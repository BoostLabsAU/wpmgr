package client

// Member management handlers for the client portal (m66 Phase 3).
//
// Routes (mounted by RegisterMembers on the /clients group with RequireOrgScope):
//
//	GET    /clients/:clientId/members
//	POST   /clients/:clientId/members
//	DELETE /clients/:clientId/members/:userId
//	GET    /clients/:clientId/invitations
//	DELETE /clients/:clientId/invitations/:invitationId
//	POST   /clients/:clientId/invitations/:invitationId/regenerate

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"net/http"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgtype"

	"github.com/mosamlife/wpmgr/apps/api/internal/audit"
	"github.com/mosamlife/wpmgr/apps/api/internal/auth"
	"github.com/mosamlife/wpmgr/apps/api/internal/authz"
	"github.com/mosamlife/wpmgr/apps/api/internal/db"
	"github.com/mosamlife/wpmgr/apps/api/internal/db/sqlc"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/server/httpx"
)

// AuthIdentityService is the subset of auth.Repo needed for member management.
// auth.Repo satisfies this interface.
type AuthIdentityService interface {
	GetUsersByIDs(ctx context.Context, ids []uuid.UUID) ([]auth.UserBrief, error)
}

// InviteService creates tokenized client-portal invitations.
// invitation.Service satisfies this interface after CreateClientInvitation is
// added in invitation/service.go.
type InviteService interface {
	CreateClientInvitation(ctx context.Context, tenantID, clientID, actorID uuid.UUID, email string) (acceptLink string, invitationID uuid.UUID, expiresAt time.Time, err error)
}

// MemberHandler is wired into the client Handler via RegisterMembers. It keeps
// the member-management surface separate from the core client CRUD to avoid
// making the base Handler struct aware of auth and invitation dependencies.
type MemberHandler struct {
	pool    *db.Pool
	authSvc AuthIdentityService
	invSvc  InviteService
	audit   *audit.Recorder
	baseURL string
}

// memberDTO is the wire shape for a single member.
type memberDTO struct {
	UserID    string `json:"user_id"`
	Email     string `json:"email"`
	Name      string `json:"name"`
	CreatedAt string `json:"created_at"`
}

// inviteResultDTO is the wire shape for addClientMember response.
type inviteResultDTO struct {
	Email        string  `json:"email"`
	Invited      bool    `json:"invited"`
	UserID       *string `json:"user_id,omitempty"`
	CreatedAt    *string `json:"created_at,omitempty"`
	AcceptLink   string  `json:"accept_link"`
	InvitationID *string `json:"invitation_id,omitempty"`
	ExpiresAt    *string `json:"expires_at,omitempty"`
}

// invitationDTO is the wire shape for a client invitation in the list.
type invitationDTO struct {
	ID        string `json:"id"`
	Email     string `json:"email"`
	CreatedAt string `json:"created_at"`
	ExpiresAt string `json:"expires_at"`
	Status    string `json:"status"`
}

// addMemberBody is the request body for POST /clients/:clientId/members.
type addMemberBody struct {
	Email string `json:"email"`
}

// NewMemberHandler builds a MemberHandler. pool and authSvc are required;
// invSvc may be nil (invitation functionality returns 503 when nil).
func NewMemberHandler(pool *db.Pool, authSvc AuthIdentityService, invSvc InviteService, rec *audit.Recorder, baseURL string) *MemberHandler {
	return &MemberHandler{pool: pool, authSvc: authSvc, invSvc: invSvc, audit: rec, baseURL: baseURL}
}

// RegisterMembers mounts the member management routes on the given router group
// (the /clients group that already carries RequireOrgScope). Called from
// Handler.Register when a MemberHandler has been wired.
func (mh *MemberHandler) RegisterMembers(g gin.IRouter) {
	g.GET("/:clientId/members", authz.RequirePermission(authz.PermClientRead), mh.listMembers)
	g.POST("/:clientId/members", authz.RequirePermission(authz.PermClientManage), mh.addMember)
	g.DELETE("/:clientId/members/:userId", authz.RequirePermission(authz.PermClientManage), mh.removeMember)
	g.GET("/:clientId/invitations", authz.RequirePermission(authz.PermClientRead), mh.listInvitations)
	g.DELETE("/:clientId/invitations/:invitationId", authz.RequirePermission(authz.PermClientManage), mh.revokeInvitation)
	g.POST("/:clientId/invitations/:invitationId/regenerate", authz.RequirePermission(authz.PermClientManage), mh.regenerateInvitation)
}

