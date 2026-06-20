package vuln

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgtype"

	"github.com/mosamlife/wpmgr/apps/api/internal/db"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
)

// Repo is the data-access layer for the vuln domain.  All tenant-scoped
// operations run under pool.InTenantTx or pool.InAgentTx as appropriate.
// The global feed tables (wordfence_vuln_*) are written by the ingester via
// pool.InAgentTx and read without a tenant GUC.
type Repo struct {
	pool *db.Pool
}

// NewRepo builds a Repo.
func NewRepo(pool *db.Pool) *Repo {
	return &Repo{pool: pool}
}

// ---------------------------------------------------------------------------
// Feed ingestion (global, no RLS)
// ---------------------------------------------------------------------------

// UpsertFeedRecord inserts or replaces one vulnerability record and its
// associated software rows inside the provided transaction.  Called once per
// record during the feed import batch.
func (r *Repo) UpsertFeedRecord(ctx context.Context, tx pgx.Tx, rec FeedRecord) error {
	_, err := tx.Exec(ctx, `
		INSERT INTO wordfence_vuln_feed
			(vuln_id, title, cve, cve_link, cvss_score, cvss_rating, cwe,
			 informational, references, published, updated, raw)
		VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12)
		ON CONFLICT (vuln_id) DO UPDATE SET
			title         = EXCLUDED.title,
			cve           = EXCLUDED.cve,
			cve_link      = EXCLUDED.cve_link,
			cvss_score    = EXCLUDED.cvss_score,
			cvss_rating   = EXCLUDED.cvss_rating,
			cwe           = EXCLUDED.cwe,
			informational = EXCLUDED.informational,
			references    = EXCLUDED.references,
			published     = EXCLUDED.published,
			updated       = EXCLUDED.updated,
			raw           = EXCLUDED.raw`,
		rec.VulnID, rec.Title, nilString(rec.CVE), nilString(rec.CVELink),
		rec.CVSSScore, nilString(rec.CVSSRating), rec.CWE,
		rec.Informational, rec.References, rec.Published, rec.Updated, rec.Raw,
	)
	if err != nil {
		return fmt.Errorf("upsert vuln feed record %s: %w", rec.VulnID, err)
	}

	// Delete stale software rows for this vuln (re-insert below ensures freshness).
	if _, err := tx.Exec(ctx,
		`DELETE FROM wordfence_vuln_software WHERE vuln_id = $1`, rec.VulnID,
	); err != nil {
		return fmt.Errorf("delete stale software rows for %s: %w", rec.VulnID, err)
	}

	// Re-insert software rows.
	// F3: normalise slug to lower-case on ingest so the feed canonical slug
	// ("Akismet") and an agent inventory slug ("akismet") always match. The
	// original mixed-case slug is NOT stored because the conflict key
	// (vuln_id, kind, slug) must be stable, and slug is matched in
	// LookupSoftware using the same lower-cased value from the agent inventory.
	for _, sw := range rec.Software {
		slug := normSlug(sw.Slug)
		if _, err := tx.Exec(ctx, `
			INSERT INTO wordfence_vuln_software
				(vuln_id, kind, slug, affected_versions, patched, patched_versions)
			VALUES ($1,$2,$3,$4,$5,$6)
			ON CONFLICT (vuln_id, kind, slug) DO UPDATE SET
				affected_versions = EXCLUDED.affected_versions,
				patched           = EXCLUDED.patched,
				patched_versions  = EXCLUDED.patched_versions`,
			rec.VulnID, sw.Kind, slug, sw.AffectedVersions, sw.Patched, sw.PatchedVersions,
		); err != nil {
			return fmt.Errorf("upsert software row vuln=%s kind=%s slug=%s: %w",
				rec.VulnID, sw.Kind, slug, err)
		}
	}
	return nil
}

