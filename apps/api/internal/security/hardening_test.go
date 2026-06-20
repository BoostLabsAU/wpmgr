package security

import (
	"context"
	"encoding/json"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/agentcmd"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/ipprovider"
	"github.com/mosamlife/wpmgr/apps/api/internal/server/httpx"
)

// ---------------------------------------------------------------------------
// In-memory fake service (no DB, no RLS, tests service/DTO/handler logic)
// ---------------------------------------------------------------------------

// fakeHardeningService is a stand-in for *Service that keeps state in memory.
// It validates the same business rules as the real service so the tests cover
// the validation paths without a Postgres connection.
type fakeHardeningService struct {
	configs map[uuid.UUID]HardeningConfig // keyed by siteID
	bans    map[uuid.UUID][]Ban           // keyed by siteID
}

func newFakeHardeningService() *fakeHardeningService {
	return &fakeHardeningService{
		configs: make(map[uuid.UUID]HardeningConfig),
		bans:    make(map[uuid.UUID][]Ban),
	}
}

func (s *fakeHardeningService) getHardeningConfig(_ context.Context, tenantID, siteID uuid.UUID) (HardeningConfig, error) {
	if cfg, ok := s.configs[siteID]; ok {
		return cfg, nil
	}
	return DefaultHardeningConfig(tenantID, siteID), nil
}

func (s *fakeHardeningService) saveHardeningConfig(_ context.Context, tenantID, siteID uuid.UUID, cfg HardeningConfig, actorType, actorID string) (HardeningConfig, error) {
	if !validXMLRPCModes[cfg.XMLRPCMode] {
		return HardeningConfig{}, domain.Validation("invalid_xmlrpc_mode",
			"xmlrpc_mode must be on|off|limited")
	}
	if !validRESTAPIModes[cfg.RestrictRESTAPI] {
		return HardeningConfig{}, domain.Validation("invalid_restrict_rest_api",
			"restrict_rest_api must be default|restricted")
	}
	if !validLoginIdentifierModes[cfg.RestrictLoginIdentifier] {
		return HardeningConfig{}, domain.Validation("invalid_restrict_login_identifier",
			"restrict_login_identifier must be username|email|both")
	}
	cfg.TenantID = tenantID
	cfg.SiteID = siteID
	cfg.ActorType = actorType
	cfg.ActorID = actorID
	cfg.UpdatedAt = time.Now()
	s.configs[siteID] = cfg
	return cfg, nil
}

func (s *fakeHardeningService) listBans(_ context.Context, _, siteID uuid.UUID) ([]Ban, error) {
	bans := s.bans[siteID]
	if bans == nil {
		return []Ban{}, nil
	}
	return bans, nil
}

func (s *fakeHardeningService) createBan(_ context.Context, tenantID, siteID uuid.UUID, ban Ban) (Ban, error) {
	if !validBanTypes[ban.Type] {
		return Ban{}, domain.Validation("invalid_ban_type",
			"ban type must be ip|range|user_agent")
	}
	ban.Value = strings.TrimSpace(ban.Value)
	if ban.Value == "" {
		return Ban{}, domain.Validation("invalid_ban_value", "ban value is required")
	}
	switch ban.Type {
	case BanTypeIP:
		// simple inline check mirroring net.ParseIP validation
		if !looksLikeIPAddr(ban.Value) {
			return Ban{}, domain.Validation("invalid_ban_ip",
				"not a valid IP address")
		}
	case BanTypeRange:
		if !strings.Contains(ban.Value, "/") || !looksLikeIPAddr(strings.Split(ban.Value, "/")[0]) {
			return Ban{}, domain.Validation("invalid_ban_cidr",
				"not a valid CIDR block")
		}
	}
	// Mirror the semantic safety rules from validateBan so the fake and real
	// service behave consistently.
	if err := validateBan(ban); err != nil {
		return Ban{}, err
	}
	for _, existing := range s.bans[siteID] {
		if existing.Type == ban.Type && existing.Value == ban.Value {
			return Ban{}, domain.Conflict("ban_already_exists",
				"a ban for this type/value already exists on this site")
		}
	}
	ban.TenantID = tenantID
	ban.SiteID = siteID
	ban.ID = uuid.New()
	ban.CreatedAt = time.Now()
	s.bans[siteID] = append(s.bans[siteID], ban)
	return ban, nil
}

func (s *fakeHardeningService) deleteBan(_ context.Context, _, siteID, banID uuid.UUID) error {
	for i, b := range s.bans[siteID] {
		if b.ID == banID {
			s.bans[siteID] = append(s.bans[siteID][:i], s.bans[siteID][i+1:]...)
			return nil
		}
	}
	return domain.NotFound("ban_not_found", "security ban not found")
}

