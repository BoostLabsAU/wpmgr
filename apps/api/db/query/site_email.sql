-- M59 — Per-site Email / SMTP Management queries (Phase 1: config CRUD).
--
-- All operator reads/writes run under pool.InTenantTx (app.tenant_id GUC).
-- The repo NEVER returns provider_secret_encrypted to callers — only a
-- secret_set boolean is surfaced (mirrors perf repo CDN credentials pattern).
-- updated_at is set via now() in the query (no trigger).

-- ---------------------------------------------------------------------------
-- site_email_config
-- ---------------------------------------------------------------------------

-- name: GetSiteEmailConfig :one
-- Returns the per-site config row. Caller resolves org-wide inheritance in the
-- service layer (call GetOrgEmailConfig when this returns pgx.ErrNoRows).
SELECT *
FROM site_email_config
WHERE tenant_id = @tenant_id
  AND site_id   = @site_id;

-- name: GetOrgEmailConfig :one
-- Returns the org-wide default config row (site_id IS NULL) for a tenant.
SELECT *
FROM site_email_config
WHERE tenant_id = @tenant_id
  AND site_id IS NULL;

-- name: UpsertSiteEmailConfig :one
-- Insert-or-update a per-site config row. provider_secret_encrypted uses a
-- nil-sentinel: when @set_secret is false the existing ciphertext is preserved,
-- so editing non-secret fields without re-entering the password keeps the stored
-- secret intact (mirrors smtp_settings.sql UpsertSMTPSettings pattern exactly).
INSERT INTO site_email_config (
    tenant_id, site_id,
    provider,
    from_address, from_name, force_from_email, force_from_name, return_path,
    config,
    provider_secret_encrypted,
    mappings, default_connection, fallback_connection,
    log_emails, store_body, retention_days,
    updated_at
) VALUES (
    @tenant_id, @site_id,
    @provider,
    @from_address, @from_name, @force_from_email, @force_from_name, @return_path,
    @config,
    -- ::bytea so Postgres can infer the param type (both CASE branches are
    -- otherwise untyped: a bare param + NULL -> "could not determine data type").
    CASE WHEN @set_secret::boolean THEN @provider_secret_encrypted::bytea ELSE NULL END,
    @mappings, @default_connection, @fallback_connection,
    @log_emails, @store_body, @retention_days,
    now()
)
ON CONFLICT ON CONSTRAINT site_email_config_pkey DO NOTHING
RETURNING *;

-- name: UpsertSiteEmailConfigByTenantSite :one
-- Upsert variant that matches on (tenant_id, site_id) instead of PK — used for
-- the standard per-site PUT where no id is known by the caller.
-- For the org-wide default (site_id IS NULL) use UpsertOrgEmailConfig instead.
INSERT INTO site_email_config (
    tenant_id, site_id,
    provider,
    from_address, from_name, force_from_email, force_from_name, return_path,
    config,
    provider_secret_encrypted,
    mappings, default_connection, fallback_connection,
    log_emails, store_body, retention_days,
    updated_at
) VALUES (
    @tenant_id, @site_id,
    @provider,
    @from_address, @from_name, @force_from_email, @force_from_name, @return_path,
    @config,
    CASE WHEN @set_secret::boolean THEN @provider_secret_encrypted::bytea ELSE NULL END,
    @mappings, @default_connection, @fallback_connection,
    @log_emails, @store_body, @retention_days,
    now()
)
ON CONFLICT (tenant_id, site_id) WHERE site_id IS NOT NULL
DO UPDATE SET
    provider                  = EXCLUDED.provider,
    from_address              = EXCLUDED.from_address,
    from_name                 = EXCLUDED.from_name,
    force_from_email          = EXCLUDED.force_from_email,
    force_from_name           = EXCLUDED.force_from_name,
    return_path               = EXCLUDED.return_path,
    config                    = EXCLUDED.config,
    provider_secret_encrypted = CASE WHEN @set_secret::boolean
                                    THEN EXCLUDED.provider_secret_encrypted
                                    ELSE site_email_config.provider_secret_encrypted END,
    mappings                  = EXCLUDED.mappings,
    default_connection        = EXCLUDED.default_connection,
    fallback_connection       = EXCLUDED.fallback_connection,
    log_emails                = EXCLUDED.log_emails,
    store_body                = EXCLUDED.store_body,
    retention_days            = EXCLUDED.retention_days,
    updated_at                = now()
