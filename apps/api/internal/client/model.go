// Package client implements the agency clients domain: tenant-level client
// records that group WordPress sites under customer entities. Every query is
// tenant-scoped both explicitly (tenant_id in WHERE/VALUES) and by Postgres
// RLS (app.tenant_id policy — m63 migration), giving defense-in-depth.
package client

import (
	"time"

	"github.com/google/uuid"
)

// Client is a tenant-level agency client record.
type Client struct {
	ID           uuid.UUID
	TenantID     uuid.UUID
	Name         string
	ContactEmail *string
	Company      *string
	Phone        *string
	Notes        *string
	Color        *string
	LogoURL      *string
	// Timezone is the client's IANA timezone governing report send time
	// (decision 6; m64). Defaults to "UTC".
	Timezone   string
	ArchivedAt *time.Time
	CreatedAt  time.Time
	UpdatedAt  time.Time
	// SiteCount is the number of non-archived sites currently assigned to this
	// client. Populated by the repo (subquery / JOIN), not stored on the row.
	SiteCount int64
}

// CreateInput is the validated input for creating a client.
type CreateInput struct {
	TenantID     uuid.UUID
	Name         string
	ContactEmail *string
	Company      *string
	Phone        *string
	Notes        *string
	Color        *string
	LogoURL      *string
	// Timezone is the client's IANA timezone (default "UTC").
	Timezone *string
}

// UpdateInput is the validated partial-update input for a client. A nil field
// means "leave unchanged".
type UpdateInput struct {
	TenantID     uuid.UUID
	ID           uuid.UUID
	Name         *string
	ContactEmail *string
	Company      *string
	Phone        *string
	Notes        *string
	Color        *string
	LogoURL      *string
	// Timezone, when non-nil, updates the client's IANA timezone.
	Timezone *string
}

// AssignInput bulk-assigns sites to a client (or unassigns when ClientID is nil).
type AssignInput struct {
	TenantID uuid.UUID
	// ClientID is nil to unassign the sites.
	ClientID *uuid.UUID
	SiteIDs  []uuid.UUID
}

// AssignResult is the outcome of a bulk site assignment.
type AssignResult struct {
	Updated int64
}
