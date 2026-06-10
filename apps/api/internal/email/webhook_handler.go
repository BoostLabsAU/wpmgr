package email

// webhook_handler.go — public HTTP handlers for provider bounce/complaint webhooks.
//
// m61 TRUST MODEL (cross-tenant forgery fix):
//
// Route: POST /webhooks/email/{provider}/{routeToken}  (PUBLIC — no operator auth)
//
// The routeToken is a per-config-row opaque 32-byte random value whose SHA-256
// hash is stored in site_email_config.webhook_route_token_hash.  It is embedded
// in the webhook URL and rotated on demand via the operator PUT config endpoint.
//
// Processing flow:
//  1. Hash the routeToken and look it up in site_email_config (constant-time
//     index scan).  Unknown token → 404 immediately (no info leak).
//  2. Load the per-row webhook signing key (decrypt age ciphertext) and verify
//     the provider signature with IT, not an instance-wide key.
//  3. For SES: assert the SNS TopicArn is in that row's ses_topic_arns allowlist.
//  4. Parse the event body.  Extract the provider-native wpmgr_tenant / wpmgr_site
//     metadata (injected by the agent at send time).
//  5. Assert: if wpmgr_tenant is present in the metadata it MUST equal the
//     routeToken's tenant_id.  Mismatch → drop the event silently (logged).
//  6. Resolve siteID from the metadata — but only within the routeToken's tenant.
//  7. Pass the verified event to svc.HandleWebhookEvent with the resolved tenant.
//
// This eliminates cross-tenant forgery: an attacker cannot produce a signature
// valid under the victim tenant's per-row key, and cannot know the victim's
// routeToken (opaque random, never returned after initial rotation).
//
// Provider-specific metadata injection (agent must set at send time):
//
//   SendGrid:  custom_args.wpmgr_site = <site_id_uuid_string>
//              custom_args.wpmgr_tenant = <tenant_id_uuid_string>
//   Mailgun:   v:wpmgr_site = <site_id_uuid_string>
//              v:wpmgr_tenant = <tenant_id_uuid_string>
//   Postmark:  Metadata.wpmgr_site = <site_id_uuid_string>
//              Metadata.wpmgr_tenant = <tenant_id_uuid_string>
//   SES:       MessageTag[*] name=wpmgr_site value=<site_id>
//              MessageTag[*] name=wpmgr_tenant value=<tenant_id>

import (
	"context"
	"crypto/sha256"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"strings"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/server/httpx"
)

// maxWebhookBodyBytes is the maximum body size the webhook handler will read.
// SNS messages are small (<16 KiB); SendGrid batches can be a few hundred KiB.
const maxWebhookBodyBytes = 2 << 20 // 2 MiB

// ---------------------------------------------------------------------------
// WebhookHandler
// ---------------------------------------------------------------------------

// WebhookHandler handles incoming provider bounce/complaint webhooks.
// It is intentionally separate from Handler (the operator handler) because
// webhook routes are PUBLIC (no session/auth middleware) and carry their own
// per-provider signature verification.
//
// m61: the handler no longer holds instance-wide signing keys.  All signing
// material is resolved per-request from the config row identified by routeToken.
type WebhookHandler struct {
	svc        *Service
	snsClient  *http.Client // used for SNS cert-fetch and subscription-confirm
	logger     *slog.Logger
	publicBase string // e.g. "https://manage.wpmgr.app" — used only for logging
}

// NewWebhookHandler builds the webhook handler.
// publicBase should be the public base URL of the instance (no trailing slash).
func NewWebhookHandler(svc *Service, publicBase string, logger *slog.Logger) *WebhookHandler {
	return &WebhookHandler{
		svc:        svc,
		snsClient:  defaultSNSHTTPClient,
		publicBase: publicBase,
		logger:     logger,
	}
}

// RegisterPublic mounts the public webhook routes on the root engine (no session/auth).
// Route pattern: POST /webhooks/email/:provider/:routeToken
func (h *WebhookHandler) RegisterPublic(r *gin.Engine) {
	wh := r.Group("/webhooks/email")
	// All four providers use the same parameterised route.
	// Postmark no longer uses :secret in the path — the routeToken IS the secret.
	wh.POST("/:provider/:routeToken", h.handleWebhook)
}

// ---------------------------------------------------------------------------
// main dispatch
// ---------------------------------------------------------------------------

