package security

import (
	"context"
	"encoding/json"
	"fmt"
	"net"
	"strconv"
	"strings"
	"time"
	"unicode"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/agentcmd"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/ipprovider"
)

// AgentHardeningClient is the subset of agentcmd.Client the hardening service
// needs to push the hardening config + ban list to the agent. *agentcmd.Client
// satisfies it via its SyncSecurityHardening method. Declared as an interface
// so tests can substitute a fake without spinning up the SSRF transport.
type AgentHardeningClient interface {
	SyncSecurityHardening(ctx context.Context, siteID uuid.UUID, siteURL string, req agentcmd.HardeningRequest) (agentcmd.HardeningResult, error)
}

// AgentSecurityClient is the subset of agentcmd.Client the service needs to
// push security config and issue IP unblocks. *agentcmd.Client satisfies it
// via its SyncSecurityConfig and UnblockIP methods. Declared as an interface
// so tests can substitute a fake without spinning up the SSRF transport.
type AgentSecurityClient interface {
	SyncSecurityConfig(ctx context.Context, siteID uuid.UUID, siteURL string, req agentcmd.SecurityConfigRequest) (agentcmd.SecurityConfigResult, error)
	UnblockIP(ctx context.Context, siteID uuid.UUID, siteURL string, req agentcmd.UnblockIPRequest) (agentcmd.UnblockIPResult, error)
}

// SiteLookup resolves a site's agent URL from the site service (wired in main
// via a narrow adapter, keeping this package free of the site import).
type SiteLookup interface {
	GetSiteURL(ctx context.Context, tenantID, siteID uuid.UUID) (string, error)
}

// Service orchestrates the security domain: repo + optional agentcmd clients.
type Service struct {
	repo               *Repo
	agentClient        AgentSecurityClient
	hardeningClient    AgentHardeningClient
	siteLookup         SiteLookup
}

// NewService builds a Service.
func NewService(repo *Repo) *Service {
	return &Service{repo: repo}
}

// SetAgentClient wires the agentcmd client for pushing login-protection config
// and unblocking IPs. The SiteLookup is required alongside it so the service
// can resolve the site URL without a hard dependency on the site package.
func (s *Service) SetAgentClient(client AgentSecurityClient, sites SiteLookup) {
	s.agentClient = client
	s.siteLookup = sites
}

// SetHardeningClient wires the agentcmd client for pushing the hardening config
// + ban list. The SiteLookup is shared with SetAgentClient; call both or
// supply the same adapter via both set methods. If SiteLookup is already set
// (by SetAgentClient), it is not overwritten here.
func (s *Service) SetHardeningClient(client AgentHardeningClient, sites SiteLookup) {
	s.hardeningClient = client
	if s.siteLookup == nil {
		s.siteLookup = sites
	}
}

// validModes are the three allowed protection modes.
var validModes = map[string]bool{
	"disabled": true,
	"audit":    true,
	"protect":  true,
}

// GetConfig returns the stored config, or the default when no row exists yet.
func (s *Service) GetConfig(ctx context.Context, tenantID, siteID uuid.UUID) (SecurityConfig, error) {
	cfg, found, err := s.repo.GetConfig(ctx, tenantID, siteID)
	if err != nil {
		return SecurityConfig{}, err
	}
	if !found {
		return defaultConfig(tenantID, siteID), nil
	}
	return cfg, nil
}

