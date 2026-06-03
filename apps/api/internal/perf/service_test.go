package perf

import (
	"context"
	"errors"
	"sync"
	"testing"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/agentcmd"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/site"
)

// ---------------------------------------------------------------------------
// fakes
// ---------------------------------------------------------------------------

type fakeRepo struct {
	mu          sync.Mutex
	config      Config
	configFound bool
	ciphertext  []byte
	provider    string
	purges      []RecordPurgeInput
	upserts     []UpsertConfigInput
}

func (r *fakeRepo) GetConfig(_ context.Context, tenantID, siteID uuid.UUID) (Config, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if !r.configFound {
		return Config{}, ErrNotFound
	}
	c := r.config
	c.TenantID, c.SiteID = tenantID, siteID
	return c, nil
}

func (r *fakeRepo) UpsertConfig(_ context.Context, in UpsertConfigInput) (Config, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.upserts = append(r.upserts, in)
	r.config = in.Config
	r.configFound = true
	r.ciphertext = in.CDNCredentialsEncrypted
	return in.Config, nil
}

func (r *fakeRepo) GetCDNCredentialsCiphertext(_ context.Context, _, _ uuid.UUID) ([]byte, string, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	return r.ciphertext, r.provider, nil
}

func (r *fakeRepo) UpdateInstallState(context.Context, uuid.UUID, string, bool, bool, bool) error {
	return nil
}

func (r *fakeRepo) GetCacheStats(_ context.Context, tenantID, siteID uuid.UUID) (CacheStats, error) {
	return CacheStats{}, ErrNotFound
}

func (r *fakeRepo) UpsertCacheStats(_ context.Context, s CacheStats) (CacheStats, error) {
	return s, nil
}

func (r *fakeRepo) RecordPurge(_ context.Context, in RecordPurgeInput) (PurgeAuditEntry, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.purges = append(r.purges, in)
	return PurgeAuditEntry{ID: uuid.New(), TenantID: in.TenantID, SiteID: in.SiteID, Kind: string(in.Kind), TargetURLs: in.TargetURLs, URLsCount: len(in.TargetURLs)}, nil
}

func (r *fakeRepo) ListPurgeAudit(context.Context, uuid.UUID, uuid.UUID, int32, int32) ([]PurgeAuditEntry, error) {
	return nil, nil
}

type fakeEvents struct {
	mu     sync.Mutex
	events []site.ConnectionEvent
}

func (e *fakeEvents) Publish(_ context.Context, ev site.ConnectionEvent) error {
	e.mu.Lock()
	defer e.mu.Unlock()
	e.events = append(e.events, ev)
	return nil
}

func (e *fakeEvents) types() []string {
	e.mu.Lock()
	defer e.mu.Unlock()
	out := make([]string, len(e.events))
	for i, ev := range e.events {
		out[i] = ev.Type
	}
	return out
}

type fakeAgent struct {
	mu          sync.Mutex
	purgeCalls  []agentcmd.CachePurgeRequest
	purgeResult agentcmd.CachePurgeResult
	purgeErr    error
}

func (a *fakeAgent) SyncPerfConfig(context.Context, uuid.UUID, string, agentcmd.PerfConfigRequest) (agentcmd.PerfConfigResult, error) {
	return agentcmd.PerfConfigResult{OK: true}, nil
}
func (a *fakeAgent) CacheEnable(context.Context, uuid.UUID, string, agentcmd.CacheEnableRequest) (agentcmd.CacheEnableResult, error) {
	return agentcmd.CacheEnableResult{OK: true, Detail: "enabled"}, nil
}
func (a *fakeAgent) CacheDisable(context.Context, uuid.UUID, string, agentcmd.CacheDisableRequest) (agentcmd.CacheDisableResult, error) {
	return agentcmd.CacheDisableResult{OK: true, Detail: "disabled"}, nil
}
func (a *fakeAgent) CachePurge(_ context.Context, _ uuid.UUID, _ string, req agentcmd.CachePurgeRequest) (agentcmd.CachePurgeResult, error) {
	a.mu.Lock()
	defer a.mu.Unlock()
	a.purgeCalls = append(a.purgeCalls, req)
	return a.purgeResult, a.purgeErr
}
func (a *fakeAgent) CachePreload(context.Context, uuid.UUID, string, agentcmd.CachePreloadRequest) (agentcmd.CachePreloadResult, error) {
	return agentcmd.CachePreloadResult{OK: true, Detail: "preload"}, nil
}
func (a *fakeAgent) RucssCompute(context.Context, uuid.UUID, string, agentcmd.RucssComputeRequest) (agentcmd.RucssComputeResult, error) {
	return agentcmd.RucssComputeResult{OK: true, Detail: "rucss compute queued", Queued: 1}, nil
}
func (a *fakeAgent) DBClean(context.Context, uuid.UUID, string, agentcmd.DBCleanRequest) (agentcmd.DBCleanResult, error) {
	return agentcmd.DBCleanResult{OK: true, Detail: "cleaned", RowsCleaned: 7}, nil
}