// handleWebhook is the single entry point for all provider webhooks.
// It resolves the config row by routeToken BEFORE doing any provider-specific
// processing, so an unknown/invalid token is rejected at step 1 with 404.
func (h *WebhookHandler) handleWebhook(c *gin.Context) {
	provider := c.Param("provider")
	routeToken := c.Param("routeToken")

	// Step 1: resolve config row by routeToken hash (constant-time lookup).
	resolved, err := h.svc.ResolveWebhookConfig(c.Request.Context(), routeToken)
	if err != nil {
		if err == ErrNotFound {
			// 404 — no info leak about whether the token ever existed.
			c.Status(http.StatusNotFound)
			return
		}
		h.logger.Error("webhook: resolve config by token failed",
			slog.String("provider", provider),
			slog.String("err", err.Error()),
		)
		c.Status(http.StatusInternalServerError)
		return
	}

	tenantID := resolved.Config.TenantID

	// Read the body once; providers need it for signature verification and parsing.
	body, rerr := io.ReadAll(io.LimitReader(c.Request.Body, maxWebhookBodyBytes))
	if rerr != nil {
		httpx.Error(c, domain.Validation("webhook_body_read", "could not read request body"))
		return
	}

	// Step 2+: route to the per-provider handler.
	switch provider {
	case "ses":
		h.handleSES(c, body, resolved, tenantID)
	case "sendgrid":
		h.handleSendGrid(c, body, resolved, tenantID)
	case "mailgun":
		h.handleMailgun(c, body, resolved, tenantID)
	case "postmark":
		h.handlePostmark(c, body, resolved, tenantID)
	default:
		h.logger.Warn("webhook: unknown provider", slog.String("provider", provider))
		c.Status(http.StatusNotFound)
	}
}

// ---------------------------------------------------------------------------
// SES / SNS handler
// ---------------------------------------------------------------------------

func (h *WebhookHandler) handleSES(c *gin.Context, body []byte, resolved WebhookResolvedConfig, tenantID uuid.UUID) {
	// Step 2: verify SNS signature (cert-pinned RSA).
	msg, err := verifySNSMessage(body, h.snsClient)
	if err != nil {
		h.logger.Warn("ses webhook: signature verification failed",
			slog.String("tenant_id", tenantID.String()),
			slog.String("err", err.Error()),
		)
		httpx.Error(c, domain.Unauthorized("sns_sig_invalid", "SNS signature verification failed"))
		return
	}

	// Step 3: SES-specific — assert TopicArn is in this config row's allowlist.
	if !sesTopicArnAllowed(msg.TopicArn, resolved.Config.SesTopicArns) {
		h.logger.Warn("ses webhook: TopicArn not in allowlist",
			slog.String("tenant_id", tenantID.String()),
			slog.String("topic_arn", msg.TopicArn),
		)
		// Return 200 so SNS does not retry; the rejection is the security control.
		c.Status(http.StatusOK)
		return
	}

	// Handle the SNS SubscriptionConfirmation handshake.
	if msg.Type == "SubscriptionConfirmation" {
		if cerr := snsConfirmSubscription(msg.SubscribeURL, h.snsClient); cerr != nil {
			h.logger.Warn("ses webhook: subscription confirmation failed",
				slog.String("tenant_id", tenantID.String()),
				slog.String("err", cerr.Error()),
			)
			httpx.Error(c, domain.Internal("sns_confirm_failed", "could not confirm SNS subscription"))
			return
		}
		c.Status(http.StatusOK)
		return
	}

	if msg.Type != "Notification" {
		c.Status(http.StatusOK) // UnsubscribeConfirmation etc. — ack and drop.
		return
	}

	// Parse the SES notification embedded in the SNS Message field.
	var sesNote sesNotification
	if err := json.Unmarshal([]byte(msg.Message), &sesNote); err != nil {
		h.logger.Warn("ses webhook: unmarshal SES notification",
			slog.String("tenant_id", tenantID.String()),
			slog.String("err", err.Error()),
		)
		c.Status(http.StatusOK) // Ack so SNS does not retry non-parseable events.
		return
	}

	events := parseSESEvents(sesNote)
	for _, ev := range events {
		ev.ProviderEventID = msg.MessageID + "_" + ev.Email
		// Step 5: enforce tenant assertion (intra-tenant only).
		ev = h.assertAndScopeEvent(ev, tenantID)
		ev.EmailHash = emailHash(ev.Email)
		ctx, cancel := handleWebhookEventContext(c.Request.Context())
		if err := h.svc.HandleWebhookEvent(ctx, ev); err != nil {
			h.logger.Error("ses webhook: handle event",
				slog.String("tenant_id", tenantID.String()),
				slog.String("err", err.Error()),
			)
		}
		cancel()
	}
	c.Status(http.StatusOK)
}