// SaveConfig validates the incoming config, applies the safety rail for
// protect+empty-allowlist, upserts it in the database, and pushes it to the
// agent. Returns the stored (potentially modified) config.
//
// Safety rail: when mode=="protect" and allow_cidrs is empty after the
// operator's edit, we auto-add the requesting operator's client IP as a /32
// (IPv4) or /128 (IPv6) so enabling protection cannot lock the operator out.
// operatorIP is derived by the handler from X-Forwarded-For / RemoteAddr.
func (s *Service) SaveConfig(ctx context.Context, tenantID, siteID uuid.UUID, cfg SecurityConfig, operatorIP string) (SecurityConfig, error) {
	// --- mode validation ---
	if !validModes[cfg.Mode] {
		return SecurityConfig{}, domain.Validation("invalid_mode",
			fmt.Sprintf("mode must be one of: disabled, audit, protect; got %q", cfg.Mode))
	}

	// --- CIDR validation ---
	cfg.AllowCIDRs = normalizeCIDRs(cfg.AllowCIDRs)
	cfg.DenyCIDRs = normalizeCIDRs(cfg.DenyCIDRs)
	for _, cidr := range append(cfg.AllowCIDRs, cfg.DenyCIDRs...) {
		if _, _, err := net.ParseCIDR(cidr); err != nil {
			return SecurityConfig{}, domain.Validation("invalid_cidr",
				fmt.Sprintf("CIDR %q is not a valid IPv4 or IPv6 network", cidr))
		}
	}

	// --- threshold clamping (sane positive ranges) ---
	cfg.Thresholds = clampThresholds(cfg.Thresholds)

	// --- IP header default ---
	if cfg.IPHeader == "" {
		cfg.IPHeader = "REMOTE_ADDR"
	}

	// --- safety rail: protect + empty allowlist ---
	// When the operator enables protect mode with no allowlist, auto-add their
	// own IP as a /32 or /128 so they cannot lock themselves out immediately.
	if cfg.Mode == "protect" && len(cfg.AllowCIDRs) == 0 && operatorIP != "" {
		cidr := ipToCIDR(operatorIP)
		if cidr != "" {
			cfg.AllowCIDRs = []string{cidr}
		}
	}

	cfg.TenantID = tenantID
	cfg.SiteID = siteID

	// --- persist ---
	saved, err := s.repo.UpsertConfig(ctx, cfg)
	if err != nil {
		return SecurityConfig{}, err
	}

	// --- push to agent (best-effort) ---
	if s.agentClient != nil && s.siteLookup != nil {
		siteURL, lookupErr := s.siteLookup.GetSiteURL(ctx, tenantID, siteID)
		if lookupErr == nil {
			req := agentcmd.SecurityConfigRequest{
				Mode:       saved.Mode,
				Thresholds: saved.Thresholds,
				IPHeader:   saved.IPHeader,
				AllowCIDRs: coalesceSlice(saved.AllowCIDRs),
				DenyCIDRs:  coalesceSlice(saved.DenyCIDRs),
			}
			if _, pushErr := s.agentClient.SyncSecurityConfig(ctx, siteID, siteURL, req); pushErr != nil {
				// Non-fatal: config is already persisted. Return stored config +
				// wrapped push error so the handler can surface it as a warning.
				return saved, fmt.Errorf("config stored but agent push failed: %w", pushErr)
			}
		}
		// site URL lookup failure is also non-fatal — config is still stored.
	}

	return saved, nil
}

// UnblockIP validates the IP string and sends the unblock_ip command to the
// agent. Returns the agent's ok+detail.
func (s *Service) UnblockIP(ctx context.Context, tenantID, siteID uuid.UUID, ip string) (bool, string, error) {
	if net.ParseIP(ip) == nil {
		return false, "", domain.Validation("invalid_ip", fmt.Sprintf("%q is not a valid IP address", ip))
	}
	if s.agentClient == nil || s.siteLookup == nil {
		return false, "", domain.ServiceUnavailable("security_agent_unwired", "security agent client is not wired")
	}
	siteURL, err := s.siteLookup.GetSiteURL(ctx, tenantID, siteID)
	if err != nil {
		return false, "", err
	}
	res, err := s.agentClient.UnblockIP(ctx, siteID, siteURL, agentcmd.UnblockIPRequest{IP: ip})
	if err != nil {
		// The client already wraps ok=false as an error; surface it as-is.
		return false, err.Error(), err
	}
	return res.OK, res.Detail, nil
}

