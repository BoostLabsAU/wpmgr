package client

import (
	"context"
	"errors"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgtype"

	"github.com/mosamlife/wpmgr/apps/api/internal/db"
	"github.com/mosamlife/wpmgr/apps/api/internal/db/sqlc"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
)

// Repo is the persistence interface for client records.
type Repo interface {
	List(ctx context.Context, tenantID uuid.UUID, includeArchived bool) ([]Client, error)
	Get(ctx context.Context, tenantID, id uuid.UUID) (Client, error)
	Create(ctx context.Context, in CreateInput) (Client, error)
	Update(ctx context.Context, in UpdateInput) (Client, error)
	Archive(ctx context.Context, tenantID, id uuid.UUID) (Client, error)
	Delete(ctx context.Context, tenantID, id uuid.UUID) (int64, error)
	CountSites(ctx context.Context, tenantID, clientID uuid.UUID) (int64, error)
	AssignSites(ctx context.Context, in AssignInput) (int64, error)
}

type pgRepo struct {
	pool *db.Pool
}

// NewRepo builds a Repo backed by the pgx pool with RLS enforcement.
func NewRepo(pool *db.Pool) Repo {
	return &pgRepo{pool: pool}
}

func (r *pgRepo) List(ctx context.Context, tenantID uuid.UUID, includeArchived bool) ([]Client, error) {
	var out []Client
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		var ia *bool
		if includeArchived {
			ia = &includeArchived
		}
		rows, err := sqlc.New(tx).ListClients(ctx, sqlc.ListClientsParams{
			TenantID:        tenantID,
			IncludeArchived: ia,
		})
		if err != nil {
			return domain.Internal("client_list_failed", "failed to list clients").WithCause(err)
		}
		out = make([]Client, 0, len(rows))
		for _, row := range rows {
			out = append(out, rowToModel(row.ID, row.TenantID, row.Name, row.ContactEmail,
				row.Company, row.Phone, row.Notes, row.Color, row.LogoUrl,
				row.ArchivedAt, row.CreatedAt, row.UpdatedAt, row.SiteCount))
		}
		return nil
	})
	return out, err
}

func (r *pgRepo) Get(ctx context.Context, tenantID, id uuid.UUID) (Client, error) {
	var out Client
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row, err := sqlc.New(tx).GetClient(ctx, sqlc.GetClientParams{ID: id, TenantID: tenantID})
		if err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return domain.NotFound("client_not_found", "client not found")
			}
			return domain.Internal("client_get_failed", "failed to load client").WithCause(err)
		}
		out = rowToModel(row.ID, row.TenantID, row.Name, row.ContactEmail,
			row.Company, row.Phone, row.Notes, row.Color, row.LogoUrl,
			row.ArchivedAt, row.CreatedAt, row.UpdatedAt, row.SiteCount)
		return nil
	})
	return out, err
}

func (r *pgRepo) Create(ctx context.Context, in CreateInput) (Client, error) {
	var out Client
	err := r.pool.InTenantTx(ctx, in.TenantID, func(tx pgx.Tx) error {
		row, err := sqlc.New(tx).CreateClient(ctx, sqlc.CreateClientParams{
			TenantID:     in.TenantID,
			Name:         in.Name,
			ContactEmail: in.ContactEmail,
			Company:      in.Company,
			Phone:        in.Phone,
			Notes:        in.Notes,
			Color:        in.Color,
			LogoUrl:      in.LogoURL,
		})
		if err != nil {
			return domain.Internal("client_create_failed", "failed to create client").WithCause(err)
		}
		out = fromClient(row)
		return nil
	})
	return out, err
}

func (r *pgRepo) Update(ctx context.Context, in UpdateInput) (Client, error) {
	var out Client
	err := r.pool.InTenantTx(ctx, in.TenantID, func(tx pgx.Tx) error {
		row, err := sqlc.New(tx).UpdateClient(ctx, sqlc.UpdateClientParams{
			ID:           in.ID,
			TenantID:     in.TenantID,
			Name:         in.Name,
			ContactEmail: in.ContactEmail,
			Company:      in.Company,
			Phone:        in.Phone,
			Notes:        in.Notes,
			Color:        in.Color,
			LogoUrl:      in.LogoURL,
		})
		if err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return domain.NotFound("client_not_found", "client not found")
			}
			return domain.Internal("client_update_failed", "failed to update client").WithCause(err)
		}
		out = fromClient(row)
		return nil
	})
	return out, err
}