// sesTopicArnAllowed returns true when topicArn is in the allowlist.
// An empty / nil allowlist means SES was not configured for this row → reject.
func sesTopicArnAllowed(topicArn string, allowlist []string) bool {
	if len(allowlist) == 0 {
		return false
	}
	for _, a := range allowlist {
		if a == topicArn {
			return true
		}
	}
	return false
}

// ---------------------------------------------------------------------------
// SendGrid handler
// ---------------------------------------------------------------------------

func (h *WebhookHandler) handleSendGrid(c *gin.Context, body []byte, resolved WebhookResolvedConfig, tenantID uuid.UUID) {
	if resolved.SigningKeyPlain == "" {
		h.logger.Warn("sendgrid webhook: no signing key configured",
			slog.String("tenant_id", tenantID.String()),
		)
		httpx.Error(c, domain.ServiceUnavailable("sendgrid_webhook_unconfigured", "SendGrid webhook signing key not configured for this config row"))
		return
	}

	sig := c.GetHeader("X-Twilio-Email-Event-Webhook-Signature")
	ts := c.GetHeader("X-Twilio-Email-Event-Webhook-Timestamp")
	// Step 2: verify ECDSA signature with the per-row key.
	if err := verifySendGridSignature(body, sig, ts, resolved.SigningKeyPlain); err != nil {
		h.logger.Warn("sendgrid webhook: signature verification failed",
			slog.String("tenant_id", tenantID.String()),
			slog.String("err", err.Error()),
		)
		httpx.Error(c, domain.Unauthorized("sendgrid_sig_invalid", "SendGrid ECDSA signature verification failed"))
		return
	}

	// SendGrid sends a JSON ARRAY of events.
	var rawEvents []json.RawMessage
	if err := json.Unmarshal(body, &rawEvents); err != nil {
		c.Status(http.StatusOK) // Ack and drop malformed batches.
		return
	}

	for _, raw := range rawEvents {
		var ev sendGridEvent
		if err := json.Unmarshal(raw, &ev); err != nil {
			continue
		}
		if !isSendGridSuppressEvent(ev.Event) {
			continue
		}
		in := WebhookEventInput{
			Provider:        "sendgrid",
			ProviderEventID: ev.SgEventID,
			Email:           ev.Email,
			EventType:       sendGridEventType(ev.Event),
			TenantID:        &tenantID, // from routeToken resolution
		}
		// Extract metadata tenant/site from custom_args for step 5 assertion.
		if ev.CustomArgs != nil {
			if tid, ok := ev.CustomArgs["wpmgr_tenant"]; ok {
				if id, err := uuid.Parse(tid); err == nil {
					id := id
					in.MetaTenantID = &id
				}
			}
			if sid, ok := ev.CustomArgs["wpmgr_site"]; ok {
				if id, err := uuid.Parse(sid); err == nil {
					id := id
					in.SiteID = &id
				}
			}
		}
		// Step 5: assert metadata tenant == routeToken tenant; scope site.
		in = h.assertAndScopeEvent(in, tenantID)
		in.EmailHash = emailHash(in.Email)
		ctx, cancel := handleWebhookEventContext(c.Request.Context())
		if err := h.svc.HandleWebhookEvent(ctx, in); err != nil {
			h.logger.Error("sendgrid webhook: handle event",
				slog.String("tenant_id", tenantID.String()),
				slog.String("err", err.Error()),
			)
		}
		cancel()
	}
	c.Status(http.StatusOK)
}

// ---------------------------------------------------------------------------
// Mailgun handler
// ---------------------------------------------------------------------------