// IngestLoginEvents dedup-upserts the agent-shipped batch and returns the
// highest agent_event_id so the caller (agent ingest handler) can echo the
// cursor advance back.
func (s *Service) IngestLoginEvents(ctx context.Context, tenantID, siteID uuid.UUID, batch LoginEventBatch) (int64, error) {
	events := make([]LoginEvent, 0, len(batch.LoginEvents))
	for _, e := range batch.LoginEvents {
		ev := LoginEvent{
			AgentEventID: int64(e.ID),
			IP:           e.IP,
			Status:       LoginEventStatus(int16(e.Status)),
			Category:     e.Category,
			Username:     e.Username,
			RequestID:    e.RequestID,
		}
		if ts := int64(e.OccurredAt); ts > 0 {
			ev.OccurredAt = time.Unix(ts, 0).UTC()
		}
		events = append(events, ev)
	}
	return s.repo.InsertLoginEventsBatch(ctx, tenantID, siteID, events)
}

// ListLoginEvents lists login events for a site.
func (s *Service) ListLoginEvents(ctx context.Context, tenantID, siteID uuid.UUID, limit int, statusFilter *LoginEventStatus) ([]LoginEvent, error) {
	return s.repo.ListLoginEvents(ctx, tenantID, siteID, limit, statusFilter)
}

// ---------------------------------------------------------------------------
// Phase 1 — hardening config
// ---------------------------------------------------------------------------

// validXMLRPCModes is the set of accepted xmlrpc_mode values.
var validXMLRPCModes = map[XMLRPCMode]bool{
	XMLRPCModeOn:      true,
	XMLRPCModeOff:     true,
	XMLRPCModeLimited: true,
}

// validRESTAPIModes is the set of accepted restrict_rest_api values.
var validRESTAPIModes = map[RESTAPIMode]bool{
	RESTAPIModeDefault:    true,
	RESTAPIModeRestricted: true,
}

// validLoginIdentifierModes is the set of accepted restrict_login_identifier values.
var validLoginIdentifierModes = map[LoginIdentifierMode]bool{
	LoginIdentifierUsername: true,
	LoginIdentifierEmail:    true,
	LoginIdentifierBoth:     true,
}

// GetHardeningConfig returns the stored hardening config, or the safe default
// when no row exists yet.
func (s *Service) GetHardeningConfig(ctx context.Context, tenantID, siteID uuid.UUID) (HardeningConfig, error) {
	cfg, found, err := s.repo.GetHardeningConfig(ctx, tenantID, siteID)
	if err != nil {
		return HardeningConfig{}, err
	}
	if !found {
		return DefaultHardeningConfig(tenantID, siteID), nil
	}
	return cfg, nil
}

// SaveHardeningConfig validates the incoming config, upserts it, and pushes it
// to the agent together with the current ban list. Returns the stored config.
// actorType and actorID are written to the row for audit tracing.
func (s *Service) SaveHardeningConfig(ctx context.Context, tenantID, siteID uuid.UUID, cfg HardeningConfig, actorType, actorID string) (HardeningConfig, error) {
	// --- enum validation ---
	if !validXMLRPCModes[cfg.XMLRPCMode] {
		return HardeningConfig{}, domain.Validation("invalid_xmlrpc_mode",
			fmt.Sprintf("xmlrpc_mode must be on|off|limited; got %q", cfg.XMLRPCMode))
	}
	if !validRESTAPIModes[cfg.RestrictRESTAPI] {
		return HardeningConfig{}, domain.Validation("invalid_restrict_rest_api",
			fmt.Sprintf("restrict_rest_api must be default|restricted; got %q", cfg.RestrictRESTAPI))
	}
	if !validLoginIdentifierModes[cfg.RestrictLoginIdentifier] {
		return HardeningConfig{}, domain.Validation("invalid_restrict_login_identifier",
			fmt.Sprintf("restrict_login_identifier must be username|email|both; got %q", cfg.RestrictLoginIdentifier))
	}

	cfg.TenantID = tenantID
	cfg.SiteID = siteID
	cfg.ActorType = actorType
	cfg.ActorID = actorID

	saved, err := s.repo.UpsertHardeningConfig(ctx, cfg)
	if err != nil {
		return HardeningConfig{}, err
	}

	// Push config + current ban list to agent (best-effort).
	pushErr := s.pushHardening(ctx, tenantID, siteID, saved)
	if pushErr != nil {
		return saved, fmt.Errorf("config stored but agent push failed: %w", pushErr)
	}
	return saved, nil
}