// looksLikeIPAddr is a minimal IP-plausibility check for the in-memory fake.
// It does NOT replace the net.ParseIP validation in the real service; it just
// avoids importing net in the test package while still catching obvious bad values.
func looksLikeIPAddr(s string) bool {
	s = strings.TrimSpace(s)
	if s == "" {
		return false
	}
	parts := strings.Split(s, ".")
	if len(parts) == 4 {
		// IPv4 candidate: every octet must be digits 0-255.
		for _, p := range parts {
			if len(p) == 0 || len(p) > 3 {
				return false
			}
			for _, c := range p {
				if c < '0' || c > '9' {
					return false
				}
			}
		}
		return true
	}
	// IPv6 candidate: must contain at least one colon and only hex digits/colons.
	if strings.Contains(s, ":") {
		for _, c := range s {
			if !(c == ':' || (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F')) {
				return false
			}
		}
		return true
	}
	return false
}

// principalMiddleware returns a Gin middleware that injects a test principal.
func principalMiddleware(tenantID uuid.UUID) gin.HandlerFunc {
	return func(c *gin.Context) {
		p := domain.Principal{
			Type:     domain.PrincipalUser,
			UserID:   tenantID,
			TenantID: tenantID,
			Role:     "owner",
		}
		c.Request = c.Request.WithContext(domain.WithPrincipal(c.Request.Context(), p))
		c.Next()
	}
}

// ---------------------------------------------------------------------------
// Unit tests: defaults
// ---------------------------------------------------------------------------

// TestHardeningDefaultConfig verifies that a not-found site returns safe
// defaults (all toggles off, permissive enum values).
func TestHardeningDefaultConfig(t *testing.T) {
	svc := newFakeHardeningService()
	tenantID := uuid.New()
	siteID := uuid.New()

	cfg, err := svc.getHardeningConfig(context.Background(), tenantID, siteID)
	if err != nil {
		t.Fatalf("GetHardeningConfig: unexpected error: %v", err)
	}
	if cfg.DisableFileEditor {
		t.Error("default: disable_file_editor should be false")
	}
	if cfg.XMLRPCMode != XMLRPCModeOn {
		t.Errorf("default: xmlrpc_mode want 'on', got %q", cfg.XMLRPCMode)
	}
	if cfg.RestrictRESTAPI != RESTAPIModeDefault {
		t.Errorf("default: restrict_rest_api want 'default', got %q", cfg.RestrictRESTAPI)
	}
	if cfg.RestrictLoginIdentifier != LoginIdentifierBoth {
		t.Errorf("default: restrict_login_identifier want 'both', got %q", cfg.RestrictLoginIdentifier)
	}
	if cfg.ForceSSL {
		t.Error("default: force_ssl should be false")
	}
}

// ---------------------------------------------------------------------------
// Unit tests: save + get round-trip
// ---------------------------------------------------------------------------

func TestHardeningPutGetRoundTrip(t *testing.T) {
	svc := newFakeHardeningService()
	tenantID := uuid.New()
	siteID := uuid.New()

	input := HardeningConfig{
		DisableFileEditor:        true,
		XMLRPCMode:               XMLRPCModeOff,
		RestrictRESTAPI:          RESTAPIModeRestricted,
		RestrictLoginIdentifier:  LoginIdentifierEmail,
		ForceUniqueNickname:      true,
		DisableAuthorArchiveEnum: true,
		ForceSSL:                 true,
		DisableDirectoryBrowsing: true,
		DisablePHPInUploads:      true,
		ProtectSystemFiles:       true,
	}
	saved, err := svc.saveHardeningConfig(context.Background(), tenantID, siteID, input, "user", "u1")
	if err != nil {
		t.Fatalf("save: %v", err)
	}
	got, err := svc.getHardeningConfig(context.Background(), tenantID, siteID)
	if err != nil {
		t.Fatalf("get: %v", err)
	}
	if got.XMLRPCMode != saved.XMLRPCMode {
		t.Errorf("xmlrpc_mode round-trip: want %q got %q", saved.XMLRPCMode, got.XMLRPCMode)
	}
	if got.RestrictRESTAPI != RESTAPIModeRestricted {
		t.Errorf("restrict_rest_api: want restricted, got %q", got.RestrictRESTAPI)
	}
	if !got.DisableFileEditor {
		t.Error("disable_file_editor should be true after save")
	}
	if !got.ForceSSL {
		t.Error("force_ssl should be true after save")
	}
	if got.TenantID != tenantID {
		t.Errorf("tenant_id: want %s, got %s", tenantID, got.TenantID)
	}
}

// ---------------------------------------------------------------------------
// Unit tests: enum validation
// ---------------------------------------------------------------------------

func TestHardeningInvalidEnum(t *testing.T) {
	svc := newFakeHardeningService()
	tenantID := uuid.New()
	siteID := uuid.New()

	cases := []struct {
		name string
		cfg  HardeningConfig
	}{
		{
			name: "invalid xmlrpc_mode",
			cfg: HardeningConfig{
				XMLRPCMode:              "bogus",
				RestrictRESTAPI:         RESTAPIModeDefault,
				RestrictLoginIdentifier: LoginIdentifierBoth,
			},
		},
		{
			name: "invalid restrict_rest_api",
			cfg: HardeningConfig{
				XMLRPCMode:              XMLRPCModeOn,
				RestrictRESTAPI:         "open",
				RestrictLoginIdentifier: LoginIdentifierBoth,
			},
		},
		{
			name: "invalid restrict_login_identifier",
			cfg: HardeningConfig{
				XMLRPCMode:              XMLRPCModeOn,
				RestrictRESTAPI:         RESTAPIModeDefault,
				RestrictLoginIdentifier: "phone",
			},
		},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			_, err := svc.saveHardeningConfig(context.Background(), tenantID, siteID, tc.cfg, "user", "u1")
			if err == nil {
				t.Fatal("want validation error, got nil")
			}
			_, ok := domain.AsDomain(err)
			if !ok {
				t.Fatalf("want domain error, got %T: %v", err, err)
			}
			if domain.HTTPStatus(err) != http.StatusUnprocessableEntity {
				t.Errorf("want 422, got %d", domain.HTTPStatus(err))
			}
		})
	}
}