// PruneMissingVulns deletes feed rows whose vuln_id is not in the provided set.
// Called after a full-dump ingest to remove retracted vulnerabilities.
func (r *Repo) PruneMissingVulns(ctx context.Context, tx pgx.Tx, knownIDs []string) error {
	// Build a temporary table of known IDs for the NOT IN filter.
	if len(knownIDs) == 0 {
		// Safety: if the ingested set is empty (e.g. feed returned nothing) do NOT
		// prune — something went wrong upstream.
		return nil
	}
	ids := make([]any, len(knownIDs))
	for i, id := range knownIDs {
		ids[i] = id
	}
	// pgx parameterized ANY($1::text[]).
	_, err := tx.Exec(ctx,
		`DELETE FROM wordfence_vuln_feed WHERE vuln_id != ALL($1::text[])`,
		knownIDs,
	)
	if err != nil {
		return fmt.Errorf("prune missing vulns: %w", err)
	}
	return nil
}

// StampFeedMeta writes the freshness + attribution sentinel row.
func (r *Repo) StampFeedMeta(ctx context.Context, tx pgx.Tx, meta FeedMetaUpdate) error {
	_, err := tx.Exec(ctx, `
		UPDATE wordfence_vuln_feed_meta SET
			fetched_at      = $1,
			ok              = $2,
			record_count    = $3,
			defiant_notice  = $4,
			defiant_license = $5,
			mitre_notice    = $6,
			last_error      = $7
		WHERE id = 1`,
		meta.FetchedAt, meta.OK, meta.RecordCount,
		nilString(meta.DefiantNotice), nilString(meta.DefiantLicense),
		nilString(meta.MitreNotice), nilString(meta.LastError),
	)
	return err
}

// StampFeedError records an error without resetting the ok/fetched_at fields
// from the last successful run.
func (r *Repo) StampFeedError(ctx context.Context, lastError string) error {
	_, err := r.pool.Exec(ctx,
		`UPDATE wordfence_vuln_feed_meta SET ok = false, last_error = $1 WHERE id = 1`,
		lastError,
	)
	return err
}

// GetFeedMeta returns the current feed sentinel row.
func (r *Repo) GetFeedMeta(ctx context.Context) (FeedMeta, error) {
	var m FeedMeta
	var fetchedAt pgtype.Timestamptz
	var defiantNotice, defiantLicense, mitreNotice, lastError pgtype.Text
	err := r.pool.QueryRow(ctx, `
		SELECT fetched_at, ok, record_count,
		       defiant_notice, defiant_license, mitre_notice, last_error
		FROM wordfence_vuln_feed_meta WHERE id = 1`,
	).Scan(&fetchedAt, &m.OK, &m.RecordCount,
		&defiantNotice, &defiantLicense, &mitreNotice, &lastError)
	if err != nil {
		return m, fmt.Errorf("get feed meta: %w", err)
	}
	if fetchedAt.Valid {
		t := fetchedAt.Time
		m.FetchedAt = &t
	}
	m.DefiantNotice = defiantNotice.String
	m.DefiantLicense = defiantLicense.String
	m.MitreNotice = mitreNotice.String
	m.LastError = lastError.String
	return m, nil
}

// LookupSoftware returns all vulnerability software rows for the given (kind, slug).
// Reads without a tenant GUC (global public table).
// F3: the slug is lower-cased before comparison to match the normalisation
// applied on ingest (see UpsertFeedRecord). This prevents false negatives when
// the agent inventory slug differs in case from the Wordfence canonical slug.
func (r *Repo) LookupSoftware(ctx context.Context, kind, slug string) ([]VulnSoftwareRow, error) {
	querySlug := normSlug(slug)
	rows, err := r.pool.Query(ctx, `
		SELECT s.vuln_id, s.kind, s.slug, s.affected_versions, s.patched, s.patched_versions,
		       f.title, f.cve, f.cve_link, f.cvss_score, f.cvss_rating, f.references
		FROM wordfence_vuln_software s
		JOIN wordfence_vuln_feed f USING (vuln_id)
		WHERE s.kind = $1 AND s.slug = $2`, kind, querySlug)
	if err != nil {
		return nil, fmt.Errorf("lookup software %s/%s: %w", kind, slug, err)
	}
	defer rows.Close()

	var result []VulnSoftwareRow
	for rows.Next() {
		var row VulnSoftwareRow
		var cve, cveLink, cvssRating pgtype.Text
		var cvssScore pgtype.Numeric
		if err := rows.Scan(
			&row.VulnID, &row.Kind, &row.Slug,
			&row.AffectedVersions, &row.Patched, &row.PatchedVersions,
			&row.Title, &cve, &cveLink, &cvssScore, &cvssRating,
			&row.References,
		); err != nil {
			return nil, fmt.Errorf("scan software row: %w", err)
		}
		row.CVE = cve.String
		row.CVELink = cveLink.String
		row.CVSSRating = cvssRating.String
		if cvssScore.Valid {
			f, _ := cvssScore.Float64Value()
			if f.Valid {
				v := f.Float64
				row.CVSSScore = &v
			}
		}
		result = append(result, row)
	}
	return result, rows.Err()
}