func (h *WebhookHandler) handleMailgun(c *gin.Context, body []byte, resolved WebhookResolvedConfig, tenantID uuid.UUID) {
	if resolved.SigningKeyPlain == "" {
		h.logger.Warn("mailgun webhook: no signing key configured",
			slog.String("tenant_id", tenantID.String()),
		)
		httpx.Error(c, domain.ServiceUnavailable("mailgun_webhook_unconfigured", "Mailgun webhook signing key not configured for this config row"))
		return
	}

	var mgBody mailgunWebhookBody
	if err := json.Unmarshal(body, &mgBody); err != nil {
		c.Status(http.StatusOK)
		return
	}

	sig := mgBody.Signature
	// Step 2: verify HMAC-SHA256 with the per-row signing key.
	if err := verifyMailgunSignature(sig.Timestamp, sig.Token, sig.Signature, resolved.SigningKeyPlain); err != nil {
		h.logger.Warn("mailgun webhook: signature verification failed",
			slog.String("tenant_id", tenantID.String()),
			slog.String("err", err.Error()),
		)
		httpx.Error(c, domain.Unauthorized("mailgun_sig_invalid", "Mailgun HMAC signature verification failed"))
		return
	}

	if !isMailgunSuppressEvent(mgBody.EventData.Event) {
		c.Status(http.StatusOK)
		return
	}

	in := WebhookEventInput{
		Provider:        "mailgun",
		ProviderEventID: mgBody.EventData.ID,
		Email:           mgBody.EventData.Recipient,
		EventType:       mailgunEventType(mgBody.EventData.Event),
		TenantID:        &tenantID,
	}
	if mgBody.EventData.UserVariables != nil {
		if tid, ok := mgBody.EventData.UserVariables["wpmgr_tenant"]; ok {
			if id, err := uuid.Parse(tid); err == nil {
				id := id
				in.MetaTenantID = &id
			}
		}
		if sid, ok := mgBody.EventData.UserVariables["wpmgr_site"]; ok {
			if id, err := uuid.Parse(sid); err == nil {
				id := id
				in.SiteID = &id
			}
		}
	}
	in = h.assertAndScopeEvent(in, tenantID)
	in.EmailHash = emailHash(in.Email)
	ctx, cancel := handleWebhookEventContext(c.Request.Context())
	if err := h.svc.HandleWebhookEvent(ctx, in); err != nil {
		h.logger.Error("mailgun webhook: handle event",
			slog.String("tenant_id", tenantID.String()),
			slog.String("err", err.Error()),
		)
	}
	cancel()
	c.Status(http.StatusOK)
}

// ---------------------------------------------------------------------------
// Postmark handler
// ---------------------------------------------------------------------------

func (h *WebhookHandler) handlePostmark(c *gin.Context, body []byte, resolved WebhookResolvedConfig, tenantID uuid.UUID) {
	// For Postmark, the routeToken itself is the credential (embedded in the URL
	// path).  Verification was done at the route-token resolution step (step 1).
	// An optional per-row secret stored in webhook_signing_key_enc provides a
	// second factor: when set, require it to match the X-Postmark-Secret header
	// (which Postmark can be configured to send). When not set, the routeToken
	// alone is the credential (single-factor but still per-tenant opaque random).
	if resolved.SigningKeyPlain != "" {
		provided := c.GetHeader("X-Postmark-Secret")
		if err := verifyPostmarkSecret(provided, resolved.SigningKeyPlain); err != nil {
			h.logger.Warn("postmark webhook: secret header mismatch",
				slog.String("tenant_id", tenantID.String()),
			)
			httpx.Error(c, domain.Unauthorized("postmark_secret_invalid", "Postmark webhook secret mismatch"))
			return
		}
	}

	// Detect by RecordType field.
	var base postmarkBase
	if err := json.Unmarshal(body, &base); err != nil {
		c.Status(http.StatusOK)
		return
	}

	var in WebhookEventInput
	in.Provider = "postmark"
	in.TenantID = &tenantID

	switch base.RecordType {
	case "Bounce":
		var b postmarkBounce
		if err := json.Unmarshal(body, &b); err != nil || b.Type != "HardBounce" {
			c.Status(http.StatusOK)
			return
		}
		in.ProviderEventID = postmarkEventID(b.ID)
		in.Email = b.Email
		in.EventType = "hard_bounce"
		in.MetaTenantID = parseUUIDPtr(b.Metadata["wpmgr_tenant"])
		in.SiteID = parseUUIDPtr(b.Metadata["wpmgr_site"])

	case "SpamComplaint":
		var sc postmarkSpamComplaint
		if err := json.Unmarshal(body, &sc); err != nil {
			c.Status(http.StatusOK)
			return
		}
		in.ProviderEventID = postmarkEventID(sc.ID)
		in.Email = sc.Email
		in.EventType = "complaint"
		in.MetaTenantID = parseUUIDPtr(sc.Metadata["wpmgr_tenant"])
		in.SiteID = parseUUIDPtr(sc.Metadata["wpmgr_site"])

	default:
		c.Status(http.StatusOK)
		return
	}

	in = h.assertAndScopeEvent(in, tenantID)
	in.EmailHash = emailHash(in.Email)
	ctx, cancel := handleWebhookEventContext(c.Request.Context())
	if err := h.svc.HandleWebhookEvent(ctx, in); err != nil {
		h.logger.Error("postmark webhook: handle event",
			slog.String("tenant_id", tenantID.String()),
			slog.String("err", err.Error()),
		)
	}
	cancel()
	c.Status(http.StatusOK)
}