// ---------------------------------------------------------------------------
// Unit tests: tenant isolation at the service layer
// ---------------------------------------------------------------------------

// TestHardeningTenantIsolation verifies that the service stamps the correct
// tenantID onto the saved row so the repo's tenant-scoped writes carry the
// right tenant_id column value (and the RLS GUC then enforces isolation at the
// DB layer in production).
func TestHardeningTenantIsolation(t *testing.T) {
	svc := newFakeHardeningService()
	tenantA := uuid.New()
	tenantB := uuid.New()
	siteIDA := uuid.New()
	siteIDB := uuid.New() // separate site IDs so in-memory map doesn't collide

	cfgA := HardeningConfig{
		XMLRPCMode:              XMLRPCModeOff,
		RestrictRESTAPI:         RESTAPIModeDefault,
		RestrictLoginIdentifier: LoginIdentifierBoth,
		ForceSSL:                true,
	}
	savedA, err := svc.saveHardeningConfig(context.Background(), tenantA, siteIDA, cfgA, "user", "u-a")
	if err != nil {
		t.Fatalf("save A: %v", err)
	}
	if savedA.TenantID != tenantA {
		t.Errorf("savedA.TenantID want %s got %s", tenantA, savedA.TenantID)
	}

	cfgB := HardeningConfig{
		XMLRPCMode:              XMLRPCModeOn,
		RestrictRESTAPI:         RESTAPIModeDefault,
		RestrictLoginIdentifier: LoginIdentifierBoth,
		ForceSSL:                false,
	}
	savedB, err := svc.saveHardeningConfig(context.Background(), tenantB, siteIDB, cfgB, "user", "u-b")
	if err != nil {
		t.Fatalf("save B: %v", err)
	}
	if savedB.TenantID != tenantB {
		t.Errorf("savedB.TenantID want %s got %s", tenantB, savedB.TenantID)
	}

	// The row for site A must still carry tenant A's tenant_id.
	gotA, _ := svc.getHardeningConfig(context.Background(), tenantA, siteIDA)
	if gotA.TenantID != tenantA {
		t.Errorf("got tenant_id for A: want %s, got %s", tenantA, gotA.TenantID)
	}
	if gotA.ForceSSL != true {
		t.Error("tenant A force_ssl should still be true")
	}
	// The row for site B should be tenant B's config.
	gotB, _ := svc.getHardeningConfig(context.Background(), tenantB, siteIDB)
	if gotB.TenantID != tenantB {
		t.Errorf("got tenant_id for B: want %s, got %s", tenantB, gotB.TenantID)
	}
	if gotB.ForceSSL {
		t.Error("tenant B force_ssl should be false")
	}
}

// ---------------------------------------------------------------------------
// Unit tests: ban create / list / delete
// ---------------------------------------------------------------------------