func (mh *MemberHandler) listMembers(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}
	clientID, err := uuid.Parse(c.Param("clientId"))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_client_id", "clientId is not a valid UUID"))
		return
	}

	var rows []sqlc.ListMembersForClientRow
	err = mh.pool.InTenantTx(c.Request.Context(), p.TenantID, func(tx pgx.Tx) error {
		var qerr error
		rows, qerr = sqlc.New(tx).ListMembersForClient(c.Request.Context(), sqlc.ListMembersForClientParams{
			ClientID: clientID,
			TenantID: p.TenantID,
		})
		return qerr
	})
	if err != nil {
		httpx.Error(c, domain.Internal("member_list_failed", "failed to list members").WithCause(err))
		return
	}

	// Batch-resolve identities from the users table (no RLS on users).
	ids := make([]uuid.UUID, 0, len(rows))
	for _, r := range rows {
		ids = append(ids, r.UserID)
	}
	briefs, _ := mh.authSvc.GetUsersByIDs(c.Request.Context(), ids)
	byID := make(map[uuid.UUID]auth.UserBrief, len(briefs))
	for _, b := range briefs {
		byID[b.ID] = b
	}

	items := make([]memberDTO, 0, len(rows))
	for _, r := range rows {
		d := memberDTO{
			UserID:    r.UserID.String(),
			CreatedAt: r.CreatedAt.UTC().Format("2006-01-02T15:04:05Z"),
		}
		if b, ok2 := byID[r.UserID]; ok2 {
			d.Email = b.Email
			d.Name = b.Name
		}
		items = append(items, d)
	}
	c.JSON(http.StatusOK, gin.H{"items": items})
}

func (mh *MemberHandler) addMember(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}
	clientID, err := uuid.Parse(c.Param("clientId"))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_client_id", "clientId is not a valid UUID"))
		return
	}
	var body addMemberBody
	if err := c.ShouldBindJSON(&body); err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "request body is not valid JSON"))
		return
	}
	if body.Email == "" {
		httpx.Error(c, domain.Validation("email_required", "email is required"))
		return
	}

	// Verify the client belongs to this tenant (RLS backs it, but an explicit
	// tenant-scoped load surfaces the right error code).
	clientErr := mh.pool.RunTenantTx(c.Request.Context(), p, func(tx pgx.Tx) error {
		_, qerr := sqlc.New(tx).GetClient(c.Request.Context(), sqlc.GetClientParams{
			ID:       clientID,
			TenantID: p.TenantID,
		})
		return qerr
	})
	if clientErr != nil {
		if e, ok2 := domain.AsDomain(clientErr); ok2 && e.Kind == domain.KindNotFound {
			httpx.Error(c, domain.NotFound("client_not_found", "client not found"))
			return
		}
		httpx.Error(c, clientErr)
		return
	}

	// Look up an existing user by email. The users table has no RLS so a bare
	// pool QueryRow is correct here.
	type existingUserInfo struct {
		ID        uuid.UUID
		Email     string
		Name      string
		CreatedAt time.Time
	}
	var existing *existingUserInfo

	var userID uuid.UUID
	var userName string
	var userCreatedAt time.Time
	lookupErr := mh.pool.QueryRow(c.Request.Context(),
		`SELECT id, name, created_at FROM users WHERE lower(email) = lower($1)`, body.Email,
	).Scan(&userID, &userName, &userCreatedAt)
	if lookupErr != nil && lookupErr != pgx.ErrNoRows {
		httpx.Error(c, domain.Internal("user_lookup_failed", "failed to look up user").WithCause(lookupErr))
		return
	}
	if lookupErr == nil {
		existing = &existingUserInfo{ID: userID, Email: body.Email, Name: userName, CreatedAt: userCreatedAt}
	}

	if existing != nil {
		// Known user: insert or no-op via ON CONFLICT DO NOTHING.
		var member sqlc.ClientMember
		err = mh.pool.InTenantTx(c.Request.Context(), p.TenantID, func(tx pgx.Tx) error {
			var qerr error
			member, qerr = sqlc.New(tx).CreateClientMember(c.Request.Context(), sqlc.CreateClientMemberParams{
				TenantID:  p.TenantID,
				ClientID:  clientID,
				UserID:    existing.ID,
				InvitedBy: pgtype.UUID{Bytes: p.UserID, Valid: p.UserID != uuid.Nil},
			})
			return qerr
		})
		if err != nil {
			httpx.Error(c, domain.Internal("member_add_failed", "failed to add member").WithCause(err))
			return
		}
		if member.ID == uuid.Nil {
			// ON CONFLICT DO NOTHING returned no row: already a member.
			httpx.Error(c, domain.Conflict("member_exists", "this user is already a portal member for this client"))
			return
		}
		mh.recordAudit(c, p.TenantID, "client_member.added", clientID.String(),
			map[string]any{"grantee_id": existing.ID.String(), "email": existing.Email})
		cat := existing.CreatedAt.UTC().Format("2006-01-02T15:04:05Z")
		uid := existing.ID.String()
		c.JSON(http.StatusCreated, inviteResultDTO{
			Email:      existing.Email,
			Invited:    false,
			UserID:     &uid,
			CreatedAt:  &cat,
			AcceptLink: mh.baseURL + "/portal",
		})
		return
	}

	// Unknown user: create an invitation with scope='client'.
	if mh.invSvc == nil {
		httpx.Error(c, domain.Unavailable("invitation_unavailable", "invitation service is not configured"))
		return
	}
	link, invID, expiresAt, invErr := mh.invSvc.CreateClientInvitation(c.Request.Context(), p.TenantID, clientID, p.UserID, body.Email)
	if invErr != nil {
		httpx.Error(c, invErr)
		return
	}
	mh.recordAudit(c, p.TenantID, "client_member.invited", clientID.String(),
		map[string]any{"email": body.Email, "invitation_id": invID.String()})
	invIDStr := invID.String()
	expStr := expiresAt.UTC().Format("2006-01-02T15:04:05Z")
	c.JSON(http.StatusCreated, inviteResultDTO{
		Email:        body.Email,
		Invited:      true,
		AcceptLink:   link,
		InvitationID: &invIDStr,
		ExpiresAt:    &expStr,
	})
}