RETURNING *;

-- name: UpsertOrgEmailConfig :one
-- Upsert the org-wide default row (site_id IS NULL).
INSERT INTO site_email_config (
    tenant_id, site_id,
    provider,
    from_address, from_name, force_from_email, force_from_name, return_path,
    config,
    provider_secret_encrypted,
    mappings, default_connection, fallback_connection,
    log_emails, store_body, retention_days,
    updated_at
) VALUES (
    @tenant_id, NULL,
    @provider,
    @from_address, @from_name, @force_from_email, @force_from_name, @return_path,
    @config,
    CASE WHEN @set_secret::boolean THEN @provider_secret_encrypted::bytea ELSE NULL END,
    @mappings, @default_connection, @fallback_connection,
    @log_emails, @store_body, @retention_days,
    now()
)
ON CONFLICT (tenant_id) WHERE site_id IS NULL
DO UPDATE SET
    provider                  = EXCLUDED.provider,
    from_address              = EXCLUDED.from_address,
    from_name                 = EXCLUDED.from_name,
    force_from_email          = EXCLUDED.force_from_email,
    force_from_name           = EXCLUDED.force_from_name,
    return_path               = EXCLUDED.return_path,
    config                    = EXCLUDED.config,
    provider_secret_encrypted = CASE WHEN @set_secret::boolean
                                    THEN EXCLUDED.provider_secret_encrypted
                                    ELSE site_email_config.provider_secret_encrypted END,
    mappings                  = EXCLUDED.mappings,
    default_connection        = EXCLUDED.default_connection,
    fallback_connection       = EXCLUDED.fallback_connection,
    log_emails                = EXCLUDED.log_emails,
    store_body                = EXCLUDED.store_body,
    retention_days            = EXCLUDED.retention_days,
    updated_at                = now()
RETURNING *;

-- name: ListSiteEmailConfigs :many
-- Lists all per-site config rows for a tenant (dashboard overview).
-- Excludes the org-wide default row (site_id IS NULL).
SELECT *
FROM site_email_config
WHERE tenant_id = @tenant_id
  AND site_id IS NOT NULL
ORDER BY created_at DESC, id DESC
LIMIT @row_limit OFFSET @row_offset;

-- name: GetEmailConfigByRouteTokenHash :one
-- m61: resolve a config row by its webhook_route_token_hash for the public
-- webhook dispatcher.  Runs under InAgentTx (webhook path, no tenant GUC).
-- Returns the full row so the handler can load and decrypt the per-row signing
-- key and verify the provider signature with the right key.
SELECT *
FROM site_email_config
WHERE webhook_route_token_hash = @token_hash::bytea;

-- name: SetEmailConfigWebhookFields :one
-- m61: write/rotate webhook security columns on a config row.
-- Use set_signing_key flag (nil-sentinel) to preserve the existing encrypted key
-- when rotating only the token or ARNs.
-- Runs under InTenantTx (operator PUT path).
UPDATE site_email_config
SET webhook_route_token_hash = @token_hash::bytea,
    ses_topic_arns            = @ses_topic_arns::text[],
    webhook_signing_key_enc   = CASE WHEN @set_signing_key::boolean
                                    THEN @signing_key_enc::bytea
                                    ELSE webhook_signing_key_enc END,
    updated_at                = now()
WHERE tenant_id = @tenant_id
  AND id        = @id
RETURNING *;

-- ---------------------------------------------------------------------------
-- site_email_log  (Phase 3 — ingest + viewer)
-- ---------------------------------------------------------------------------

