package auth

import (
	"time"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/authz"
)

// User is a human principal. PasswordHash is empty for OIDC-only users; the
// hash is never serialized to the API.
type User struct {
	ID           uuid.UUID
	Email        string
	PasswordHash string
	OIDCSubject  string
	OIDCIssuer   string
	Name         string
	Status       string // 'active' | 'pending' | 'disabled' (ADR-045 Phase 3)
	IsSuperadmin bool   // instance-level; written only by boot seeder
	CreatedAt    time.Time
	UpdatedAt    time.Time
	LastLoginAt  *time.Time
}

// Membership binds a user to a tenant with a role.
type Membership struct {
	UserID    uuid.UUID
	TenantID  uuid.UUID
	Role      authz.Role
	CreatedAt time.Time
	UpdatedAt time.Time
}