func (mh *MemberHandler) removeMember(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}
	clientID, err := uuid.Parse(c.Param("clientId"))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_client_id", "clientId is not a valid UUID"))
		return
	}
	targetUserID, err := uuid.Parse(c.Param("userId"))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_user_id", "userId is not a valid UUID"))
		return
	}

	var affected int64
	err = mh.pool.InTenantTx(c.Request.Context(), p.TenantID, func(tx pgx.Tx) error {
		var qerr error
		affected, qerr = sqlc.New(tx).DeleteClientMember(c.Request.Context(), sqlc.DeleteClientMemberParams{
			ClientID: clientID,
			UserID:   targetUserID,
			TenantID: p.TenantID,
		})
		return qerr
	})
	if err != nil {
		httpx.Error(c, domain.Internal("member_remove_failed", "failed to remove member").WithCause(err))
		return
	}
	if affected == 0 {
		httpx.Error(c, domain.NotFound("member_not_found", "member not found"))
		return
	}
	mh.recordAudit(c, p.TenantID, "client_member.removed", clientID.String(),
		map[string]any{"grantee_id": targetUserID.String()})
	c.Status(http.StatusNoContent)
}

func (mh *MemberHandler) listInvitations(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}
	clientID, err := uuid.Parse(c.Param("clientId"))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_client_id", "clientId is not a valid UUID"))
		return
	}

	var rows []sqlc.Invitation
	err = mh.pool.InTenantTx(c.Request.Context(), p.TenantID, func(tx pgx.Tx) error {
		var qerr error
		rows, qerr = sqlc.New(tx).ListInvitationsForClient(c.Request.Context(), sqlc.ListInvitationsForClientParams{
			TenantID: p.TenantID,
			ClientID: pgtype.UUID{Bytes: clientID, Valid: true},
		})
		return qerr
	})
	if err != nil {
		httpx.Error(c, domain.Internal("invitation_list_failed", "failed to list invitations").WithCause(err))
		return
	}

	now := time.Now().UTC()
	items := make([]invitationDTO, 0, len(rows))
	for _, r := range rows {
		status := "pending"
		if r.AcceptedAt.Valid {
			status = "accepted"
		} else if r.RevokedAt.Valid {
			status = "revoked"
		} else if now.After(r.ExpiresAt) {
			status = "expired"
		}
		items = append(items, invitationDTO{
			ID:        r.ID.String(),
			Email:     r.Email,
			CreatedAt: r.CreatedAt.UTC().Format("2006-01-02T15:04:05Z"),
			ExpiresAt: r.ExpiresAt.UTC().Format("2006-01-02T15:04:05Z"),
			Status:    status,
		})
	}
	c.JSON(http.StatusOK, gin.H{"items": items})
}