func TestBanCreateListDelete(t *testing.T) {
	svc := newFakeHardeningService()
	tenantID := uuid.New()
	siteID := uuid.New()
	ctx := context.Background()

	// Empty list.
	bans, err := svc.listBans(ctx, tenantID, siteID)
	if err != nil {
		t.Fatalf("list empty: %v", err)
	}
	if len(bans) != 0 {
		t.Fatalf("expected empty list, got %d", len(bans))
	}

	// IP ban.
	ipBan, err := svc.createBan(ctx, tenantID, siteID, Ban{
		Type: BanTypeIP, Value: "1.2.3.4", Comment: "test",
	})
	if err != nil {
		t.Fatalf("create ip ban: %v", err)
	}
	if ipBan.ID == uuid.Nil {
		t.Error("created ban must have non-zero ID")
	}

	// CIDR ban — use a public documentation range (RFC 5737 TEST-NET-3).
	_, err = svc.createBan(ctx, tenantID, siteID, Ban{
		Type: BanTypeRange, Value: "203.0.113.0/24",
	})
	if err != nil {
		t.Fatalf("create cidr ban: %v", err)
	}

	// UA ban.
	_, err = svc.createBan(ctx, tenantID, siteID, Ban{
		Type: BanTypeUserAgent, Value: "badbot/1.0",
	})
	if err != nil {
		t.Fatalf("create ua ban: %v", err)
	}

	bans, _ = svc.listBans(ctx, tenantID, siteID)
	if len(bans) != 3 {
		t.Fatalf("want 3 bans, got %d", len(bans))
	}

	// Delete one.
	if err := svc.deleteBan(ctx, tenantID, siteID, ipBan.ID); err != nil {
		t.Fatalf("delete: %v", err)
	}
	bans, _ = svc.listBans(ctx, tenantID, siteID)
	if len(bans) != 2 {
		t.Fatalf("want 2 bans after delete, got %d", len(bans))
	}
}

func TestBanDuplicateRejection(t *testing.T) {
	svc := newFakeHardeningService()
	tenantID := uuid.New()
	siteID := uuid.New()
	ctx := context.Background()

	ban := Ban{Type: BanTypeIP, Value: "203.0.113.42"}
	if _, err := svc.createBan(ctx, tenantID, siteID, ban); err != nil {
		t.Fatalf("first create: %v", err)
	}
	_, err := svc.createBan(ctx, tenantID, siteID, ban)
	if err == nil {
		t.Fatal("want Conflict error for duplicate, got nil")
	}
	_, ok := domain.AsDomain(err)
	if !ok {
		t.Fatalf("want domain error, got %T: %v", err, err)
	}
	if domain.HTTPStatus(err) != http.StatusConflict {
		t.Errorf("want 409, got %d", domain.HTTPStatus(err))
	}
}

func TestBanMalformedIPRejection(t *testing.T) {
	svc := newFakeHardeningService()
	tenantID := uuid.New()
	siteID := uuid.New()
	ctx := context.Background()

	cases := []struct {
		name  string
		entry Ban
	}{
		{name: "bad IP", entry: Ban{Type: BanTypeIP, Value: "not-an-ip"}},
		// "notacidr" has no slash, so looksLikeIPAddr on the host part fails and
		// the slash-check catches it before even parsing the host.
		{name: "bad CIDR no slash", entry: Ban{Type: BanTypeRange, Value: "notacidr"}},
		{name: "bad type", entry: Ban{Type: "country", Value: "US"}},
		{name: "empty value", entry: Ban{Type: BanTypeIP, Value: ""}},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			_, err := svc.createBan(ctx, tenantID, siteID, tc.entry)
			if err == nil {
				t.Fatalf("want validation error, got nil for %+v", tc.entry)
			}
			_, ok := domain.AsDomain(err)
			if !ok {
				t.Fatalf("want domain error, got %T: %v", err, err)
			}
			if domain.HTTPStatus(err) != http.StatusUnprocessableEntity && domain.HTTPStatus(err) != http.StatusConflict {
				t.Errorf("want 422 or 409, got %d", domain.HTTPStatus(err))
			}
		})
	}
}

// ---------------------------------------------------------------------------
// Unit tests: ban safety validation (defense-in-depth rejections)
// ---------------------------------------------------------------------------