// ---------------------------------------------------------------------------
// Findings (tenant-scoped, RLS-enforced)
// ---------------------------------------------------------------------------

// UpsertFinding inserts or refreshes a matched finding for a site.
// Dismissed findings are only updated (last_seen + installed_version) if the
// installed version has changed, preserving the dismiss decision otherwise.
func (r *Repo) UpsertFinding(ctx context.Context, tx pgx.Tx, f FindingUpsert) error {
	_, err := tx.Exec(ctx, `
		INSERT INTO site_vulnerabilities
			(tenant_id, site_id, vuln_id, kind, slug, name,
			 installed_version, fixed_version, severity, cvss_score,
			 cve, title, status, first_seen, last_seen)
		VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,'open',now(),now())
		ON CONFLICT (site_id, vuln_id, kind, slug) DO UPDATE SET
			last_seen         = now(),
			installed_version = EXCLUDED.installed_version,
			fixed_version     = EXCLUDED.fixed_version,
			severity          = EXCLUDED.severity,
			cvss_score        = EXCLUDED.cvss_score,
			cve               = EXCLUDED.cve,
			title             = EXCLUDED.title,
			name              = EXCLUDED.name,
			-- Re-open a resolved finding when the same vuln re-appears.
			status = CASE
				WHEN site_vulnerabilities.status = 'resolved' THEN 'open'
				ELSE site_vulnerabilities.status
			END,
			resolved_at = CASE
				WHEN site_vulnerabilities.status = 'resolved' THEN NULL
				ELSE site_vulnerabilities.resolved_at
			END`,
		f.TenantID, f.SiteID, f.VulnID, f.Kind, f.Slug, f.Name,
		f.InstalledVersion, nilString(f.FixedVersion), f.Severity,
		f.CVSSScore, nilString(f.CVE), f.Title,
	)
	if err != nil {
		return fmt.Errorf("upsert finding vuln=%s site=%s: %w", f.VulnID, f.SiteID, err)
	}
	return nil
}

// ResolveStaleFindings marks open findings for the site as resolved when their
// vuln_id is NOT in the current matched set (i.e. the vulnerability no longer
// applies after an update or the item was removed).
func (r *Repo) ResolveStaleFindings(ctx context.Context, tx pgx.Tx, tenantID, siteID uuid.UUID, matchedVulnIDs []string) error {
	if len(matchedVulnIDs) == 0 {
		// Resolve ALL open findings — the site has no vulnerabilities.
		_, err := tx.Exec(ctx, `
			UPDATE site_vulnerabilities SET
				status      = 'resolved',
				resolved_at = now()
			WHERE tenant_id = $1 AND site_id = $2 AND status = 'open'`,
			tenantID, siteID,
		)
		return err
	}
	_, err := tx.Exec(ctx, `
		UPDATE site_vulnerabilities SET
			status      = 'resolved',
			resolved_at = now()
		WHERE tenant_id = $1 AND site_id = $2
		  AND status = 'open'
		  AND vuln_id != ALL($3::text[])`,
		tenantID, siteID, matchedVulnIDs,
	)
	return err
}