// ---------------------------------------------------------------------------
// security helpers
// ---------------------------------------------------------------------------

// assertAndScopeEvent enforces the intra-tenant metadata assertion (step 5).
//
// Rule: if the event metadata carries a wpmgr_tenant (MetaTenantID) and it
// does NOT match the routeToken's tenantID, the event is silently dropped by
// clearing TenantID.  After this call ev.TenantID is always == &tenantID when
// the event should be processed; it is nil when it should be dropped.
//
// A forged wpmgr_site can at most affect another site WITHIN THE SAME TENANT
// (intra-tenant), which is acceptable (SHOULD-FIX #3 in MarkEmailLogBounced
// further narrows the blast radius to the correct site).
func (h *WebhookHandler) assertAndScopeEvent(ev WebhookEventInput, tenantID uuid.UUID) WebhookEventInput {
	if ev.MetaTenantID != nil && *ev.MetaTenantID != tenantID {
		h.logger.Warn("webhook: metadata tenant != routeToken tenant; dropping event",
			slog.String("route_tenant", tenantID.String()),
			slog.String("meta_tenant", ev.MetaTenantID.String()),
			slog.String("provider", ev.Provider),
			slog.String("event_id", ev.ProviderEventID),
		)
		// Drop: clear TenantID so HandleWebhookEvent treats it as orphaned and
		// skips the suppression write (it only suppresses when TenantID != nil).
		ev.TenantID = nil
		ev.SiteID = nil
		return ev
	}
	// Ensure TenantID is the routeToken's tenant (it is already, but be explicit).
	t := tenantID
	ev.TenantID = &t
	return ev
}

// emailHash returns the SHA-256 of the lower-cased, trimmed email address.
// Mirrors suppressionHash in repo.go but is kept local so the webhook handler
// does not need to import repo internals.
func emailHash(email string) []byte {
	norm := strings.ToLower(strings.TrimSpace(email))
	sum := sha256.Sum256([]byte(norm))
	return sum[:]
}

// ---------------------------------------------------------------------------
// Provider event-body types (shared between old and new paths)
// ---------------------------------------------------------------------------

// sesNotification is a minimal parse of the SES notification JSON.
type sesNotification struct {
	NotificationType string `json:"notificationType"` // Bounce | Complaint
	Bounce           *struct {
		BounceType        string `json:"bounceType"` // Permanent | Transient
		BouncedRecipients []struct {
			EmailAddress string `json:"emailAddress"`
		} `json:"bouncedRecipients"`
	} `json:"bounce,omitempty"`
	Complaint *struct {
		ComplainedRecipients []struct {
			EmailAddress string `json:"emailAddress"`
		} `json:"complainedRecipients"`
	} `json:"complaint,omitempty"`
	Mail struct {
		MessageID string `json:"messageId"`
		Tags      []struct {
			Name  string `json:"name"`
			Value string `json:"value"`
		} `json:"tags"`
	} `json:"mail"`
}

func parseSESEvents(note sesNotification) []WebhookEventInput {
	var events []WebhookEventInput

	// Resolve metadata tenant/site from SES message tags (injected by the agent).
	metaTenantID, siteID := resolveTenantSiteFromSESTags(note.Mail.Tags)
	messageID := note.Mail.MessageID

	switch note.NotificationType {
	case "Bounce":
		if note.Bounce == nil {
			return nil
		}
		// Only hard (Permanent) bounces → suppress.
		if !strings.EqualFold(note.Bounce.BounceType, "Permanent") {
			return nil
		}
		for _, r := range note.Bounce.BouncedRecipients {
			events = append(events, WebhookEventInput{
				Provider:        "ses",
				Email:           r.EmailAddress,
				EventType:       "hard_bounce",
				MetaTenantID:    metaTenantID,
				SiteID:          siteID,
				ProviderEventID: messageID + "_bounce_" + r.EmailAddress,
			})
		}
	case "Complaint":
		if note.Complaint == nil {
			return nil
		}
		for _, r := range note.Complaint.ComplainedRecipients {
			events = append(events, WebhookEventInput{
				Provider:        "ses",
				Email:           r.EmailAddress,
				EventType:       "complaint",
				MetaTenantID:    metaTenantID,
				SiteID:          siteID,
				ProviderEventID: messageID + "_complaint_" + r.EmailAddress,
			})
		}
	}
	return events
}