func (mh *MemberHandler) revokeInvitation(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}
	clientID, err := uuid.Parse(c.Param("clientId"))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_client_id", "clientId is not a valid UUID"))
		return
	}
	invitationID, err := uuid.Parse(c.Param("invitationId"))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_invitation_id", "invitationId is not a valid UUID"))
		return
	}

	err = mh.pool.InTenantTx(c.Request.Context(), p.TenantID, func(tx pgx.Tx) error {
		inv, lerr := sqlc.New(tx).GetInvitationByID(c.Request.Context(), invitationID)
		if lerr != nil {
			if lerr == pgx.ErrNoRows {
				return domain.NotFound("invitation_not_found", "invitation not found")
			}
			return lerr
		}
		// Verify scope and client binding.
		if inv.Scope != "client" || !inv.ClientID.Valid || uuid.UUID(inv.ClientID.Bytes) != clientID {
			return domain.NotFound("invitation_not_found", "invitation not found")
		}
		if _, rerr := sqlc.New(tx).RevokeInvitation(c.Request.Context(), sqlc.RevokeInvitationParams{
			ID:        invitationID,
			RevokedBy: pgtype.UUID{Bytes: p.UserID, Valid: p.UserID != uuid.Nil},
		}); rerr != nil {
			if rerr == pgx.ErrNoRows {
				return domain.Conflict("invitation_not_pending", "invitation was already accepted, expired, or revoked")
			}
			return rerr
		}
		return nil
	})
	if err != nil {
		httpx.Error(c, err)
		return
	}
	mh.recordAudit(c, p.TenantID, "client_member.invite_revoked", clientID.String(),
		map[string]any{"invitation_id": invitationID.String()})
	c.Status(http.StatusNoContent)
}

func (mh *MemberHandler) regenerateInvitation(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}
	clientID, err := uuid.Parse(c.Param("clientId"))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_client_id", "clientId is not a valid UUID"))
		return
	}
	invitationID, err := uuid.Parse(c.Param("invitationId"))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_invitation_id", "invitationId is not a valid UUID"))
		return
	}

	rawToken, tokenHash, err := generateClientToken()
	if err != nil {
		httpx.Error(c, domain.Internal("token_gen_failed", "failed to generate invitation token").WithCause(err))
		return
	}

	newExpiry := time.Now().UTC().Add(7 * 24 * time.Hour)
	var email string
	err = mh.pool.InTenantTx(c.Request.Context(), p.TenantID, func(tx pgx.Tx) error {
		inv, lerr := sqlc.New(tx).GetInvitationByID(c.Request.Context(), invitationID)
		if lerr != nil {
			if lerr == pgx.ErrNoRows {
				return domain.NotFound("invitation_not_found", "invitation not found")
			}
			return lerr
		}
		if inv.Scope != "client" || !inv.ClientID.Valid || uuid.UUID(inv.ClientID.Bytes) != clientID {
			return domain.NotFound("invitation_not_found", "invitation not found")
		}
		email = inv.Email
		if _, uerr := sqlc.New(tx).RegenerateInvitationToken(c.Request.Context(), sqlc.RegenerateInvitationTokenParams{
			ID:        invitationID,
			TokenHash: tokenHash,
			ExpiresAt: newExpiry,
		}); uerr != nil {
			if uerr == pgx.ErrNoRows {
				return domain.Conflict("invitation_not_pending", "invitation was already accepted")
			}
			return uerr
		}
		return nil
	})
	if err != nil {
		httpx.Error(c, err)
		return
	}

	link := mh.baseURL + "/accept?token=" + rawToken
	expStr := newExpiry.Format("2006-01-02T15:04:05Z")
	invIDStr := invitationID.String()
	mh.recordAudit(c, p.TenantID, "client_member.invite_regenerated", clientID.String(),
		map[string]any{"invitation_id": invitationID.String(), "email": email})
	c.JSON(http.StatusOK, inviteResultDTO{
		Email:        email,
		Invited:      true,
		AcceptLink:   link,
		InvitationID: &invIDStr,
		ExpiresAt:    &expStr,
	})
}

// recordAudit best-effort records a client member audit event.
func (mh *MemberHandler) recordAudit(c *gin.Context, tenantID uuid.UUID, action, targetID string, meta map[string]any) {
	if mh.audit == nil {
		return
	}
	actorType := audit.ActorSystem
	actorID := ""
	if p, ok := domain.PrincipalFromContext(c.Request.Context()); ok {
		actorType = audit.ActorUser
		if p.Type == domain.PrincipalAPIKey {
			actorType = audit.ActorAPIKey
		}
		actorID = p.ActorID()
	}
	_, _ = mh.audit.Record(c.Request.Context(), audit.Event{
		TenantID:   tenantID,
		ActorType:  actorType,
		ActorID:    actorID,
		Action:     action,
		TargetType: "client",
		TargetID:   targetID,
		Metadata:   meta,
	})
}

// generateClientToken generates a 32-byte random token for client portal
// invitations. Same construction as invitation.generateSecureToken (that
// function is package-private; we keep this copy local to the client package).
func generateClientToken() (raw, hash string, err error) {
	b := make([]byte, 32)
	if _, err = rand.Read(b); err != nil {
		return "", "", fmt.Errorf("rand token: %w", err)
	}
	raw = hex.EncodeToString(b)
	sum := sha256.Sum256([]byte(raw))
	hash = hex.EncodeToString(sum[:])
	return raw, hash, nil
}