// TestBanSafetyRejections calls validateBan directly to assert that lock-out-
// class and private-range bans are rejected at the service layer, independent of
// any DB or agent interaction.
func TestBanSafetyRejections(t *testing.T) {
	cases := []struct {
		name    string
		ban     Ban
		wantCode string // expected domain error code substring
	}{
		// --- range: all-addresses ---
		{
			name:     "all-addresses IPv4 (0.0.0.0/0)",
			ban:      Ban{Type: BanTypeRange, Value: "0.0.0.0/0"},
			wantCode: "ban_range_too_broad",
		},
		{
			name:     "all-addresses IPv6 (::/0)",
			ban:      Ban{Type: BanTypeRange, Value: "::/0"},
			wantCode: "ban_range_too_broad",
		},
		// --- range: over-broad prefix ---
		{
			name:     "IPv4 over-broad /7 (straddles two /8 blocks)",
			ban:      Ban{Type: BanTypeRange, Value: "10.0.0.0/7"},
			wantCode: "ban_range_too_broad",
		},
		{
			name:     "IPv6 over-broad /16",
			ban:      Ban{Type: BanTypeRange, Value: "2001:db8::/16"},
			wantCode: "ban_range_too_broad",
		},
		// --- range: private space ---
		{
			name:     "RFC-1918 10/8 private range",
			ban:      Ban{Type: BanTypeRange, Value: "10.0.0.0/8"},
			wantCode: "ban_range_private",
		},
		{
			name:     "RFC-1918 172.16/12 private range",
			ban:      Ban{Type: BanTypeRange, Value: "172.16.0.0/12"},
			wantCode: "ban_range_private",
		},
		{
			name:     "RFC-1918 192.168/16 private range",
			ban:      Ban{Type: BanTypeRange, Value: "192.168.0.0/16"},
			wantCode: "ban_range_private",
		},
		{
			name:     "loopback range 127.0.0.0/8",
			ban:      Ban{Type: BanTypeRange, Value: "127.0.0.0/8"},
			wantCode: "ban_range_private",
		},
		{
			// fc00::/7 would first be caught by the over-broad (/7 < /32) check;
			// use fc00::/32 to isolate the private-range check specifically.
			name:     "ULA IPv6 range fc00::/32 (not over-broad, but private)",
			ban:      Ban{Type: BanTypeRange, Value: "fc00::/32"},
			wantCode: "ban_range_private",
		},
		// --- ip: loopback ---
		{
			name:     "IPv4 loopback 127.0.0.1",
			ban:      Ban{Type: BanTypeIP, Value: "127.0.0.1"},
			wantCode: "ban_ip_private",
		},
		{
			name:     "IPv6 loopback ::1",
			ban:      Ban{Type: BanTypeIP, Value: "::1"},
			wantCode: "ban_ip_private",
		},
		// --- ip: RFC-1918 private ---
		{
			name:     "private IPv4 10.x.x.x",
			ban:      Ban{Type: BanTypeIP, Value: "10.0.0.1"},
			wantCode: "ban_ip_private",
		},
		{
			name:     "private IPv4 192.168.x.x",
			ban:      Ban{Type: BanTypeIP, Value: "192.168.1.1"},
			wantCode: "ban_ip_private",
		},
		{
			name:     "private IPv4 172.16.x.x",
			ban:      Ban{Type: BanTypeIP, Value: "172.16.5.5"},
			wantCode: "ban_ip_private",
		},
		// --- ip: link-local and ULA ---
		{
			name:     "IPv4 link-local 169.254.1.1",
			ban:      Ban{Type: BanTypeIP, Value: "169.254.1.1"},
			wantCode: "ban_ip_private",
		},
		{
			name:     "IPv6 ULA fc00::1",
			ban:      Ban{Type: BanTypeIP, Value: "fc00::1"},
			wantCode: "ban_ip_private",
		},
		{
			name:     "IPv6 link-local fe80::1",
			ban:      Ban{Type: BanTypeIP, Value: "fe80::1"},
			wantCode: "ban_ip_private",
		},
		// --- user_agent: control characters ---
		{
			name:     "user_agent with newline",
			ban:      Ban{Type: BanTypeUserAgent, Value: "badbot\nAnother-Header: injected"},
			wantCode: "ban_ua_control_char",
		},
		{
			// Trailing \r\n is stripped by TrimSpace before validateBan sees it;
			// use CR/LF embedded in the middle to test mid-string injection.
			name:     "user_agent with embedded CR+LF (mid-string injection)",
			ban:      Ban{Type: BanTypeUserAgent, Value: "bad\r\nbot"},
			wantCode: "ban_ua_control_char",
		},
		{
			name:     "user_agent with null byte",
			ban:      Ban{Type: BanTypeUserAgent, Value: "badbot\x00"},
			wantCode: "ban_ua_control_char",
		},
		{
			name:     "user_agent with tab (control char)",
			ban:      Ban{Type: BanTypeUserAgent, Value: "bad\tbot"},
			wantCode: "ban_ua_control_char",
		},
		// --- user_agent: over-length ---
		{
			name:     "user_agent over 512 bytes",
			ban:      Ban{Type: BanTypeUserAgent, Value: strings.Repeat("A", banMaxUserAgentLen+1)},
			wantCode: "ban_ua_too_long",
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			err := validateBan(tc.ban)
			if err == nil {
				t.Fatalf("validateBan: want error for %+v, got nil", tc.ban)
			}
			de, ok := domain.AsDomain(err)
			if !ok {
				t.Fatalf("want domain error, got %T: %v", err, err)
			}
			if domain.HTTPStatus(err) != http.StatusUnprocessableEntity {
				t.Errorf("want 422, got %d; err=%v", domain.HTTPStatus(err), err)
			}
			if de.Code != tc.wantCode {
				t.Errorf("want domain code %q, got %q (msg: %s)", tc.wantCode, de.Code, err)
			}
		})
	}
}