-- name: IngestEmailLogEntry :one
-- Idempotent upsert of one agent-pushed log entry keyed on
-- (tenant_id, site_id, agent_seq). Status/response/error/resent_count may
-- change on re-push (e.g. an asynchronous provider callback updates the
-- status after initial delivery). Body is only stored when body_stored=true.
INSERT INTO site_email_log (
    tenant_id, site_id, agent_seq,
    message_id, to_addresses, from_address, subject, provider,
    status, response, error, retries, resent_count,
    body_stored, body,
    created_at, updated_at
) VALUES (
    @tenant_id, @site_id, @agent_seq,
    @message_id, @to_addresses, @from_address, @subject, @provider,
    @status, @response, @error, @retries, @resent_count,
    @body_stored,
    -- Store body only when body_stored flag is set; otherwise NULL.
    CASE WHEN @body_stored::boolean THEN @body::text ELSE NULL END,
    @created_at, now()
)
ON CONFLICT (tenant_id, site_id, agent_seq)
    WHERE agent_seq IS NOT NULL
DO UPDATE SET
    status        = EXCLUDED.status,
    response      = EXCLUDED.response,
    error         = EXCLUDED.error,
    resent_count  = EXCLUDED.resent_count,
    body_stored   = EXCLUDED.body_stored,
    body          = CASE WHEN EXCLUDED.body_stored THEN EXCLUDED.body ELSE site_email_log.body END,
    updated_at    = now()
RETURNING *;

-- name: ListSiteEmailLog :many
-- Keyset-paginated list for a single site. Ordered created_at DESC, id DESC.
-- Composite (created_at, id) cursor predicate avoids skipping co-timestamped
-- rows (batch inserts share created_at — see wpmgr-keyset-cursor-composite).
-- Body is intentionally excluded from the list — callers use GetEmailLog for detail.
-- @cursor_ts and @cursor_id are the last row's created_at/id; pass far-future
-- values (e.g. now()+100yr, max-uuid) to get the first page.
-- Repo passes sentinel epoch-start/@range_from and far-future/@range_to when no
-- date filter is requested; @filter_status '' skips the status filter;
-- @search_q '' skips the text search.
SELECT
    id, tenant_id, site_id, agent_seq,
    message_id, to_addresses, from_address, subject, provider,
    status, response, error, retries, resent_count,
    body_stored,
    created_at, updated_at
FROM site_email_log
WHERE tenant_id   = @tenant_id
  AND site_id     = @site_id
  AND (created_at, id) < (@cursor_ts::timestamptz, @cursor_id::uuid)
  AND (@filter_status::text = '' OR status = @filter_status::text)
  AND created_at >= @range_from
  AND created_at <= @range_to
  AND (@search_q::text = '' OR (
        subject ILIKE '%' || @search_q::text || '%'
        OR from_address ILIKE '%' || @search_q::text || '%'
        OR to_addresses::text ILIKE '%' || @search_q::text || '%'
  ))
ORDER BY created_at DESC, id DESC
LIMIT @row_limit;

-- name: GetEmailLog :one
-- Fetch a single email log entry by id (operator detail view, includes body).
SELECT *
FROM site_email_log
WHERE tenant_id = @tenant_id
  AND site_id   = @site_id
  AND id        = @id;

-- name: GetEmailLogPrev :one
-- Returns the id of the next-older row for the Prev button in detail navigation.
SELECT id
FROM site_email_log
WHERE tenant_id = @tenant_id
  AND site_id   = @site_id
  AND (created_at, id) < (@this_ts::timestamptz, @this_id::uuid)
ORDER BY created_at DESC, id DESC
LIMIT 1;

-- name: GetEmailLogNext :one
-- Returns the id of the next-newer row for the Next button in detail navigation.
SELECT id
FROM site_email_log
WHERE tenant_id = @tenant_id
  AND site_id   = @site_id
  AND (created_at, id) > (@this_ts::timestamptz, @this_id::uuid)
ORDER BY created_at ASC, id ASC
LIMIT 1;

-- name: ListFleetEmailLog :many
-- Cross-site keyset-paginated list for a tenant (fleet/agency dashboard).
-- Same composite cursor as ListSiteEmailLog. Body excluded from list.
-- Repo passes sentinel epoch-start/@range_from and far-future/@range_to when no
-- date filter is requested.
SELECT
    id, tenant_id, site_id, agent_seq,
    message_id, to_addresses, from_address, subject, provider,
    status, response, error, retries, resent_count,
    body_stored,
    created_at, updated_at
