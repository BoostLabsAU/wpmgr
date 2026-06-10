package client

import (
	"context"
	"net"
	"net/url"
	"regexp"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
)

const maxLogoURLLen = 2048

const (
	maxSiteAssignBatch = 500
)

var colorHex = regexp.MustCompile(`^#[0-9a-fA-F]{6}$`)

// Service holds client business logic.
type Service struct {
	repo Repo
}

// NewService builds a client Service.
func NewService(repo Repo) *Service {
	return &Service{repo: repo}
}

// List returns the tenant's clients, optionally including archived ones.
func (s *Service) List(ctx context.Context, tenantID uuid.UUID, includeArchived bool) ([]Client, error) {
	if tenantID == uuid.Nil {
		return nil, domain.Forbidden("tenant_required", "a tenant context is required")
	}
	return s.repo.List(ctx, tenantID, includeArchived)
}

// Get returns a single tenant-scoped client by ID.
func (s *Service) Get(ctx context.Context, tenantID, id uuid.UUID) (Client, error) {
	if tenantID == uuid.Nil {
		return Client{}, domain.Forbidden("tenant_required", "a tenant context is required")
	}
	return s.repo.Get(ctx, tenantID, id)
}

// Create validates and creates a new client.
func (s *Service) Create(ctx context.Context, in CreateInput) (Client, error) {
	if in.TenantID == uuid.Nil {
		return Client{}, domain.Forbidden("tenant_required", "a tenant context is required")
	}
	if err := validateClientFields(in.Name, in.ContactEmail, in.Color, in.Timezone, in.LogoURL); err != nil {
		return Client{}, err
	}
	return s.repo.Create(ctx, in)
}

// Update partially updates a client. Only non-nil fields are changed.
func (s *Service) Update(ctx context.Context, in UpdateInput) (Client, error) {
	if in.TenantID == uuid.Nil {
		return Client{}, domain.Forbidden("tenant_required", "a tenant context is required")
	}
	if in.Name != nil {
		if err := validateClientFields(*in.Name, in.ContactEmail, in.Color, in.Timezone, in.LogoURL); err != nil {
			return Client{}, err
		}
	} else {
		if err := validateClientFields("", in.ContactEmail, in.Color, in.Timezone, in.LogoURL); err != nil {
			return Client{}, err
		}
	}
	return s.repo.Update(ctx, in)
}

// Archive soft-deletes a client by setting archived_at.
func (s *Service) Archive(ctx context.Context, tenantID, id uuid.UUID) (Client, error) {
	if tenantID == uuid.Nil {
		return Client{}, domain.Forbidden("tenant_required", "a tenant context is required")
	}
	return s.repo.Archive(ctx, tenantID, id)
}

// Delete permanently removes a client. ON DELETE SET NULL on sites.client_id
// handles site unassignment automatically in the same DB statement.
func (s *Service) Delete(ctx context.Context, tenantID, id uuid.UUID) error {
	if tenantID == uuid.Nil {
		return domain.Forbidden("tenant_required", "a tenant context is required")
	}
	n, err := s.repo.Delete(ctx, tenantID, id)
	if err != nil {
		return err
	}
	if n == 0 {
		return domain.NotFound("client_not_found", "client not found")
	}
	return nil
}

// CountSites returns the number of non-archived sites assigned to a client.
func (s *Service) CountSites(ctx context.Context, tenantID, clientID uuid.UUID) (int64, error) {
	if tenantID == uuid.Nil {
		return 0, domain.Forbidden("tenant_required", "a tenant context is required")
	}
	return s.repo.CountSites(ctx, tenantID, clientID)
}

// AssignSites bulk-assigns (or unassigns when clientID is nil) sites to a
// client. Caps the batch at maxSiteAssignBatch to bound the UPDATE.
func (s *Service) AssignSites(ctx context.Context, in AssignInput) (AssignResult, error) {
	if in.TenantID == uuid.Nil {
		return AssignResult{}, domain.Forbidden("tenant_required", "a tenant context is required")
	}
	if len(in.SiteIDs) == 0 {
		return AssignResult{}, domain.Validation("site_ids_required", "site_ids must not be empty")
	}
	if len(in.SiteIDs) > maxSiteAssignBatch {
		return AssignResult{}, domain.Validation("too_many_sites",
			"site_ids must contain at most 500 entries per request")
	}
	n, err := s.repo.AssignSites(ctx, in)
	if err != nil {
		return AssignResult{}, err
	}
	return AssignResult{Updated: n}, nil
}

// ---------------------------------------------------------------------------
// validation helpers
// ---------------------------------------------------------------------------

func validateClientFields(name string, email, color, timezone, logoURL *string) error {
	trimmed := strings.TrimSpace(name)
	if name != "" {
		if trimmed == "" {
			return domain.Validation("name_required", "name must not be blank")
		}
		if utf8.RuneCountInString(trimmed) > 200 {
			return domain.Validation("name_too_long", "name must be 200 characters or fewer")
		}
	}
	if email != nil && *email != "" {
		if !strings.Contains(*email, "@") {
			return domain.Validation("invalid_email", "contact_email must be a valid email address")
		}
	}
	if color != nil && *color != "" {
		if !colorHex.MatchString(*color) {
			return domain.Validation("invalid_color", "color must be a 6-digit hex code (e.g. #1a2b3c)")
		}
	}
	if timezone != nil && *timezone != "" {
		if _, err := time.LoadLocation(*timezone); err != nil {
			return domain.Validation("invalid_timezone", "timezone must be a valid IANA timezone name (e.g. America/New_York)")
		}
	}
	// FIX-1 defense-in-depth: validate logo_url at write time so invalid URLs
	// never reach the fetch path. The SSRF-safe httpclient is the real boundary;
	// this is belt-and-braces. Checks: non-empty → parseable, https-only scheme,
	// host must not be a literal IP, length cap.
	if logoURL != nil && *logoURL != "" {
		if len(*logoURL) > maxLogoURLLen {
			return domain.Validation("logo_url_too_long", "logo_url must be 2048 characters or fewer")
		}
		u, err := url.Parse(*logoURL)
		if err != nil {
			return domain.Validation("invalid_logo_url", "logo_url must be a valid URL")
		}
		if strings.ToLower(u.Scheme) != "https" {
			return domain.Validation("invalid_logo_url", "logo_url scheme must be https")
		}
		// Reject literal IP hosts (e.g. https://10.0.0.1/...) — these bypass
		// the hostname-based SSRF check at DNS resolution time.
		host := u.Hostname()
		if host == "" {
			return domain.Validation("invalid_logo_url", "logo_url must include a hostname")
		}
		// net.ParseIP returns non-nil for a literal IP address.
		if isLiteralIP(host) {
			return domain.Validation("invalid_logo_url", "logo_url host must not be a literal IP address")
		}
	}
	return nil
}

// isLiteralIP reports whether host is a bare IP address (v4, v6, or
// IPv4-mapped v6 like ::ffff:127.0.0.1). url.Hostname() strips brackets from
// IPv6 literals, so the bare form is what arrives here. This write-time check
// is defense-in-depth only; the SSRF-safe httpclient dialer is the real
// boundary at fetch time.
func isLiteralIP(host string) bool {
	return net.ParseIP(host) != nil
}