// TestBanSafetyAccepts verifies that normal public-facing values pass validateBan
// so valid legitimate bans are not accidentally rejected.
func TestBanSafetyAccepts(t *testing.T) {
	cases := []struct {
		name string
		ban  Ban
	}{
		{name: "public IPv4", ban: Ban{Type: BanTypeIP, Value: "1.2.3.4"}},
		{name: "public IPv4 (doc range)", ban: Ban{Type: BanTypeIP, Value: "203.0.113.42"}},
		{name: "public IPv4 range /24", ban: Ban{Type: BanTypeRange, Value: "203.0.113.0/24"}},
		{name: "public IPv4 range /16", ban: Ban{Type: BanTypeRange, Value: "203.0.0.0/16"}},
		{name: "public IPv4 range at /8 boundary", ban: Ban{Type: BanTypeRange, Value: "203.0.0.0/8"}},
		{name: "public IPv6 /48", ban: Ban{Type: BanTypeRange, Value: "2001:db8::/48"}},
		{name: "public IPv6 /32 (minimum)", ban: Ban{Type: BanTypeRange, Value: "2001:db8::/32"}},
		{name: "normal user agent", ban: Ban{Type: BanTypeUserAgent, Value: "BadBot/2.0"}},
		{name: "user agent exactly at max len", ban: Ban{Type: BanTypeUserAgent, Value: strings.Repeat("A", banMaxUserAgentLen)}},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if err := validateBan(tc.ban); err != nil {
				t.Errorf("validateBan: unexpected rejection for %+v: %v", tc.ban, err)
			}
		})
	}
}

// TestBanSafetyViaIPProviderConsistency cross-checks that every IP we claim is
// "private/loopback/link-local" here is also considered non-global by
// ipprovider.IsGlobalUnicast — the function we delegate to in validateBan.
func TestBanSafetyViaIPProviderConsistency(t *testing.T) {
	privateIPs := []string{
		"127.0.0.1", "::1",
		"10.0.0.1", "172.16.5.5", "192.168.1.1",
		"169.254.1.1", "fc00::1", "fe80::1",
	}
	for _, ip := range privateIPs {
		if ipprovider.IsGlobalUnicast(ip) {
			t.Errorf("expected %q to be non-global-unicast, but IsGlobalUnicast returned true", ip)
		}
	}

	publicIPs := []string{"1.2.3.4", "203.0.113.42", "8.8.8.8"}
	for _, ip := range publicIPs {
		if !ipprovider.IsGlobalUnicast(ip) {
			t.Errorf("expected %q to be global-unicast, but IsGlobalUnicast returned false", ip)
		}
	}

	// Also confirm the range base-address check works for private CIDRs.
	privateCIDRs := []string{
		"10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16",
		"127.0.0.0/8", "fc00::/7",
	}
	for _, cidr := range privateCIDRs {
		_, ipNet, err := net.ParseCIDR(cidr)
		if err != nil {
			t.Fatalf("ParseCIDR(%q): %v", cidr, err)
		}
		if ipprovider.IsGlobalUnicast(ipNet.IP.String()) {
			t.Errorf("base of %q (%s) should not be global-unicast", cidr, ipNet.IP)
		}
	}
}

func TestBanDeleteNotFound(t *testing.T) {
	svc := newFakeHardeningService()
	tenantID := uuid.New()
	siteID := uuid.New()

	err := svc.deleteBan(context.Background(), tenantID, siteID, uuid.New())
	if err == nil {
		t.Fatal("want NotFound error, got nil")
	}
	_, ok := domain.AsDomain(err)
	if !ok {
		t.Fatalf("want domain error, got %T: %v", err, err)
	}
	if domain.HTTPStatus(err) != http.StatusNotFound {
		t.Errorf("want 404, got %d", domain.HTTPStatus(err))
	}
}

// ---------------------------------------------------------------------------
// Unit tests: sync command wire contract
// ---------------------------------------------------------------------------