// pushHardening fetches the current ban list and sends the full config + ban
// snapshot to the agent via sync_security_hardening. Best-effort: callers
// surface push errors as warnings, not failures.
func (s *Service) pushHardening(ctx context.Context, tenantID, siteID uuid.UUID, cfg HardeningConfig) error {
	if s.hardeningClient == nil || s.siteLookup == nil {
		return nil
	}
	siteURL, err := s.siteLookup.GetSiteURL(ctx, tenantID, siteID)
	if err != nil {
		return nil // site URL unavailable — skip push silently
	}
	bans, err := s.repo.ListBans(ctx, tenantID, siteID)
	if err != nil {
		return fmt.Errorf("load bans for push: %w", err)
	}
	banEntries := make([]agentcmd.BanEntry, 0, len(bans))
	for _, b := range bans {
		banEntries = append(banEntries, agentcmd.BanEntry{
			ID:      b.ID.String(),
			Type:    string(b.Type),
			Value:   b.Value,
			Comment: b.Comment,
		})
	}
	req := agentcmd.HardeningRequest{
		Config: agentcmd.HardeningConfig{
			DisableFileEditor:        cfg.DisableFileEditor,
			XMLRPCMode:               string(cfg.XMLRPCMode),
			RestrictRESTAPI:          string(cfg.RestrictRESTAPI),
			RestrictLoginIdentifier:  string(cfg.RestrictLoginIdentifier),
			ForceUniqueNickname:      cfg.ForceUniqueNickname,
			DisableAuthorArchiveEnum: cfg.DisableAuthorArchiveEnum,
			ForceSSL:                 cfg.ForceSSL,
			DisableDirectoryBrowsing: cfg.DisableDirectoryBrowsing,
			DisablePHPInUploads:      cfg.DisablePHPInUploads,
			ProtectSystemFiles:       cfg.ProtectSystemFiles,
		},
		Bans: banEntries,
	}
	if _, pushErr := s.hardeningClient.SyncSecurityHardening(ctx, siteID, siteURL, req); pushErr != nil {
		return pushErr
	}
	return nil
}

// ---------------------------------------------------------------------------
// Phase 1 — ban list
// ---------------------------------------------------------------------------

// validBanTypes is the set of accepted ban type values.
var validBanTypes = map[BanType]bool{
	BanTypeIP:        true,
	BanTypeRange:     true,
	BanTypeUserAgent: true,
}

// banMaxUserAgentLen is the maximum permitted length for a user_agent ban value.
// Matches the web client's own input cap (512 bytes) and prevents oversized WAF
// rules from being injected downstream.
const banMaxUserAgentLen = 512

// banMinIPv4Prefix is the minimum acceptable IPv4 prefix length for a range ban.
// A prefix shorter than /8 (e.g. /7, /6, …, /0) covers more than a full /8
// block — effectively a continent-scale or all-addresses block, which is either
// a misconfig or an attempt to lock out virtually all traffic. The /8 boundary
// is a widely accepted "broadest useful" single-ISP/network block.
const banMinIPv4Prefix = 8

// banMinIPv6Prefix is the minimum acceptable IPv6 prefix length for a range ban.
// Prefixes shorter than /32 span entire regional internet registries (e.g. a /19
// covers millions of hosts). /32 is the smallest allocation an ISP typically
// receives and is a reasonable "broadest useful block" boundary for ban purposes.
const banMinIPv6Prefix = 32