FROM site_email_log
WHERE tenant_id   = @tenant_id
  AND (created_at, id) < (@cursor_ts::timestamptz, @cursor_id::uuid)
  AND (@filter_status::text = '' OR status = @filter_status::text)
  AND created_at >= @range_from
  AND created_at <= @range_to
  AND (@search_q::text = '' OR (
        subject ILIKE '%' || @search_q::text || '%'
        OR from_address ILIKE '%' || @search_q::text || '%'
  ))
ORDER BY created_at DESC, id DESC
LIMIT @row_limit;

-- name: GetEmailStats :one
-- Per-site summary: total sent/failed counts over [range_from, range_to].
-- Repo always provides explicit bounds; use epoch-start + far-future as the
-- open-ended defaults so no NULL handling is needed in SQL.
SELECT
    COUNT(*)                                          AS total,
    COUNT(*) FILTER (WHERE status = 'sent')           AS sent_count,
    COUNT(*) FILTER (WHERE status = 'failed')         AS failed_count,
    COUNT(DISTINCT provider)                          AS provider_count
FROM site_email_log
WHERE tenant_id   = @tenant_id
  AND site_id     = @site_id
  AND created_at >= @range_from
  AND created_at <= @range_to;

-- name: GetEmailStatsByDay :many
-- Per-site daily time-series for the stats dashboard.
SELECT
    date_trunc('day', created_at AT TIME ZONE 'UTC')::timestamptz AS day,
    COUNT(*)                                          AS total,
    COUNT(*) FILTER (WHERE status = 'sent')           AS sent_count,
    COUNT(*) FILTER (WHERE status = 'failed')         AS failed_count
FROM site_email_log
WHERE tenant_id   = @tenant_id
  AND site_id     = @site_id
  AND created_at >= @range_from
  AND created_at <= @range_to
GROUP BY 1
ORDER BY 1 ASC;

-- name: GetEmailStatsByProvider :many
-- Per-site provider breakdown for the stats dashboard.
SELECT
    provider,
    COUNT(*)                                          AS total,
    COUNT(*) FILTER (WHERE status = 'sent')           AS sent_count,
    COUNT(*) FILTER (WHERE status = 'failed')         AS failed_count
FROM site_email_log
WHERE tenant_id   = @tenant_id
  AND site_id     = @site_id
  AND created_at >= @range_from
  AND created_at <= @range_to
GROUP BY provider
ORDER BY total DESC;

-- name: GetFleetEmailStats :one
-- Tenant-wide summary (fleet dashboard).
SELECT
    COUNT(*)                                          AS total,
    COUNT(*) FILTER (WHERE status = 'sent')           AS sent_count,
    COUNT(*) FILTER (WHERE status = 'failed')         AS failed_count,
    COUNT(DISTINCT provider)                          AS provider_count,
    COUNT(DISTINCT site_id)                           AS site_count
FROM site_email_log
WHERE tenant_id   = @tenant_id
  AND created_at >= @range_from
  AND created_at <= @range_to;

-- name: GetFleetEmailStatsByDay :many
-- Fleet daily time-series (tenant-wide).
SELECT
    date_trunc('day', created_at AT TIME ZONE 'UTC')::timestamptz AS day,
    COUNT(*)                                          AS total,
    COUNT(*) FILTER (WHERE status = 'sent')           AS sent_count,
    COUNT(*) FILTER (WHERE status = 'failed')         AS failed_count
FROM site_email_log
WHERE tenant_id   = @tenant_id
  AND created_at >= @range_from
  AND created_at <= @range_to
GROUP BY 1
ORDER BY 1 ASC;

-- name: DeleteEmailLogsOlderThan :execrows
-- Retention pruner: deletes rows older than the supplied cutoff timestamp.
-- Batched via LIMIT so a single invocation does not lock the table for too long.
-- The worker calls this in a loop until 0 rows are deleted.
-- Cross-tenant (InAgentTx): the agent policy allows full-table access.
DELETE FROM site_email_log
WHERE id IN (
    SELECT l.id
    FROM site_email_log l
    JOIN site_email_config c
      ON c.tenant_id = l.tenant_id
     AND c.site_id   = l.site_id
    WHERE l.created_at < @cutoff_ts::timestamptz
       OR (
            -- Per-site retention: delete when older than the config's retention_days.
            -- Falls back to 14 days when no per-site config exists (subquery returns NULL).
            l.created_at < now() - (COALESCE(
                (SELECT retention_days FROM site_email_config
                 WHERE tenant_id = l.tenant_id AND site_id = l.site_id
                 LIMIT 1), 14
            ) * INTERVAL '1 day')
          )
    LIMIT @batch_size::bigint
);