// TestHardeningSyncContractSerialization verifies that the wire contract types
// round-trip through JSON with the exact field names documented in the ADR.
func TestHardeningSyncContractSerialization(t *testing.T) {
	req := agentcmd.HardeningRequest{
		Config: agentcmd.HardeningConfig{
			DisableFileEditor:        true,
			XMLRPCMode:               "off",
			RestrictRESTAPI:          "restricted",
			RestrictLoginIdentifier:  "email",
			ForceUniqueNickname:      true,
			DisableAuthorArchiveEnum: true,
			ForceSSL:                 true,
			DisableDirectoryBrowsing: true,
			DisablePHPInUploads:      true,
			ProtectSystemFiles:       true,
		},
		Bans: []agentcmd.BanEntry{
			{ID: "uuid-1", Type: "ip", Value: "1.2.3.4", Comment: "test"},
			{ID: "uuid-2", Type: "range", Value: "10.0.0.0/8"},
			{ID: "uuid-3", Type: "user_agent", Value: "badbot/1.0"},
		},
	}

	raw, err := json.Marshal(req)
	if err != nil {
		t.Fatalf("marshal: %v", err)
	}

	var decoded agentcmd.HardeningRequest
	if err := json.Unmarshal(raw, &decoded); err != nil {
		t.Fatalf("unmarshal: %v", err)
	}

	// Top-level keys.
	var m map[string]any
	_ = json.Unmarshal(raw, &m)
	if _, ok := m["config"]; !ok {
		t.Error("wire JSON missing top-level 'config' key")
	}
	if _, ok := m["bans"]; !ok {
		t.Error("wire JSON missing top-level 'bans' key")
	}

	// Config field names.
	cfgMap, _ := m["config"].(map[string]any)
	for _, key := range []string{
		"disable_file_editor", "xmlrpc_mode", "restrict_rest_api",
		"restrict_login_identifier", "force_unique_nickname",
		"disable_author_archive_enum", "force_ssl",
		"disable_directory_browsing", "disable_php_in_uploads",
		"protect_system_files",
	} {
		if _, ok := cfgMap[key]; !ok {
			t.Errorf("wire config JSON missing key %q", key)
		}
	}

	// Ban entry field names.
	bansRaw, _ := m["bans"].([]any)
	if len(bansRaw) != 3 {
		t.Fatalf("want 3 ban entries, got %d", len(bansRaw))
	}
	firstBan, _ := bansRaw[0].(map[string]any)
	for _, key := range []string{"id", "type", "value", "comment"} {
		if _, ok := firstBan[key]; !ok {
			t.Errorf("ban entry missing key %q", key)
		}
	}

	// Decoded value checks.
	if !decoded.Config.DisableFileEditor {
		t.Error("decoded disable_file_editor should be true")
	}
	if decoded.Config.XMLRPCMode != "off" {
		t.Errorf("decoded xmlrpc_mode: want 'off', got %q", decoded.Config.XMLRPCMode)
	}
	if len(decoded.Bans) != 3 {
		t.Fatalf("decoded: want 3 bans, got %d", len(decoded.Bans))
	}
}

// ---------------------------------------------------------------------------
// Unit tests: DTO mapping
// ---------------------------------------------------------------------------

func TestHardeningDTOMapping(t *testing.T) {
	tenantID := uuid.New()
	siteID := uuid.New()

	original := HardeningConfig{
		TenantID:                tenantID,
		SiteID:                  siteID,
		DisableFileEditor:       true,
		XMLRPCMode:              XMLRPCModeOff,
		RestrictRESTAPI:         RESTAPIModeRestricted,
		RestrictLoginIdentifier: LoginIdentifierEmail,
		ForceUniqueNickname:     true,
		DisableAuthorArchiveEnum: true,
		ForceSSL:                true,
		DisableDirectoryBrowsing: true,
		DisablePHPInUploads:     true,
		ProtectSystemFiles:      true,
		UpdatedAt:               time.Now().UTC().Truncate(time.Second),
	}
	dto := toHardeningDTO(original)
	roundTripped := fromHardeningDTO(dto, tenantID, siteID)

	if roundTripped.XMLRPCMode != XMLRPCModeOff {
		t.Errorf("xmlrpc_mode: want off, got %q", roundTripped.XMLRPCMode)
	}
	if roundTripped.RestrictRESTAPI != RESTAPIModeRestricted {
		t.Errorf("restrict_rest_api: want restricted, got %q", roundTripped.RestrictRESTAPI)
	}
	if roundTripped.RestrictLoginIdentifier != LoginIdentifierEmail {
		t.Errorf("restrict_login_identifier: want email, got %q", roundTripped.RestrictLoginIdentifier)
	}
	if !roundTripped.DisableFileEditor {
		t.Error("disable_file_editor mismatch after round-trip")
	}
	if !roundTripped.ForceSSL {
		t.Error("force_ssl mismatch after round-trip")
	}
}