// validateBan performs semantic safety checks on a ban entry AFTER the basic
// type/syntax validation in CreateBan. It is a pure function with no DB access,
// making it straightforward to unit-test in isolation.
//
// Rejected cases (all return domain.Validation errors):
//
//   - BanTypeRange: all-addresses ranges (0.0.0.0/0, ::/0), over-broad prefixes
//     (IPv4 < /8, IPv6 < /32), and ranges that wholly contain loopback or
//     RFC-1918/link-local/ULA private space (banning a private range is a no-op
//     on public sites and risks self-lockout for shared-hosting operators).
//
//   - BanTypeIP: loopback, RFC-1918 private, link-local, and ULA addresses.
//     Banning a private IP has no effect on inbound public traffic and is almost
//     certainly a misconfig.
//
//   - BanTypeUserAgent: values containing ASCII control characters (including
//     CR/LF), which could enable rule-injection in downstream WAF/agent
//     configuration files. Also rejects values that are empty after trimming, or
//     exceed banMaxUserAgentLen bytes.
func validateBan(ban Ban) error {
	switch ban.Type {
	case BanTypeRange:
		cidr := strings.TrimSpace(ban.Value)
		// net.ParseCIDR already ran before validateBan; the parse here always succeeds.
		_, ipNet, _ := net.ParseCIDR(cidr)
		ones, bits := ipNet.Mask.Size()

		// Reject all-addresses ranges explicitly for a clear error message.
		if ones == 0 {
			return domain.Validation("ban_range_too_broad",
				"cannot ban an all-addresses range (0.0.0.0/0 or ::/0); use the security-config deny list for global blocks")
		}

		// Reject over-broad prefixes.
		if bits == 32 && ones < banMinIPv4Prefix {
			return domain.Validation("ban_range_too_broad",
				fmt.Sprintf("IPv4 range ban prefix must be /%d or longer; /%d covers too large an address space", banMinIPv4Prefix, ones))
		}
		if bits == 128 && ones < banMinIPv6Prefix {
			return domain.Validation("ban_range_too_broad",
				fmt.Sprintf("IPv6 range ban prefix must be /%d or longer; /%d covers too large an address space", banMinIPv6Prefix, ones))
		}

		// Reject ranges that fully contain private/loopback/link-local/ULA space.
		// We test by checking whether the network's base address itself is
		// non-public; for a prefix that wholly covers private space (e.g. 10/8,
		// 192.168/16, fc00::/7), the base address is not a global unicast address.
		if !ipprovider.IsGlobalUnicast(ipNet.IP.String()) {
			return domain.Validation("ban_range_private",
				"cannot ban a private, loopback, or link-local address range; this has no effect on public traffic and may cause self-lockout")
		}

	case BanTypeIP:
		ip := strings.TrimSpace(ban.Value)
		// net.ParseIP already validated syntax; this check is semantic.
		if !ipprovider.IsGlobalUnicast(ip) {
			return domain.Validation("ban_ip_private",
				"cannot ban a private, loopback, or link-local address; this has no effect on public traffic")
		}

	case BanTypeUserAgent:
		ua := strings.TrimSpace(ban.Value)
		if ua == "" {
			return domain.Validation("invalid_ban_value", "ban value is required")
		}
		if len(ua) > banMaxUserAgentLen {
			return domain.Validation("ban_ua_too_long",
				fmt.Sprintf("user agent ban value must be %d characters or fewer", banMaxUserAgentLen))
		}
		// Reject any ASCII control character including CR (\r) and LF (\n).
		// These can break downstream WAF/nginx configuration files via rule injection.
		for _, r := range ua {
			if r < 0x20 || r == unicode.MaxRune || unicode.IsControl(r) {
				return domain.Validation("ban_ua_control_char",
					"user agent must not contain control characters (including newlines and carriage returns)")
			}
		}
	}
	return nil
}

// ListBans returns all bans for a site.
func (s *Service) ListBans(ctx context.Context, tenantID, siteID uuid.UUID) ([]Ban, error) {
	return s.repo.ListBans(ctx, tenantID, siteID)
}