// ListOpenFindings returns the open findings for a site, severity-sorted.
func (r *Repo) ListOpenFindings(ctx context.Context, tenantID, siteID uuid.UUID) ([]Finding, error) {
	var findings []Finding
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		rows, err := tx.Query(ctx, `
			SELECT v.id, v.tenant_id, v.site_id, v.vuln_id, v.kind, v.slug, v.name,
			       v.installed_version, v.fixed_version, v.severity, v.cvss_score,
			       v.cve, v.title, v.status, v.first_seen, v.last_seen,
			       v.resolved_at, v.dismissed_at, v.dismissed_by,
			       f.cve_link, f.references
			FROM site_vulnerabilities v
			LEFT JOIN wordfence_vuln_feed f USING (vuln_id)
			WHERE v.tenant_id = $1 AND v.site_id = $2 AND v.status = 'open'
			ORDER BY
				CASE v.severity
					WHEN 'critical' THEN 1
					WHEN 'high'     THEN 2
					WHEN 'medium'   THEN 3
					ELSE 4
				END,
				v.cvss_score DESC NULLS LAST,
				v.first_seen DESC`,
			tenantID, siteID,
		)
		if err != nil {
			return err
		}
		defer rows.Close()
		for rows.Next() {
			var f Finding
			if err := scanFinding(rows, &f); err != nil {
				return err
			}
			findings = append(findings, f)
		}
		return rows.Err()
	})
	return findings, err
}

// GetFinding returns a single finding by ID, tenant-scoped.
func (r *Repo) GetFinding(ctx context.Context, tenantID, siteID, findingID uuid.UUID) (Finding, error) {
	var f Finding
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		rows, err := tx.Query(ctx, `
			SELECT v.id, v.tenant_id, v.site_id, v.vuln_id, v.kind, v.slug, v.name,
			       v.installed_version, v.fixed_version, v.severity, v.cvss_score,
			       v.cve, v.title, v.status, v.first_seen, v.last_seen,
			       v.resolved_at, v.dismissed_at, v.dismissed_by,
			       COALESCE(fd.cve_link,''), COALESCE(fd.references,'[]'::jsonb)
			FROM site_vulnerabilities v
			LEFT JOIN wordfence_vuln_feed fd USING (vuln_id)
			WHERE v.tenant_id = $1 AND v.site_id = $2 AND v.id = $3`,
			tenantID, siteID, findingID,
		)
		if err != nil {
			return err
		}
		defer rows.Close()
		if !rows.Next() {
			return domain.NotFound("finding_not_found", "vulnerability finding not found")
		}
		return scanFinding(rows, &f)
	})
	return f, err
}

// DismissFinding marks a finding as dismissed by the given user.
func (r *Repo) DismissFinding(ctx context.Context, tenantID, siteID, findingID, userID uuid.UUID) error {
	return r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		tag, err := tx.Exec(ctx, `
			UPDATE site_vulnerabilities SET
				status       = 'dismissed',
				dismissed_at = now(),
				dismissed_by = $4
			WHERE tenant_id = $1 AND site_id = $2 AND id = $3 AND status = 'open'`,
			tenantID, siteID, findingID, userID,
		)
		if err != nil {
			return domain.Internal("dismiss_finding_failed", "failed to dismiss finding").WithCause(err)
		}
		if tag.RowsAffected() == 0 {
			return domain.NotFound("finding_not_found", "vulnerability finding not found or not in open state")
		}
		return nil
	})
}

// RestoreFinding re-opens a dismissed finding.
func (r *Repo) RestoreFinding(ctx context.Context, tenantID, siteID, findingID uuid.UUID) error {
	return r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		tag, err := tx.Exec(ctx, `
			UPDATE site_vulnerabilities SET
				status       = 'open',
				dismissed_at = NULL,
				dismissed_by = NULL
			WHERE tenant_id = $1 AND site_id = $2 AND id = $3 AND status = 'dismissed'`,
			tenantID, siteID, findingID,
		)
		if err != nil {
			return domain.Internal("restore_finding_failed", "failed to restore finding").WithCause(err)
		}
		if tag.RowsAffected() == 0 {
			return domain.NotFound("finding_not_found", "vulnerability finding not found or not in dismissed state")
		}
		return nil
	})
}