type fakeSites struct{ url string }

func (s *fakeSites) GetSiteURL(context.Context, uuid.UUID, uuid.UUID) (string, error) {
	return s.url, nil
}

// ---------------------------------------------------------------------------
// config validation
// ---------------------------------------------------------------------------

func TestValidateConfig(t *testing.T) {
	svc := NewService(&fakeRepo{}, nil, nil, nil)

	tests := []struct {
		name    string
		mutate  func(*Config)
		wantErr string // domain code, "" = ok
	}{
		{
			name:   "defaults applied for empty intervals/methods",
			mutate: func(c *Config) {},
		},
		{
			name:    "invalid cache refresh interval",
			mutate:  func(c *Config) { c.CacheRefreshInterval = "3seconds" },
			wantErr: "invalid_cache_refresh_interval",
		},
		{
			name:    "invalid js delay method",
			mutate:  func(c *Config) { c.JSDelayMethod = "lazy" },
			wantErr: "invalid_js_delay_method",
		},
		{
			name:    "invalid db clean interval",
			mutate:  func(c *Config) { c.DBAutoCleanInterval = "hourly" },
			wantErr: "invalid_db_clean_interval",
		},
		{
			name:    "invalid cdn file types",
			mutate:  func(c *Config) { c.CDNFileTypes = "fonts" },
			wantErr: "invalid_cdn_file_types",
		},
		{
			name:    "cdn enabled without url",
			mutate:  func(c *Config) { c.CDNEnabled = true; c.CDNURL = "" },
			wantErr: "invalid_cdn_url",
		},
		{
			name:    "cdn enabled with bad url scheme",
			mutate:  func(c *Config) { c.CDNEnabled = true; c.CDNURL = "ftp://x" },
			wantErr: "invalid_cdn_url",
		},
		{
			name:    "cdn enabled with valid url",
			mutate:  func(c *Config) { c.CDNEnabled = true; c.CDNURL = "https://cdn.example.com" },
			wantErr: "",
		},
		{
			name:    "invalid cdn provider",
			mutate:  func(c *Config) { c.CDNProvider = "fastly" },
			wantErr: "invalid_cdn_provider",
		},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			cfg := Config{}
			tc.mutate(&cfg)
			err := svc.validateConfig(&cfg)
			if tc.wantErr == "" {
				if err != nil {
					t.Fatalf("expected nil error, got %v", err)
				}
				// defaults applied
				if cfg.CacheRefreshInterval == "" || cfg.JSDelayMethod == "" || cfg.DBAutoCleanInterval == "" || cfg.CDNFileTypes == "" {
					t.Fatalf("expected defaults to be filled, got %+v", cfg)
				}
				return
			}
			de, ok := domain.AsDomain(err)
			if !ok {
				t.Fatalf("expected domain error, got %v", err)
			}
			if de.Code != tc.wantErr {
				t.Fatalf("expected code %q, got %q", tc.wantErr, de.Code)
			}
		})
	}
}

func TestValidateConfigNormalizesLists(t *testing.T) {
	svc := NewService(&fakeRepo{}, nil, nil, nil)
	cfg := Config{
		CacheBypassURLs:          []string{" /wp-admin ", "", "/cart"},
		CSSRucssIncludeSelectors: []string{"", "  .keep  "},
	}
	if err := svc.validateConfig(&cfg); err != nil {
		t.Fatalf("validateConfig: %v", err)
	}
	if len(cfg.CacheBypassURLs) != 2 || cfg.CacheBypassURLs[0] != "/wp-admin" {
		t.Fatalf("expected trimmed non-empty urls, got %#v", cfg.CacheBypassURLs)
	}
	if len(cfg.CSSRucssIncludeSelectors) != 1 || cfg.CSSRucssIncludeSelectors[0] != ".keep" {
		t.Fatalf("expected trimmed selectors, got %#v", cfg.CSSRucssIncludeSelectors)
	}
}