// CreateBan validates the incoming ban entry, inserts it, and pushes the
// updated config + ban list to the agent. Returns the stored ban.
func (s *Service) CreateBan(ctx context.Context, tenantID, siteID uuid.UUID, ban Ban) (Ban, error) {
	// --- type validation ---
	if !validBanTypes[ban.Type] {
		return Ban{}, domain.Validation("invalid_ban_type",
			fmt.Sprintf("ban type must be ip|range|user_agent; got %q", ban.Type))
	}
	// --- value validation: basic syntax ---
	ban.Value = strings.TrimSpace(ban.Value)
	if ban.Value == "" {
		return Ban{}, domain.Validation("invalid_ban_value", "ban value is required")
	}
	switch ban.Type {
	case BanTypeIP:
		if net.ParseIP(ban.Value) == nil {
			return Ban{}, domain.Validation("invalid_ban_ip",
				fmt.Sprintf("%q is not a valid IP address", ban.Value))
		}
	case BanTypeRange:
		if _, _, err := net.ParseCIDR(ban.Value); err != nil {
			return Ban{}, domain.Validation("invalid_ban_cidr",
				fmt.Sprintf("%q is not a valid CIDR block", ban.Value))
		}
	}
	// --- value validation: semantic safety (defense-in-depth) ---
	if err := validateBan(ban); err != nil {
		return Ban{}, err
	}

	ban.TenantID = tenantID
	ban.SiteID = siteID

	saved, err := s.repo.InsertBan(ctx, ban)
	if err != nil {
		return Ban{}, err
	}

	// Push config + new ban list to agent (best-effort).
	if cfg, cfgErr := s.GetHardeningConfig(ctx, tenantID, siteID); cfgErr == nil {
		_ = s.pushHardening(ctx, tenantID, siteID, cfg)
	}
	return saved, nil
}

// DeleteBan removes a ban entry and re-pushes the config + ban list to the
// agent. Returns domain.NotFound when no such ban exists.
func (s *Service) DeleteBan(ctx context.Context, tenantID, siteID, banID uuid.UUID) error {
	if err := s.repo.DeleteBan(ctx, tenantID, siteID, banID); err != nil {
		return err
	}
	// Re-push updated ban list to agent (best-effort).
	if cfg, cfgErr := s.GetHardeningConfig(ctx, tenantID, siteID); cfgErr == nil {
		_ = s.pushHardening(ctx, tenantID, siteID, cfg)
	}
	return nil
}

// ---------------------------------------------------------------------------
// wire-format batch types (flexInt tolerance for wpdb ARRAY_A numeric strings)
// ---------------------------------------------------------------------------

// LoginEventEntry is one entry in the agent-pushed batch. All numeric fields
// use flexInt64 / flexInt16 so they tolerate wpdb ARRAY_A's quoted-number
// encoding ("5" instead of 5).
type LoginEventEntry struct {
	ID         flexInt64 `json:"id"`
	IP         string    `json:"ip"`
	Status     flexInt16 `json:"status"`
	Category   string    `json:"category"`
	Username   string    `json:"username"`
	RequestID  string    `json:"request_id"`
	OccurredAt flexInt64 `json:"occurred_at"`
}

// LoginEventBatch is the top-level body the agent POSTs to /agent/v1/security/login-events.
type LoginEventBatch struct {
	LoginEvents []LoginEventEntry `json:"login_events"`
}

// flexInt64 unmarshals a JSON value that may arrive as a number or a quoted
// numeric string (wpdb ARRAY_A always encodes numeric columns as strings).
type flexInt64 int64

func (f *flexInt64) UnmarshalJSON(b []byte) error {
	if len(b) > 0 && b[0] != '"' {
		var n int64
		if err := json.Unmarshal(b, &n); err != nil {
			return err
		}
		*f = flexInt64(n)
		return nil
	}
	var s string
	if err := json.Unmarshal(b, &s); err != nil {
		return err
	}
	if s == "" {
		*f = 0
		return nil
	}
	n, err := strconv.ParseInt(s, 10, 64)
	if err != nil {
		return err
	}
	*f = flexInt64(n)
	return nil
}