// FleetOpenCounts returns the open finding counts per severity across all
// sites for a tenant.
func (r *Repo) FleetOpenCounts(ctx context.Context, tenantID uuid.UUID) (critical, high, medium, low int, err error) {
	err = r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		rows, err := tx.Query(ctx, `
			SELECT severity, count(*)
			FROM site_vulnerabilities
			WHERE tenant_id = $1 AND status = 'open'
			GROUP BY severity`, tenantID)
		if err != nil {
			return err
		}
		defer rows.Close()
		for rows.Next() {
			var sev string
			var cnt int
			if err := rows.Scan(&sev, &cnt); err != nil {
				return err
			}
			switch sev {
			case SeverityCritical:
				critical = cnt
			case SeverityHigh:
				high = cnt
			case SeverityMedium:
				medium = cnt
			case SeverityLow:
				low = cnt
			}
		}
		return rows.Err()
	})
	return
}

// FleetOpenFindings returns the cross-site open findings list for the tenant,
// ordered by severity then cvss_score then first_seen.
func (r *Repo) FleetOpenFindings(ctx context.Context, tenantID uuid.UUID, limit int) ([]FleetFindingRow, error) {
	if limit <= 0 || limit > 500 {
		limit = 200
	}
	var result []FleetFindingRow
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		rows, err := tx.Query(ctx, `
			SELECT v.id, v.tenant_id, v.site_id, v.vuln_id, v.kind, v.slug, v.name,
			       v.installed_version, v.fixed_version, v.severity, v.cvss_score,
			       v.cve, v.title, v.status, v.first_seen, v.last_seen,
			       v.resolved_at, v.dismissed_at, v.dismissed_by,
			       COALESCE(f.cve_link,''), COALESCE(f.references,'[]'::jsonb),
			       s.name AS site_name, s.url AS site_url
			FROM site_vulnerabilities v
			LEFT JOIN wordfence_vuln_feed f USING (vuln_id)
			JOIN sites s ON s.id = v.site_id
			WHERE v.tenant_id = $1 AND v.status = 'open'
			ORDER BY
				CASE v.severity
					WHEN 'critical' THEN 1
					WHEN 'high'     THEN 2
					WHEN 'medium'   THEN 3
					ELSE 4
				END,
				v.cvss_score DESC NULLS LAST,
				v.first_seen DESC
			LIMIT $2`,
			tenantID, limit,
		)
		if err != nil {
			return err
		}
		defer rows.Close()
		for rows.Next() {
			var row FleetFindingRow
			if err := scanFleetFindingRow(rows, &row); err != nil {
				return err
			}
			result = append(result, row)
		}
		return rows.Err()
	})
	return result, err
}

// ---------------------------------------------------------------------------
// Internal types used between repo and service
// ---------------------------------------------------------------------------

// FeedRecord is the parsed representation of one vulnerability from the
// Wordfence feed, ready for database ingestion.
type FeedRecord struct {
	VulnID        string
	Title         string
	CVE           string
	CVELink       string
	CVSSScore     *float64
	CVSSRating    string
	CWE           []byte // JSONB
	Informational bool
	References    []byte // JSONB
	Published     *time.Time
	Updated       *time.Time
	Raw           []byte // full record JSONB
	Software      []SoftwareRow
}

// SoftwareRow is one entry in the software[] array of a feed record.
type SoftwareRow struct {
	Kind             string // core|plugin|theme
	Slug             string
	AffectedVersions []byte // JSONB
	Patched          bool
	PatchedVersions  []byte // JSONB
}

// FeedMetaUpdate is the data written to the sentinel row after ingestion.
type FeedMetaUpdate struct {
	FetchedAt      time.Time
	OK             bool
	RecordCount    int
	DefiantNotice  string
	DefiantLicense string
	MitreNotice    string
	LastError      string
}

// VulnSoftwareRow is the projection returned by LookupSoftware: all the
// columns the matcher needs from the software + feed join.
type VulnSoftwareRow struct {
	VulnID           string
	Kind             string
	Slug             string
	AffectedVersions []byte
	Patched          bool
	PatchedVersions  []byte
	Title            string
	CVE              string
	CVELink          string
	CVSSScore        *float64
	CVSSRating       string
	References       []byte
}

// FindingUpsert is the data the service hands to UpsertFinding.
type FindingUpsert struct {
	TenantID         uuid.UUID
	SiteID           uuid.UUID
	VulnID           string
	Kind             string
	Slug             string
	Name             string
	InstalledVersion string
	FixedVersion     string
	Severity         string
	CVSSScore        *float64
	CVE              string
	Title            string
}