-- ---------------------------------------------------------------------------
-- email_suppression  (Phase 4a — webhooks + suppression list)
-- ---------------------------------------------------------------------------

-- name: UpsertEmailSuppression :one
-- Insert-or-ignore for one email suppression entry keyed on
-- (tenant_id, COALESCE(site_id,'00000000-0000-0000-0000-000000000000'), email_hash).
-- The two partial unique indexes enforce the uniqueness separately for per-site
-- and fleet-wide (site_id IS NULL) rows. ON CONFLICT DO UPDATE refreshes the
-- mutable fields so a duplicate bounce from a different provider still records
-- the most recent provider + event_at.
-- Runs under InAgentTx (webhook path) or InTenantTx (operator manual-add).
INSERT INTO email_suppression (
    tenant_id, site_id,
    email_hash, email,
    reason, provider, event_at, source_message_id
) VALUES (
    @tenant_id, @site_id,
    @email_hash, @email,
    @reason, @provider, @event_at, @source_message_id
)
ON CONFLICT (tenant_id, site_id, email_hash)
    WHERE site_id IS NOT NULL
DO UPDATE SET
    reason            = EXCLUDED.reason,
    provider          = EXCLUDED.provider,
    event_at          = EXCLUDED.event_at,
    source_message_id = EXCLUDED.source_message_id
RETURNING *;

-- name: UpsertEmailSuppressionFleet :one
-- Fleet-wide (site_id IS NULL) variant — separate query because the conflict
-- target differs (the fleet-wide partial index has no site_id column).
INSERT INTO email_suppression (
    tenant_id, site_id,
    email_hash, email,
    reason, provider, event_at, source_message_id
) VALUES (
    @tenant_id, NULL,
    @email_hash, @email,
    @reason, @provider, @event_at, @source_message_id
)
ON CONFLICT (tenant_id, email_hash)
    WHERE site_id IS NULL
DO UPDATE SET
    reason            = EXCLUDED.reason,
    provider          = EXCLUDED.provider,
    event_at          = EXCLUDED.event_at,
    source_message_id = EXCLUDED.source_message_id
RETURNING *;

-- name: GetEmailSuppression :one
-- Fetch a single suppression entry by id (operator detail).
SELECT *
FROM email_suppression
WHERE id        = @id
  AND tenant_id = @tenant_id;

-- name: IsSuppressed :one
-- Returns true when the given email_hash is suppressed for this tenant at either
-- the fleet level (site_id IS NULL) or the specific site.
-- Runs under InAgentTx (pre-send check from the delta-fetch query) or InTenantTx.
SELECT EXISTS (
    SELECT 1 FROM email_suppression
    WHERE tenant_id  = @tenant_id
      AND email_hash = @email_hash
      AND (site_id IS NULL OR site_id = @site_id)
) AS suppressed;

-- name: ListEmailSuppression :many
-- Keyset-paginated list of suppression entries for a site.
-- Pass site_id = uuid.Nil to list fleet-wide entries only (site_id IS NULL).
-- Pass a real site_id to list that site's entries (plus fleet-wide entries).
-- @include_fleet when true also returns fleet-wide (site_id IS NULL) rows.
-- Ordered created_at DESC, id DESC (composite keyset; see wpmgr-keyset-cursor-composite).
SELECT *
FROM email_suppression
WHERE tenant_id = @tenant_id
  AND (
        (@include_fleet::boolean AND site_id IS NULL)
        OR site_id = @site_id
  )
  AND (created_at, id) < (@cursor_ts::timestamptz, @cursor_id::uuid)
  AND (@filter_reason::text = '' OR reason = @filter_reason::text)
ORDER BY created_at DESC, id DESC
LIMIT @row_limit;