// flexInt16 is like flexInt64 but for smallint-sized fields (status).
type flexInt16 int16

func (f *flexInt16) UnmarshalJSON(b []byte) error {
	if len(b) > 0 && b[0] != '"' {
		var n int16
		if err := json.Unmarshal(b, &n); err != nil {
			return err
		}
		*f = flexInt16(n)
		return nil
	}
	var s string
	if err := json.Unmarshal(b, &s); err != nil {
		return err
	}
	if s == "" {
		*f = 0
		return nil
	}
	n, err := strconv.ParseInt(s, 10, 16)
	if err != nil {
		return err
	}
	*f = flexInt16(n)
	return nil
}

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

func defaultConfig(tenantID, siteID uuid.UUID) SecurityConfig {
	return SecurityConfig{
		TenantID:   tenantID,
		SiteID:     siteID,
		Mode:       "protect",
		Thresholds: agentcmd.DefaultSecurityThresholds,
		IPHeader:   "REMOTE_ADDR",
		AllowCIDRs: []string{},
		DenyCIDRs:  []string{},
	}
}

// clampThresholds enforces sane positive ranges; zero values fall back to the
// compiled-in defaults.
func clampThresholds(t agentcmd.SecurityThresholds) agentcmd.SecurityThresholds {
	def := agentcmd.DefaultSecurityThresholds
	clamp := func(v, lo, hi int) int {
		if v < lo {
			return lo
		}
		if v > hi {
			return hi
		}
		return v
	}
	const maxCount = 10000
	const maxSecs = 365 * 24 * 3600
	if t.CaptchaLimit <= 0 {
		t.CaptchaLimit = def.CaptchaLimit
	}
	if t.TempBlockLimit <= 0 {
		t.TempBlockLimit = def.TempBlockLimit
	}
	if t.BlockAllLimit <= 0 {
		t.BlockAllLimit = def.BlockAllLimit
	}
	if t.FailedLoginGap <= 0 {
		t.FailedLoginGap = def.FailedLoginGap
	}
	if t.SuccessLoginGap <= 0 {
		t.SuccessLoginGap = def.SuccessLoginGap
	}
	if t.AllBlockedGap <= 0 {
		t.AllBlockedGap = def.AllBlockedGap
	}
	t.CaptchaLimit = clamp(t.CaptchaLimit, 1, maxCount)
	t.TempBlockLimit = clamp(t.TempBlockLimit, 1, maxCount)
	t.BlockAllLimit = clamp(t.BlockAllLimit, 1, maxCount)
	t.FailedLoginGap = clamp(t.FailedLoginGap, 1, maxSecs)
	t.SuccessLoginGap = clamp(t.SuccessLoginGap, 1, maxSecs)
	t.AllBlockedGap = clamp(t.AllBlockedGap, 1, maxSecs)
	return t
}

// normalizeCIDRs trims whitespace and filters out empty strings.
func normalizeCIDRs(in []string) []string {
	out := make([]string, 0, len(in))
	for _, s := range in {
		s = strings.TrimSpace(s)
		if s != "" {
			out = append(out, s)
		}
	}
	return out
}

// ipToCIDR converts a raw IP string (possibly host:port) to the minimal
// host-CIDR (/32 for IPv4, /128 for IPv6). Returns "" on parse failure.
func ipToCIDR(rawIP string) string {
	host, _, err := net.SplitHostPort(rawIP)
	if err != nil {
		host = rawIP
	}
	ip := net.ParseIP(strings.TrimSpace(host))
	if ip == nil {
		return ""
	}
	if ip.To4() != nil {
		return ip.String() + "/32"
	}
	return ip.String() + "/128"
}

// coalesceSlice returns an empty (non-nil) slice when the input is nil.
func coalesceSlice(s []string) []string {
	if s == nil {
		return []string{}
	}
	return s
}
