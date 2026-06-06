// Command media-encoder is the OPTIONAL, separately-deployed image-encode worker
// for the WPMgr Media Optimizer (ADR-043). It runs a River worker on the bounded
// `media_encode` queue, presigned-GETs source variants from object storage,
// encodes them with the CGO lilliput encoder, presigned-PUTs the outputs, writes
// media_variant_results, and dispatches the signed `media_apply` command so the
// agent applies on disk.
//
// THIS BINARY IS THE ONLY PLACE THAT IMPORTS internal/media/encoder (CGO +
// lilliput). The main API (cmd/wpmgr) builds CGO_ENABLED=0 on distroless/static
// and NEVER imports the encoder — it only client.Inserts model.EncodeArgs (a
// pure-Go River job type). This binary builds CGO_ENABLED=1 on a glibc base
// (infra/Dockerfile.media-encoder).
package main

import (
	"context"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"runtime"
	"syscall"
	"time"

	"github.com/google/uuid"
	"github.com/riverqueue/river"
	"github.com/riverqueue/river/riverdriver/riverpgxv5"

	"github.com/mosamlife/wpmgr/apps/api/internal/agentcmd"
	"github.com/mosamlife/wpmgr/apps/api/internal/blobstore"
	"github.com/mosamlife/wpmgr/apps/api/internal/config"
	"github.com/mosamlife/wpmgr/apps/api/internal/db"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/httpclient"
	"github.com/mosamlife/wpmgr/apps/api/internal/media/encoder"
	"github.com/mosamlife/wpmgr/apps/api/internal/media/model"
	mediarepo "github.com/mosamlife/wpmgr/apps/api/internal/media/repo"
	mediaworker "github.com/mosamlife/wpmgr/apps/api/internal/media/worker"
	siteevents "github.com/mosamlife/wpmgr/apps/api/internal/site/events"
)

// defaultEncodeWorkers bounds the media_encode queue concurrency. libaom AVIF is
// CPU-bound and a single still image can't saturate many cores, so throughput
// comes from encoding several images in PARALLEL (workers) rather than more
// threads per image. The lilliput ImageOps pool is sized to match. Sized for the
// recommended multi-vCPU encoder instance; override with WPMGR_MEDIA_ENCODE_WORKERS.
const defaultEncodeWorkers = 3

func main() {
	if err := run(); err != nil {
		slog.Error("fatal", slog.Any("error", err))
		os.Exit(1)
	}
}

func run() error {
	cfg, err := config.Load(os.Getenv("WPMGR_CONFIG_FILE"))
	if err != nil {
		return err
	}
	logger := newLogger(cfg)
	slog.SetDefault(logger)

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	if !cfg.S3.Enabled() {
		return errEnv("WPMGR_S3_BUCKET is required: the media-encoder transfers bytes via presigned object storage")
	}

	// Connect with the unprivileged app DSN (RLS-enforced; the worker runs under
	// the app.agent GUC, exactly like the main API's cross-tenant jobs).
	pool, err := db.Connect(ctx, cfg.DB.DSN())
	if err != nil {
		return err
	}
	defer pool.Close()
	if err := pool.EnforceRLSRole(ctx, logger, cfg.DB.AllowRLSBypassRole); err != nil {
		return err
	}

	// Object storage (presigned URLs only — never a live GetObject).
	store, err := blobstore.New(blobstore.Config{
		Endpoint:       cfg.S3.Endpoint,
		Region:         cfg.S3.Region,
		Bucket:         cfg.S3.Bucket,
		AccessKey:      cfg.S3.AccessKey,
		SecretKey:      cfg.S3.SecretKey,
		ForcePathStyle: cfg.S3.ForcePathStyle,
	})
	if err != nil {
		return err
	}

	// CP->agent signed media_apply client. When the signing key is empty the
	// disabled client refuses (the job will retry/fail), mirroring the API.
	var applyClient mediaworker.AgentApplyClient = disabledApply{}
	if cfg.Agent.SigningPrivateKey != "" {
		signer, serr := agentcmd.NewSigner(cfg.Agent.SigningPrivateKey)
		if serr != nil {
			return serr
		}
		// Generous per-attempt timeout: media_apply makes the agent download +
		// apply ≤10 variants. Reuse the backup HTTP timeout budget.
		applyHTTP := httpclient.New(httpclient.Config{
			Timeout:    cfg.Backup.HTTPTimeout,
			MaxRetries: 0,
		})
		applyClient = agentcmd.NewClient(applyHTTP, signer)
	} else {
		logger.Warn("WPMGR_AGENT_SIGNING_PRIVATE_KEY empty: media_apply dispatch disabled (encode jobs will not finalize)")
	}

	workers := encodeWorkerCount()
	clock := domain.SystemClock{}

	// Build the CGO encoder with a pool sized to the worker concurrency.
	enc := encoder.NewLilliputEncoder(workers)
	defer enc.Close()

	repo := mediarepo.NewRepo(pool)
	eventsPub := siteevents.NewPublisher(pool, clock)
	siteLookup := mediaworker.NewDBSiteLookup(pool)

	// The agent's media_apply status callback (job-status) MUST be an absolute
	// CP URL — the agent posts it via wp_remote_post(), which rejects a relative
	// path. Same env the API uses to build its agent-facing callback URLs.
	cpBaseURL := os.Getenv("WPMGR_PUBLIC_BASE_URL")
	if cpBaseURL == "" {
		return errEnv("WPMGR_PUBLIC_BASE_URL is required: the media_apply job-status callback must be an absolute CP URL")
	}

	encodeWorker := mediaworker.NewEncodeWorker(
		enc, repo, store, eventsPub, siteLookup, applyClient, cpBaseURL, cfg.Backup.PresignTTL, logger,
	)

	riverWorkers := river.NewWorkers()
	river.AddWorker(riverWorkers, encodeWorker)

	client, err := river.NewClient(riverpgxv5.New(pool.Pool), &river.Config{
		Logger: logger,
		Queues: map[string]river.QueueConfig{
			model.MediaEncodeQueue: {MaxWorkers: workers},
		},
		Workers: riverWorkers,
	})
	if err != nil {
		return err
	}
	if err := client.Start(ctx); err != nil {
		return err
	}
	logger.Info("media-encoder started",
		slog.Int("encode_workers", workers),
		slog.String("queue", model.MediaEncodeQueue),
		slog.String("s3_bucket", cfg.S3.Bucket))

	// Cloud Run (a Service) requires the container to listen on $PORT or the
	// startup probe fails the revision. The health server also hosts the
	// /internal/drain wake endpoint: at min-instances=0 the CP holds a request
	// open there to keep this cold-started instance alive until the media_encode
	// queue drains. Self-hosters running this via docker-compose (the `media`
	// profile) run it always-on and never call /internal/drain.
	healthSrv := startHealthServer(logger, pool, model.MediaEncodeQueue)

	<-ctx.Done()
	logger.Info("shutdown signal received, draining encode queue")
	stopCtx, cancel := context.WithTimeout(context.Background(), cfg.Shutdown.Timeout)
	defer cancel()
	if herr := healthSrv.Shutdown(stopCtx); herr != nil {
		logger.Warn("health server shutdown", slog.Any("error", herr))
	}
	if err := client.Stop(stopCtx); err != nil {
		logger.Warn("river stop", slog.Any("error", err))
	}
	return nil
}