-- name: ListFleetEmailSuppression :many
-- Fleet-scope list (no site filter). Returns all suppression entries for the
-- tenant including both fleet-wide and per-site entries.
-- Ordered created_at DESC, id DESC.
SELECT *
FROM email_suppression
WHERE tenant_id = @tenant_id
  AND (created_at, id) < (@cursor_ts::timestamptz, @cursor_id::uuid)
  AND (@filter_reason::text = '' OR reason = @filter_reason::text)
ORDER BY created_at DESC, id DESC
LIMIT @row_limit;

-- name: DeleteEmailSuppression :exec
-- Operator delete (un-suppress). Must be tenant-scoped (InTenantTx).
DELETE FROM email_suppression
WHERE id        = @id
  AND tenant_id = @tenant_id;

-- name: ListEmailSuppressionDeltas :many
-- Agent suppression-fetch: returns entries created after @since_id for
-- the given tenant + site (including fleet-wide site_id IS NULL rows).
-- Keyset cursor on (created_at, id) ASC (agent polls for new entries since last fetch).
-- @since_ts / @since_id: last seen row; pass epoch-start + uuid-zero for the first fetch.
SELECT *
FROM email_suppression
WHERE tenant_id = @tenant_id
  AND (site_id IS NULL OR site_id = @site_id)
  AND (created_at, id) > (@since_ts::timestamptz, @since_id::uuid)
ORDER BY created_at ASC, id ASC
LIMIT @row_limit;

-- ---------------------------------------------------------------------------
-- email_webhook_events  (Phase 4a — dedup / audit)
-- ---------------------------------------------------------------------------

-- name: InsertWebhookEventDedup :one
-- Inserts a dedup sentinel for a provider event. Returns (row, inserted).
-- ON CONFLICT DO NOTHING: if 0 rows are affected the event is a duplicate and
-- must be dropped. Runs under InAgentTx (webhook path).
-- m61: stores email_hash (SHA-256) instead of plaintext email (SHOULD-FIX #2).
INSERT INTO email_webhook_events (
    provider_event_id, provider,
    tenant_id, site_id,
    email_hash, event_type, suppression_id
) VALUES (
    @provider_event_id, @provider,
    @tenant_id, @site_id,
    @email_hash, @event_type, @suppression_id
)
ON CONFLICT (provider, provider_event_id) DO NOTHING
RETURNING *;

-- name: PruneWebhookEventDedup :execrows
-- Prune dedup rows older than the given cutoff (run by the GC worker).
-- Cross-tenant / InAgentTx.
DELETE FROM email_webhook_events
WHERE created_at < @cutoff_ts::timestamptz;

-- ---------------------------------------------------------------------------
-- site_email_log  Phase 4a additions
-- ---------------------------------------------------------------------------

-- name: MarkEmailLogBounced :exec
-- Update a log entry's status to 'bounced' or 'complained' when a webhook
-- event arrives. Matched by message_id + tenant_id + site_id.
-- m61 SHOULD-FIX #3: site_id added so a colliding message_id from a different
-- site in the same tenant cannot flip another site's row.
-- Runs under InAgentTx; tenant_id provided for defense-in-depth in addition to
-- RLS; site_id added to narrow the update scope.
UPDATE site_email_log
SET status     = @status,
    updated_at = now()
WHERE message_id = @message_id
  AND tenant_id  = @tenant_id
  AND site_id    = @site_id;

-- name: IncrEmailLogResentCount :exec
-- Increment resent_count on a specific log entry. Runs under InTenantTx.
UPDATE site_email_log
SET resent_count = resent_count + 1,
    updated_at   = now()
WHERE id        = @id
  AND tenant_id = @tenant_id
  AND site_id   = @site_id;

-- name: GetEmailLogBodyStored :one
-- Fetch only the body_stored flag + id for a resend gate check.
SELECT id, body_stored
FROM site_email_log
WHERE id        = @id
  AND tenant_id = @tenant_id
  AND site_id   = @site_id;

-- name: DeleteEmailLogsBulk :execrows
-- Bulk delete of log entries by id list. Must be tenant+site scoped (InTenantTx).
-- The ids array is the caller-supplied list; RLS provides the second line of defence.
DELETE FROM site_email_log
WHERE tenant_id = @tenant_id
  AND site_id   = @site_id
  AND id = ANY(@ids::uuid[]);