func resolveTenantSiteFromSESTags(tags []struct {
	Name  string `json:"name"`
	Value string `json:"value"`
}) (*uuid.UUID, *uuid.UUID) {
	var tenantID, siteID *uuid.UUID
	for _, t := range tags {
		switch t.Name {
		case "wpmgr_tenant":
			if id, err := uuid.Parse(t.Value); err == nil {
				id := id
				tenantID = &id
			}
		case "wpmgr_site":
			if id, err := uuid.Parse(t.Value); err == nil {
				id := id
				siteID = &id
			}
		}
	}
	return tenantID, siteID
}

type sendGridEvent struct {
	Email      string            `json:"email"`
	Timestamp  int64             `json:"timestamp"`
	Event      string            `json:"event"` // bounce | spamreport | unsubscribe
	SgEventID  string            `json:"sg_event_id"`
	MessageID  string            `json:"sg_message_id,omitempty"`
	CustomArgs map[string]string `json:"custom_args,omitempty"`
	// bounce-specific
	Type string `json:"type,omitempty"` // bounce | blocked (for event=bounce)
}

func isSendGridSuppressEvent(event string) bool {
	switch event {
	case "bounce", "spamreport", "unsubscribe":
		return true
	}
	return false
}

func sendGridEventType(event string) string {
	switch event {
	case "bounce":
		return "hard_bounce"
	case "spamreport":
		return "complaint"
	case "unsubscribe":
		return "unsubscribe"
	}
	return event
}

type mailgunWebhookBody struct {
	Signature struct {
		Timestamp string `json:"timestamp"`
		Token     string `json:"token"`
		Signature string `json:"signature"`
	} `json:"signature"`
	EventData struct {
		Event         string            `json:"event"` // failed | complained | unsubscribed
		ID            string            `json:"id"`
		Recipient     string            `json:"recipient"`
		UserVariables map[string]string `json:"user-variables,omitempty"`
		Severity      string            `json:"severity,omitempty"` // permanent | temporary (for failed)
	} `json:"event-data"`
}

func isMailgunSuppressEvent(event string) bool {
	switch event {
	case "failed", "complained", "unsubscribed":
		return true
	}
	return false
}

func mailgunEventType(event string) string {
	switch event {
	case "failed":
		return "hard_bounce"
	case "complained":
		return "complaint"
	case "unsubscribed":
		return "unsubscribe"
	}
	return event
}

type postmarkBase struct {
	RecordType string `json:"RecordType"`
}

type postmarkBounce struct {
	RecordType string            `json:"RecordType"`
	ID         int64             `json:"ID"`
	Type       string            `json:"Type"` // HardBounce | SoftBounce | etc.
	Email      string            `json:"Email"`
	MessageID  string            `json:"MessageID"`
	Metadata   map[string]string `json:"Metadata,omitempty"`
}

type postmarkSpamComplaint struct {
	RecordType string            `json:"RecordType"`
	ID         int64             `json:"ID"`
	Email      string            `json:"Email"`
	MessageID  string            `json:"MessageID"`
	Metadata   map[string]string `json:"Metadata,omitempty"`
}

// postmarkEventID converts Postmark's integer record ID to a stable string.
func postmarkEventID(id int64) string {
	return "pm_" + itoa(id)
}

func itoa(n int64) string {
	if n == 0 {
		return "0"
	}
	var buf [20]byte
	pos := len(buf)
	neg := n < 0
	if neg {
		n = -n
	}
	for n > 0 {
		pos--
		buf[pos] = byte('0' + n%10)
		n /= 10
	}
	if neg {
		pos--
		buf[pos] = '-'
	}
	return string(buf[pos:])
}

// ---------------------------------------------------------------------------
// shared helpers
// ---------------------------------------------------------------------------

func parseUUIDPtr(s string) *uuid.UUID {
	if s == "" {
		return nil
	}
	id, err := uuid.Parse(s)
	if err != nil {
		return nil
	}
	return &id
}

// handleWebhookEventContext wraps a cancellable background context for async
// event handling that must not be tied to the HTTP request lifecycle.
func handleWebhookEventContext(parent context.Context) (context.Context, context.CancelFunc) {
	return context.WithTimeout(context.WithoutCancel(parent), 10*time.Second)
}