// drain hold tuning. The CP holds a POST /internal/drain request open to keep
// this scale-to-zero instance alive while it works; the handler returns once the
// media_encode queue has been continuously empty for drainQuietPeriod (so a job
// enqueued moments after the last one still keeps the instance up), or after
// drainMaxHold as a hard ceiling (kept under the Cloud Run request timeout).
const (
	drainPollInterval = 2 * time.Second
	drainQuietPeriod  = 20 * time.Second
	drainMaxHold      = 50 * time.Minute
	// maxConcurrentDrains caps simultaneous /internal/drain holds. The CP is
	// singleflighted to a single hold, so this never affects the legitimate path;
	// it is defense-in-depth that bounds the blast radius (pinned goroutines +
	// COUNT load) should the encoder ever be misconfigured allow-unauthenticated
	// or ingress=all. Excess holds get 429 instead of pinning instances.
	maxConcurrentDrains = 3
)

// drainConfig parameterizes the /internal/drain hold so it is unit-testable with
// a fake count func and short durations.
type drainConfig struct {
	poll    time.Duration
	quiet   time.Duration
	maxHold time.Duration
	count   func(ctx context.Context) (int, error)
	logger  *slog.Logger
	// sem caps concurrent holds (nil = uncapped, used by the pure-loop tests).
	sem chan struct{}
}

// startHealthServer binds a minimal HTTP server on $PORT (default 8080). It
// serves the Cloud Run startup/liveness probe (200 on every other path) and the
// /internal/drain wake endpoint. Runs in a goroutine so it never blocks the
// queue. pool/queue drive the drain handler's live-job count.
func startHealthServer(logger *slog.Logger, pool *db.Pool, queue string) *http.Server {
	port := os.Getenv("PORT")
	if port == "" {
		port = "8080"
	}
	mux := http.NewServeMux()
	mux.HandleFunc("/internal/drain", drainHandler(drainConfig{
		poll:    drainPollInterval,
		quiet:   drainQuietPeriod,
		maxHold: drainMaxHold,
		count:   func(ctx context.Context) (int, error) { return liveEncodeJobs(ctx, pool, queue) },
		logger:  logger,
		sem:     make(chan struct{}, maxConcurrentDrains),
	}))
	mux.HandleFunc("/", func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte("ok"))
	})
	srv := &http.Server{
		Addr:    ":" + port,
		Handler: mux,
		// No WriteTimeout: /internal/drain intentionally holds the response open
		// for the duration of the drain. ReadHeaderTimeout still bounds slowloris.
		ReadHeaderTimeout: 5 * time.Second,
	}
	go func() {
		if err := srv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			logger.Error("health server", slog.Any("error", err))
		}
	}()
	logger.Info("media-encoder health server listening", slog.String("port", port))
	return srv
}