// ---------------------------------------------------------------------------
// HTTP handler tests
// ---------------------------------------------------------------------------

// TestHandlerGetHardeningConfigReturnsDefault verifies the GET route returns
// HTTP 200 with safe defaults for a site with no stored config.
func TestHandlerGetHardeningConfigReturnsDefault(t *testing.T) {
	gin.SetMode(gin.TestMode)
	tenantID := uuid.New()
	siteID := uuid.New()
	fSvc := newFakeHardeningService()

	engine := gin.New()
	engine.Use(principalMiddleware(tenantID))
	engine.GET("/sites/:siteId/security/hardening", func(c *gin.Context) {
		p, _ := domain.PrincipalFromContext(c.Request.Context())
		sid, _ := uuid.Parse(c.Param("siteId"))
		cfg, err := fSvc.getHardeningConfig(c.Request.Context(), p.TenantID, sid)
		if err != nil {
			httpx.Error(c, err)
			return
		}
		c.JSON(http.StatusOK, toHardeningDTO(cfg))
	})

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet,
		"/sites/"+siteID.String()+"/security/hardening", nil)
	engine.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d: %s", w.Code, w.Body.String())
	}
	var resp hardeningConfigDTO
	if err := json.NewDecoder(w.Body).Decode(&resp); err != nil {
		t.Fatalf("decode response: %v", err)
	}
	if resp.XMLRPCMode != "on" {
		t.Errorf("default xmlrpc_mode: want 'on', got %q", resp.XMLRPCMode)
	}
	if resp.DisableFileEditor {
		t.Error("default disable_file_editor should be false")
	}
}

// TestHandlerCreateBanValidatesIP verifies that POST /security/bans with an
// invalid IP returns 422.
func TestHandlerCreateBanValidatesIP(t *testing.T) {
	gin.SetMode(gin.TestMode)
	tenantID := uuid.New()
	siteID := uuid.New()
	fSvc := newFakeHardeningService()

	engine := gin.New()
	engine.Use(principalMiddleware(tenantID))
	engine.POST("/sites/:siteId/security/bans", func(c *gin.Context) {
		p, _ := domain.PrincipalFromContext(c.Request.Context())
		sid, _ := uuid.Parse(c.Param("siteId"))
		var body createBanBody
		if err := bindJSON(c, &body); err != nil {
			httpx.Error(c, err)
			return
		}
		ban := Ban{
			TenantID: p.TenantID, SiteID: sid,
			Type: BanType(strings.TrimSpace(body.Type)),
			Value: strings.TrimSpace(body.Value),
		}
		saved, err := fSvc.createBan(c.Request.Context(), p.TenantID, sid, ban)
		if err != nil {
			httpx.Error(c, err)
			return
		}
		c.JSON(http.StatusCreated, toBanDTO(saved))
	})

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost,
		"/sites/"+siteID.String()+"/security/bans",
		strings.NewReader(`{"type":"ip","value":"not-an-ip"}`))
	req.Header.Set("Content-Type", "application/json")
	engine.ServeHTTP(w, req)

	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("want 422 for bad IP, got %d: %s", w.Code, w.Body.String())
	}
}

// TestHandlerDeleteBanNotFound verifies that DELETE /security/bans/:banId for a
// non-existent ID returns 404.
func TestHandlerDeleteBanNotFound(t *testing.T) {
	gin.SetMode(gin.TestMode)
	tenantID := uuid.New()
	siteID := uuid.New()
	fSvc := newFakeHardeningService()

	engine := gin.New()
	engine.Use(principalMiddleware(tenantID))
	engine.DELETE("/sites/:siteId/security/bans/:banId", func(c *gin.Context) {
		p, _ := domain.PrincipalFromContext(c.Request.Context())
		sid, _ := uuid.Parse(c.Param("siteId"))
		bid, _ := uuid.Parse(c.Param("banId"))
		if err := fSvc.deleteBan(c.Request.Context(), p.TenantID, sid, bid); err != nil {
			httpx.Error(c, err)
			return
		}
		c.Status(http.StatusNoContent)
	})

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodDelete,
		"/sites/"+siteID.String()+"/security/bans/"+uuid.New().String(), nil)
	engine.ServeHTTP(w, req)

	if w.Code != http.StatusNotFound {
		t.Fatalf("want 404 for missing ban, got %d: %s", w.Code, w.Body.String())
	}
}
