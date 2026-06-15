package capture

import (
	"context"

	"github.com/jackc/pgx/v5"

	"github.com/mosamlife/wpmgr/apps/api/internal/db"
	"github.com/mosamlife/wpmgr/apps/api/internal/db/sqlc"
)

// DBSiteIDLister implements SiteIDLister by querying connected sites cross-tenant
// under the app.agent GUC. Only sites in the 'connected' state (not degraded or
// pending) are included — degraded sites are reachable but excluded to avoid
// burning encoder capacity on known-troubled sites.
type DBSiteIDLister struct {
	pool *db.Pool
}

// NewDBSiteIDLister builds a DBSiteIDLister.
func NewDBSiteIDLister(pool *db.Pool) *DBSiteIDLister {
	return &DBSiteIDLister{pool: pool}
}

// ListConnectedSiteIDs returns the ID, tenant ID, and URL of every enrolled site
// in the 'connected' state, across all tenants, under the app.agent GUC.
func (l *DBSiteIDLister) ListConnectedSiteIDs(ctx context.Context) ([]SiteIDWithTenantAndURL, error) {
	var out []SiteIDWithTenantAndURL
	err := l.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		rows, err := sqlc.New(tx).ListConnectedSiteIDsForScreenshot(ctx)
		if err != nil {
			return err
		}
		out = make([]SiteIDWithTenantAndURL, 0, len(rows))
		for _, r := range rows {
			out = append(out, SiteIDWithTenantAndURL{
				SiteID:   r.ID,
				TenantID: r.TenantID,
				URL:      r.Url,
			})
		}
		return nil
	})
	return out, err
}

// Ensure DBSiteIDLister satisfies the SiteIDLister interface at compile time.
var _ SiteIDLister = (*DBSiteIDLister)(nil)