// FleetFindingRow is the row shape returned by FleetOpenFindings.
type FleetFindingRow struct {
	Finding  Finding
	SiteName string
	SiteURL  string
}

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

// normSlug lower-cases a software slug so that Wordfence canonical slugs
// (e.g. "Akismet") match agent inventory slugs (e.g. "akismet") consistently.
// Applied on both the ingest path (UpsertFeedRecord) and the lookup path
// (LookupSoftware) so the stored key and the query key are always identical.
func normSlug(slug string) string { return strings.ToLower(slug) }

func nilString(s string) *string {
	if s == "" {
		return nil
	}
	return &s
}

func scanFinding(rows pgx.Rows, f *Finding) error {
	var (
		cvssScore   pgtype.Numeric
		resolvedAt  pgtype.Timestamptz
		dismissedAt pgtype.Timestamptz
		dismissedBy pgtype.UUID
		cveLink     pgtype.Text
		fixedVer    pgtype.Text
		cve         pgtype.Text
		refs        []byte
	)
	err := rows.Scan(
		&f.ID, &f.TenantID, &f.SiteID, &f.VulnID, &f.Kind, &f.Slug, &f.Name,
		&f.InstalledVersion, &fixedVer, &f.Severity, &cvssScore,
		&cve, &f.Title, &f.Status, &f.FirstSeen, &f.LastSeen,
		&resolvedAt, &dismissedAt, &dismissedBy,
		&cveLink, &refs,
	)
	if err != nil {
		return err
	}
	f.FixedVersion = fixedVer.String
	f.CVE = cve.String
	f.CVELink = cveLink.String
	f.References = refs
	if cvssScore.Valid {
		fv, _ := cvssScore.Float64Value()
		if fv.Valid {
			v := fv.Float64
			f.CVSSScore = &v
		}
	}
	if resolvedAt.Valid {
		t := resolvedAt.Time
		f.ResolvedAt = &t
	}
	if dismissedAt.Valid {
		t := dismissedAt.Time
		f.DismissedAt = &t
	}
	if dismissedBy.Valid {
		uid := uuid.UUID(dismissedBy.Bytes)
		f.DismissedBy = &uid
	}
	return nil
}

func scanFleetFindingRow(rows pgx.Rows, row *FleetFindingRow) error {
	var (
		cvssScore   pgtype.Numeric
		resolvedAt  pgtype.Timestamptz
		dismissedAt pgtype.Timestamptz
		dismissedBy pgtype.UUID
		cveLink     pgtype.Text
		fixedVer    pgtype.Text
		cve         pgtype.Text
		refs        []byte
	)
	f := &row.Finding
	err := rows.Scan(
		&f.ID, &f.TenantID, &f.SiteID, &f.VulnID, &f.Kind, &f.Slug, &f.Name,
		&f.InstalledVersion, &fixedVer, &f.Severity, &cvssScore,
		&cve, &f.Title, &f.Status, &f.FirstSeen, &f.LastSeen,
		&resolvedAt, &dismissedAt, &dismissedBy,
		&cveLink, &refs,
		&row.SiteName, &row.SiteURL,
	)
	if err != nil {
		return err
	}
	f.FixedVersion = fixedVer.String
	f.CVE = cve.String
	f.CVELink = cveLink.String
	f.References = refs
	if cvssScore.Valid {
		fv, _ := cvssScore.Float64Value()
		if fv.Valid {
			v := fv.Float64
			f.CVSSScore = &v
		}
	}
	if resolvedAt.Valid {
		t := resolvedAt.Time
		f.ResolvedAt = &t
	}
	if dismissedAt.Valid {
		t := dismissedAt.Time
		f.DismissedAt = &t
	}
	if dismissedBy.Valid {
		uid := uuid.UUID(dismissedBy.Bytes)
		f.DismissedBy = &uid
	}
	return nil
}

// marshalJSON marshals v to JSON bytes, returning nil on error (callers handle
// nil gracefully by defaulting to "[]" or "{}" in the DB column default).
func marshalJSON(v any) []byte {
	b, _ := json.Marshal(v)
	return b
}

// Ensure marshalJSON is used (suppress unused warning — called from worker).
var _ = marshalJSON