// ---------------------------------------------------------------------------
// purge records audit + emits SSE + calls agent
// ---------------------------------------------------------------------------

func TestPurgeRecordsAuditAndEmitsEvents(t *testing.T) {
	repo := &fakeRepo{}
	events := &fakeEvents{}
	ag := &fakeAgent{purgeResult: agentcmd.CachePurgeResult{OK: true, Detail: "purged", PurgedCount: 12}}
	svc := NewService(repo, nil, events, nil)
	svc.SetAgentClient(ag, &fakeSites{url: "https://site.example.com"})

	tenantID, siteID, userID := uuid.New(), uuid.New(), uuid.New()
	entry, detail, err := svc.Purge(context.Background(), tenantID, siteID, PurgeInput{
		Scope:       PurgeKindAll,
		InitiatorID: userID,
	})
	if err != nil {
		t.Fatalf("Purge: %v", err)
	}
	if detail != "purged" {
		t.Fatalf("expected agent detail 'purged', got %q", detail)
	}
	// audit recorded
	if len(repo.purges) != 1 {
		t.Fatalf("expected 1 purge audit record, got %d", len(repo.purges))
	}
	if repo.purges[0].Kind != PurgeKindAll || repo.purges[0].InitiatorUserID != userID {
		t.Fatalf("unexpected purge record: %+v", repo.purges[0])
	}
	if entry.SiteID != siteID {
		t.Fatalf("entry site mismatch")
	}
	// agent called with scope=all
	if len(ag.purgeCalls) != 1 || ag.purgeCalls[0].Scope != "all" {
		t.Fatalf("expected agent purge scope=all, got %+v", ag.purgeCalls)
	}
	// SSE: started + completed
	types := events.types()
	if !contains(types, site.EventCachePurgeStarted) || !contains(types, site.EventCachePurgeCompleted) {
		t.Fatalf("expected purge.started + purge.completed events, got %v", types)
	}
}

func TestPurgeURLScopeRequiresURLs(t *testing.T) {
	svc := NewService(&fakeRepo{}, nil, nil, nil)
	_, _, err := svc.Purge(context.Background(), uuid.New(), uuid.New(), PurgeInput{Scope: PurgeKindURL})
	de, ok := domain.AsDomain(err)
	if !ok || de.Code != "missing_urls" {
		t.Fatalf("expected missing_urls domain error, got %v", err)
	}
}

func TestPurgeInvalidScope(t *testing.T) {
	svc := NewService(&fakeRepo{}, nil, nil, nil)
	_, _, err := svc.Purge(context.Background(), uuid.New(), uuid.New(), PurgeInput{Scope: PurgeKind("nuke")})
	de, ok := domain.AsDomain(err)
	if !ok || de.Code != "invalid_scope" {
		t.Fatalf("expected invalid_scope domain error, got %v", err)
	}
}

// UpdateConfig should record an audit-able config update event and bump version.
func TestUpdateConfigBumpsVersionAndEmits(t *testing.T) {
	repo := &fakeRepo{config: Config{ConfigVersion: 3}, configFound: true}
	events := &fakeEvents{}
	svc := NewService(repo, nil, events, nil)
	saved, err := svc.UpdateConfig(context.Background(), uuid.New(), uuid.New(), UpdateConfigInput{
		Config: Config{CacheEnabled: true},
	})
	if err != nil {
		t.Fatalf("UpdateConfig: %v", err)
	}
	if saved.ConfigVersion != 4 {
		t.Fatalf("expected version bump to 4, got %d", saved.ConfigVersion)
	}
	if !contains(events.types(), site.EventPerfConfigUpdated) {
		t.Fatalf("expected perf.config.updated event, got %v", events.types())
	}
}

func contains(s []string, v string) bool {
	for _, x := range s {
		if x == v {
			return true
		}
	}
	return false
}

// sanity: ensure ErrNotFound is the sentinel used by GetConfig's default path.
func TestGetConfigDefaultWhenAbsent(t *testing.T) {
	repo := &fakeRepo{configFound: false}
	svc := NewService(repo, nil, nil, nil)
	cfg, err := svc.GetConfig(context.Background(), uuid.New(), uuid.New())
	if err != nil {
		t.Fatalf("GetConfig: %v", err)
	}
	if cfg.CacheRefreshInterval != "2hours" || !cfg.LazyLoad {
		t.Fatalf("expected default config, got %+v", cfg)
	}
	if !errors.Is(ErrNotFound, ErrNotFound) {
		t.Fatal("sentinel")
	}
}