// drainHandler keeps the instance alive while the media_encode queue has live
// work. It is gated by Cloud Run IAM (the service is not allow-unauthenticated)
// and internal ingress, so the container needs no additional auth — only the CP
// (granted run.invoker, presenting an ID token for this service's audience) can
// reach it. The River workers do the actual encoding in the background; this
// handler merely holds the request — and thus the Cloud Run instance — open until
// the queue is drained.
func drainHandler(cfg drainConfig) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
			return
		}
		// Defense-in-depth: cap concurrent holds (non-blocking acquire). The
		// legitimate CP caller is singleflighted to one hold, so this only bites a
		// misconfigured-ingress flood.
		if cfg.sem != nil {
			select {
			case cfg.sem <- struct{}{}:
				defer func() { <-cfg.sem }()
			default:
				http.Error(w, "too many concurrent drains", http.StatusTooManyRequests)
				return
			}
		}
		drained, reason := holdUntilDrained(r.Context(), cfg)
		// If the client (CP) already disconnected the write is a harmless no-op.
		writeDrainResult(w, drained, reason)
	}
}

// holdUntilDrained blocks until the queue has been continuously empty for
// cfg.quiet (returns drained=true), the cfg.maxHold ceiling elapses
// (drained=false, "max-hold"), or ctx is canceled because the client went away
// (drained=false, "client-gone"). A count error is treated as "not known-empty"
// so the hold continues rather than releasing the instance prematurely. Pure
// loop, extracted for unit testing.
func holdUntilDrained(ctx context.Context, cfg drainConfig) (bool, string) {
	deadline := time.Now().Add(cfg.maxHold)
	var quietSince time.Time
	cfg.logger.Info("drain hold started")
	for {
		if ctx.Err() != nil {
			cfg.logger.Info("drain hold ended: client gone")
			return false, "client-gone"
		}
		n, err := cfg.count(ctx)
		switch {
		case err != nil:
			if ctx.Err() != nil {
				return false, "client-gone"
			}
			cfg.logger.Warn("drain: live-count failed", slog.Any("error", err))
			quietSince = time.Time{} // an error is not "known empty"
		case n == 0:
			if quietSince.IsZero() {
				quietSince = time.Now()
			}
			if time.Since(quietSince) >= cfg.quiet {
				cfg.logger.Info("drain hold complete: queue drained")
				return true, "empty"
			}
		default:
			quietSince = time.Time{} // live work — reset the quiet timer
		}
		if time.Now().After(deadline) {
			cfg.logger.Info("drain hold ended: max-hold ceiling")
			return false, "max-hold"
		}
		select {
		case <-ctx.Done():
			return false, "client-gone"
		case <-time.After(cfg.poll):
		}
	}
}

// liveEncodeJobs counts media_encode jobs needing an awake encoder: available,
// running, or retryable. Mirrors the CP waker's query so both sides agree.
func liveEncodeJobs(ctx context.Context, pool *db.Pool, queue string) (int, error) {
	const q = `SELECT count(*) FROM river_job WHERE queue = $1 AND state IN ('available','running','retryable')`
	var n int
	if err := pool.QueryRow(ctx, q, queue).Scan(&n); err != nil {
		return 0, err
	}
	return n, nil
}

// writeDrainResult writes the small JSON drain summary (best-effort).
func writeDrainResult(w http.ResponseWriter, drained bool, reason string) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusOK)
	_, _ = fmt.Fprintf(w, `{"drained":%t,"reason":%q}`, drained, reason)
}

func encodeWorkerCount() int {
	if s := os.Getenv("WPMGR_MEDIA_ENCODE_WORKERS"); s != "" {
		if v := atoiClamp(s, 1, runtime.NumCPU()*2); v > 0 {
			return v
		}
	}
	return defaultEncodeWorkers
}

func atoiClamp(s string, lo, hi int) int {
	n := 0
	for _, c := range s {
		if c < '0' || c > '9' {
			return 0
		}
		n = n*10 + int(c-'0')
	}
	if n < lo {
		return lo
	}
	if n > hi {
		return hi
	}
	return n
}

// disabledApply refuses to dispatch media_apply when no signing key is set.
type disabledApply struct{}

func (disabledApply) MediaApply(_ context.Context, _ uuid.UUID, _ string, _ agentcmd.MediaApplyRequest) (agentcmd.MediaApplyResponse, error) {
	return agentcmd.MediaApplyResponse{}, errEnv("media_apply disabled: no CP signing key configured")
}

func newLogger(cfg config.Config) *slog.Logger {
	level := slog.LevelInfo
	_ = level.UnmarshalText([]byte(cfg.LogLevel))
	return slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: level}))
}

type envError string

func (e envError) Error() string { return string(e) }

func errEnv(msg string) error { return envError(msg) }