func (r *pgRepo) Archive(ctx context.Context, tenantID, id uuid.UUID) (Client, error) {
	var out Client
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row, err := sqlc.New(tx).ArchiveClient(ctx, sqlc.ArchiveClientParams{ID: id, TenantID: tenantID})
		if err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return domain.NotFound("client_not_found", "client not found")
			}
			return domain.Internal("client_archive_failed", "failed to archive client").WithCause(err)
		}
		out = fromClient(row)
		return nil
	})
	return out, err
}

func (r *pgRepo) Delete(ctx context.Context, tenantID, id uuid.UUID) (int64, error) {
	var n int64
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		var err error
		n, err = sqlc.New(tx).HardDeleteClient(ctx, sqlc.HardDeleteClientParams{ID: id, TenantID: tenantID})
		if err != nil {
			return domain.Internal("client_delete_failed", "failed to delete client").WithCause(err)
		}
		return nil
	})
	return n, err
}

func (r *pgRepo) CountSites(ctx context.Context, tenantID, clientID uuid.UUID) (int64, error) {
	var count int64
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		var err error
		count, err = sqlc.New(tx).CountSitesForClient(ctx, sqlc.CountSitesForClientParams{
			ClientID: pgtype.UUID{Bytes: [16]byte(clientID), Valid: true},
			TenantID: tenantID,
		})
		if err != nil {
			return domain.Internal("client_count_sites_failed", "failed to count sites for client").WithCause(err)
		}
		return nil
	})
	return count, err
}

func (r *pgRepo) AssignSites(ctx context.Context, in AssignInput) (int64, error) {
	var n int64
	var clientID pgtype.UUID
	if in.ClientID != nil {
		clientID = pgtype.UUID{Bytes: [16]byte(*in.ClientID), Valid: true}
	}
	err := r.pool.InTenantTx(ctx, in.TenantID, func(tx pgx.Tx) error {
		var err error
		n, err = sqlc.New(tx).AssignSitesClient(ctx, sqlc.AssignSitesClientParams{
			ClientID: clientID,
			TenantID: in.TenantID,
			SiteIds:  in.SiteIDs,
		})
		if err != nil {
			return domain.Internal("client_assign_sites_failed", "failed to assign sites").WithCause(err)
		}
		return nil
	})
	return n, err
}

// ---------------------------------------------------------------------------
// model mapping helpers
// ---------------------------------------------------------------------------

func rowToModel(
	id, tenantID uuid.UUID,
	name string,
	contactEmail, company, phone, notes, color, logoURL *string,
	archivedAt pgtype.Timestamptz,
	createdAt, updatedAt time.Time,
	siteCount int64,
) Client {
	c := Client{
		ID:           id,
		TenantID:     tenantID,
		Name:         name,
		ContactEmail: contactEmail,
		Company:      company,
		Phone:        phone,
		Notes:        notes,
		Color:        color,
		LogoURL:      logoURL,
		CreatedAt:    createdAt,
		UpdatedAt:    updatedAt,
		SiteCount:    siteCount,
	}
	if archivedAt.Valid {
		t := archivedAt.Time
		c.ArchivedAt = &t
	}
	return c
}

func fromClient(row sqlc.Client) Client {
	c := Client{
		ID:           row.ID,
		TenantID:     row.TenantID,
		Name:         row.Name,
		ContactEmail: row.ContactEmail,
		Company:      row.Company,
		Phone:        row.Phone,
		Notes:        row.Notes,
		Color:        row.Color,
		LogoURL:      row.LogoUrl,
		CreatedAt:    row.CreatedAt,
		UpdatedAt:    row.UpdatedAt,
	}
	if row.ArchivedAt.Valid {
		t := row.ArchivedAt.Time
		c.ArchivedAt = &t
	}
	return c
}
