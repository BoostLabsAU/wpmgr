// Package security implements the S2 Login Protection + IP store domain and the
// Phase 1 hardening config + ban list (ADR-057) on the control-plane side.
//
// It stores per-site login-protection config and hardening config, pushes them
// to the agent via signed commands, ingests the agent's login-event batch
// (POST /agent/v1/security/login-events), and exposes unblock-IP and ban CRUD.
package security

import (
	"time"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/agentcmd"
)

// ---------------------------------------------------------------------------
// Phase 1 — hardening config
// ---------------------------------------------------------------------------

// XMLRPCMode is the three-state XML-RPC control value.
type XMLRPCMode string

const (
	XMLRPCModeOn      XMLRPCMode = "on"
	XMLRPCModeOff     XMLRPCMode = "off"
	XMLRPCModeLimited XMLRPCMode = "limited"
)

// RESTAPIMode is the two-state REST API restriction value.
type RESTAPIMode string

const (
	RESTAPIModeDefault    RESTAPIMode = "default"
	RESTAPIModeRestricted RESTAPIMode = "restricted"
)

// LoginIdentifierMode controls which credential forms the WP login accepts.
type LoginIdentifierMode string

const (
	LoginIdentifierUsername LoginIdentifierMode = "username"
	LoginIdentifierEmail    LoginIdentifierMode = "email"
	LoginIdentifierBoth     LoginIdentifierMode = "both"
)

// HardeningConfig is the per-site hardening configuration stored in
// site_security_hardening_config and pushed to the agent via
// sync_security_hardening (ADR-057).
type HardeningConfig struct {
	TenantID                 uuid.UUID
	SiteID                   uuid.UUID
	DisableFileEditor        bool
	XMLRPCMode               XMLRPCMode
	RestrictRESTAPI          RESTAPIMode
	RestrictLoginIdentifier  LoginIdentifierMode
	ForceUniqueNickname      bool
	DisableAuthorArchiveEnum bool
	ForceSSL                 bool
	DisableDirectoryBrowsing bool
	DisablePHPInUploads      bool
	ProtectSystemFiles       bool
	UpdatedAt                time.Time
	ActorType                string
	ActorID                  string
}

// DefaultHardeningConfig returns the safe-default config (everything OFF,
// permissive enum values) for a site that has no stored row yet.
func DefaultHardeningConfig(tenantID, siteID uuid.UUID) HardeningConfig {
	return HardeningConfig{
		TenantID:                tenantID,
		SiteID:                  siteID,
		DisableFileEditor:       false,
		XMLRPCMode:              XMLRPCModeOn,
		RestrictRESTAPI:         RESTAPIModeDefault,
		RestrictLoginIdentifier: LoginIdentifierBoth,
		ForceUniqueNickname:     false,
		DisableAuthorArchiveEnum: false,
		ForceSSL:                false,
		DisableDirectoryBrowsing: false,
		DisablePHPInUploads:     false,
		ProtectSystemFiles:      false,
	}
}

// ---------------------------------------------------------------------------
// Phase 1 — ban list
// ---------------------------------------------------------------------------

// BanType is the kind of a ban entry.
type BanType string

const (
	BanTypeIP        BanType = "ip"
	BanTypeRange     BanType = "range"
	BanTypeUserAgent BanType = "user_agent"
)

// Ban is one durable ban entry stored in site_security_bans.
type Ban struct {
	ID        uuid.UUID
	TenantID  uuid.UUID
	SiteID    uuid.UUID
	Type      BanType
	Value     string
	Comment   string
	ActorType string
	ActorID   string
	CreatedAt time.Time
}

// SecurityConfig is the per-site login-protection configuration stored in
// site_security_config and pushed to the agent via the sync_security_config
// command.
type SecurityConfig struct {
	TenantID   uuid.UUID
	SiteID     uuid.UUID
	Mode       string                      // "disabled" | "audit" | "protect"
	Thresholds agentcmd.SecurityThresholds // inline struct so the wire contract is shared
	IPHeader   string
	AllowCIDRs []string
	DenyCIDRs  []string
	UpdatedAt  time.Time
}

// LoginEventStatus is the agent's numeric status column.
type LoginEventStatus int16

const (
	StatusFailure LoginEventStatus = 1
	StatusSuccess LoginEventStatus = 2
	StatusBlocked LoginEventStatus = 3
)

// LoginEvent is one stored login-attempt row from the agent.
type LoginEvent struct {
	ID           int64
	TenantID     uuid.UUID
	SiteID       uuid.UUID
	AgentEventID int64
	IP           string
	Status       LoginEventStatus
	Category     string
	Username     string
	RequestID    string
	OccurredAt   time.Time
	IngestedAt   time.Time
}

// ---------------------------------------------------------------------------
// Phase 3 — site-user 2FA + password policy (ADR-059)
// ---------------------------------------------------------------------------

// SiteSecurityPolicy is the per-site user auth policy stored in
// site_security_policy and pushed to the agent via sync_security_policy.
// All fields default to the OFF/safe value (zero values are safe defaults).
type SiteSecurityPolicy struct {
	TenantID uuid.UUID
	SiteID   uuid.UUID

	// 2FA
	TwoFactorEnabled            bool
	TwoFactorMethods            []string
	TwoFactorRequiredRoles      []string
	TwoFactorGraceLogins        int
	TwoFactorRememberDeviceDays int
	BlockXMLRPCFor2FAUsers      bool

	// Password
	PasswordMinZxcvbnScore   int
	PasswordMinZxcvbnRoles   []string
	PasswordBlockCompromised bool
	PasswordReuseBlockCount  int
	PasswordMaxAgeDays       int
	PasswordExpiryRoles      []string

	// Hide-backend
	HideBackendEnabled  bool
	HideBackendSlug     string
	HideBackendRedirect string

	// Audit
	UpdatedAt time.Time
	ActorType string
	ActorID   string
}

// DefaultSiteSecurityPolicy returns the safe default policy for a site that
// has no stored row yet. Everything is OFF; no 2FA, no password requirements,
// no hide-backend.
func DefaultSiteSecurityPolicy(tenantID, siteID uuid.UUID) SiteSecurityPolicy {
	return SiteSecurityPolicy{
		TenantID:                    tenantID,
		SiteID:                      siteID,
		TwoFactorEnabled:            false,
		TwoFactorMethods:            []string{"totp", "email", "backup"},
		TwoFactorRequiredRoles:      []string{},
		TwoFactorGraceLogins:        3,
		TwoFactorRememberDeviceDays: 30,
		BlockXMLRPCFor2FAUsers:      true,
		PasswordMinZxcvbnScore:      0,
		PasswordMinZxcvbnRoles:      []string{},
		PasswordBlockCompromised:    false,
		PasswordReuseBlockCount:     0,
		PasswordMaxAgeDays:          0,
		PasswordExpiryRoles:         []string{},
		HideBackendEnabled:          false,
		HideBackendSlug:             "",
		HideBackendRedirect:         "",
	}
}

// PolicyGroup is one per-role policy override stored in
// site_security_policy_groups. Nullable fields use pointers; nil = inherit
// from the site-level policy.
type PolicyGroup struct {
	ID               uuid.UUID
	TenantID         uuid.UUID
	SiteID           uuid.UUID
	Role             string
	Require2FA       *bool
	AllowedMethods   []string
	MinZxcvbnScore   *int
	BlockCompromised *bool
	MaxAgeDays       *int
	CreatedAt        time.Time
}
