package admin

import (
	"context"
	"log/slog"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
)

// VerificationResender is the narrow slice of auth.Service used by the admin
// domain to resend verification emails. Avoids importing the auth package.
type VerificationResender interface {
	ResendVerificationByID(ctx context.Context, userID uuid.UUID) error
}

// userStore is the persistence surface the admin Service needs. The concrete
// *Repo satisfies it; defining it as an interface lets the orphan-cleanup and
// guard logic be unit-tested against an in-memory fake (no DB).
type userStore interface {
	ListUsers(ctx context.Context, search string, limit, offset int32) ([]AdminUser, error)
	GetUser(ctx context.Context, id uuid.UUID) (AdminUser, error)
	DeleteUser(ctx context.Context, id uuid.UUID) error
	SetStatus(ctx context.Context, id uuid.UUID, status string) (AdminUser, error)
	Stats(ctx context.Context) (AdminStats, error)
	SoleTenants(ctx context.Context, userID uuid.UUID) ([]OrphanTenant, error)
	DeleteEmptyTenant(ctx context.Context, tenantID uuid.UUID) (bool, error)
}

// Service implements the superadmin business logic.
type Service struct {
	repo     userStore
	resender VerificationResender
}

// NewService builds an admin Service.
func NewService(repo *Repo, resender VerificationResender) *Service {
	return &Service{repo: repo, resender: resender}
}

// ListUsers returns users, optionally filtered by search string. An empty search
// matches all users. limit is clamped to [1,200]; offset to [0,∞).
func (s *Service) ListUsers(ctx context.Context, search string, limit, offset int32) ([]AdminUser, error) {
	if limit <= 0 || limit > 200 {
		limit = 50
	}
	if offset < 0 {
		offset = 0
	}
	return s.repo.ListUsers(ctx, search, limit, offset)
}

// KeptOrg names an orphaned org that was NOT auto-deleted because it still owns
// sites — surfaced so the operator can reassign or remove it deliberately.
type KeptOrg struct {
	ID        uuid.UUID `json:"id"`
	Name      string    `json:"name"`
	SiteCount int64     `json:"site_count"`
}

// DeleteUserResult summarizes the orphaned-org cleanup that accompanied a user
// deletion: how many now-empty orgs were removed, and which orgs were kept
// because they still own sites.
type DeleteUserResult struct {
	DeletedOrgs int       `json:"deleted_orgs"`
	KeptOrgs    []KeptOrg `json:"kept_orgs_with_sites"`
}

// DeleteUser permanently deletes targetID and cleans up the orgs it orphans.
// Guards: cannot delete self, cannot delete another superadmin. Orphan handling:
// before deleting, it captures the orgs where the user is the SOLE member (those
// the delete would orphan, since the membership FK cascades). After the delete,
// each now-empty org is removed; an orphaned org that still owns sites is left
// intact and reported in KeptOrgs so an operator can reassign or remove it. The
// user delete is the authoritative side effect — orphan cleanup is best-effort
// and never fails the operation (a leftover empty org is harmless).
func (s *Service) DeleteUser(ctx context.Context, actorID, targetID uuid.UUID) (DeleteUserResult, error) {
	var res DeleteUserResult
	if actorID == targetID {
		return res, domain.Forbidden("cannot_delete_self", "you cannot delete your own account")
	}
	target, err := s.repo.GetUser(ctx, targetID)
	if err != nil {
		return res, err
	}
	if target.IsSuperadmin {
		return res, domain.Forbidden("cannot_delete_superadmin", "superadmin accounts cannot be deleted")
	}

	// Capture orgs orphaned by this delete BEFORE removing the user — afterwards
	// the membership rows are gone and the sole-member relationship is lost.
	orphans, oerr := s.repo.SoleTenants(ctx, targetID)
	if oerr != nil {
		// Non-fatal: proceed with the delete, just skip cleanup.
		slog.Warn("admin: failed to inspect orphaned orgs before delete; skipping cleanup",
			"target_user", targetID, "error", oerr)
		orphans = nil
	}

	if err := s.repo.DeleteUser(ctx, targetID); err != nil {
		return res, err
	}

	// IMPORTANT: this cleanup is safe to delete tenants ONLY because `orphans` is
	// scoped to tenants the just-deleted user was the SOLE member of — never a
	// blanket "all empty tenants" sweep. The org-create paths (auth/register.go,
	// org create) are non-transactional, so a freshly created tenant briefly
	// exists with zero memberships and zero sites; a global sweep over the same
	// empty-predicate would race-delete half-provisioned orgs. DeleteEmptyTenant
	// also re-checks emptiness in-statement as a second guard.
	for _, o := range orphans {
		if o.SiteCount > 0 {
			// Org still owns sites — do not auto-delete; surface for operator action.
			res.KeptOrgs = append(res.KeptOrgs, KeptOrg{ID: o.ID, Name: o.Name, SiteCount: o.SiteCount})
			slog.Warn("admin: orphaned org kept (still owns sites)",
				"org", o.ID, "org_name", o.Name, "site_count", o.SiteCount, "deleted_user", targetID)
			continue
		}
		deleted, derr := s.repo.DeleteEmptyTenant(ctx, o.ID)
		if derr != nil {
			slog.Warn("admin: failed to delete orphaned empty org", "org", o.ID, "error", derr)
			continue
		}
		if deleted {
			res.DeletedOrgs++
		}
	}
	return res, nil
}

// SetStatus changes a user's status. Guards: status must be "active" or
// "disabled", cannot modify self, cannot modify another superadmin.
func (s *Service) SetStatus(ctx context.Context, actorID, targetID uuid.UUID, status string) (AdminUser, error) {
	if status != "active" && status != "disabled" {
		return AdminUser{}, domain.Validation("invalid_status", `status must be "active" or "disabled"`)
	}
	if actorID == targetID {
		return AdminUser{}, domain.Forbidden("cannot_modify_self", "you cannot change your own status")
	}
	target, err := s.repo.GetUser(ctx, targetID)
	if err != nil {
		return AdminUser{}, err
	}
	if target.IsSuperadmin {
		return AdminUser{}, domain.Forbidden("cannot_modify_superadmin", "superadmin accounts cannot be disabled")
	}
	return s.repo.SetStatus(ctx, targetID, status)
}

// ResendVerification re-sends the email verification link for targetID.
// Only acts on users whose status is "pending".
func (s *Service) ResendVerification(ctx context.Context, targetID uuid.UUID) error {
	return s.resender.ResendVerificationByID(ctx, targetID)
}

// Stats returns instance-wide counts for users, orgs, and sites.
func (s *Service) Stats(ctx context.Context) (AdminStats, error) {
	return s.repo.Stats(ctx)
}
