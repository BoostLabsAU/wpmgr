-- M72 — Site screenshots.
--
-- Stores one row per managed site (PRIMARY KEY site_id) with the current
-- screenshot state, object-storage keys, and capture metadata. The screenshots
-- themselves live in object storage (GCS / S3-compat) under the key prefix
-- screenshots/{tenant_id}/{site_id}/{captured_ulid}.webp.
--
-- A ULID in the object key gives every capture a unique path (no CDN staleness);
-- the worker deletes the prior key on a successful new capture.
--
-- status:  pending  — a capture job is enqueued or in-flight.
--          ready    — last capture completed; screenshot_key is valid.
--          failed   — last capture failed; failed_reason explains why.
--
-- RLS (three policies, M3 hardening):
--   tenant_isolation policy — operator read/write (InTenantTx sets app.tenant_id).
--   agent policy            — worker write (InAgentTx sets app.agent='on').
--   site_scope RESTRICTIVE  — collaborator isolation: when app.site_scope='on',
--                             only rows whose site_id is in app.allowed_site_ids
--                             pass. Mirrors backup_snapshots_site_scope (m19).
--
-- The capture worker runs under InAgentTx (cross-tenant); the operator-facing
-- reads and manual-trigger writes run under InTenantTx.

CREATE TABLE IF NOT EXISTS site_screenshots (
    site_id          uuid        NOT NULL,
    tenant_id        uuid        NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
    screenshot_key   text        NOT NULL DEFAULT '',
    screenshot_key_2x text       NOT NULL DEFAULT '',
    width            integer     NOT NULL DEFAULT 0,
    height           integer     NOT NULL DEFAULT 0,
    status           text        NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending','ready','failed')),
    failed_reason    text,
    captured_at      timestamptz,
    etag             text,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT site_screenshots_pkey PRIMARY KEY (site_id)
);

-- Tenant isolation index (supports the RLS policy predicate).
CREATE INDEX IF NOT EXISTS site_screenshots_tenant_idx
    ON site_screenshots (tenant_id);

ALTER TABLE site_screenshots ENABLE ROW LEVEL SECURITY;
ALTER TABLE site_screenshots FORCE ROW LEVEL SECURITY;

-- Tenant read/write isolation. Mirrors site_db_clean_results exactly: nullif(...)
-- so an UNSET or EMPTY app.tenant_id GUC yields NULL (matches no rows) instead of
-- erroring or leaking, and WITH CHECK so inserts/updates are tenant-scoped too.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE tablename = 'site_screenshots'
          AND policyname = 'site_screenshots_tenant_isolation'
    ) THEN
        CREATE POLICY site_screenshots_tenant_isolation ON site_screenshots
            USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

-- Agent write policy. The screenshot capture worker runs cross-tenant under
-- InAgentTx (app.agent='on') so it can update any tenant's screenshot row.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE tablename = 'site_screenshots'
          AND policyname = 'site_screenshots_agent'
    ) THEN
        CREATE POLICY site_screenshots_agent ON site_screenshots
            USING (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;

-- M3: AS RESTRICTIVE site_scope policy — collaborator isolation.
--
-- Without this policy a site-collaborator's read isolation relies solely on the
-- caller pre-filtering the site_id. Adding a RESTRICTIVE policy makes the DB
-- itself enforce the collaborator scope: when app.site_scope='on' (collaborator
-- path set by InSiteScopeTx), only rows whose site_id is in the
-- app.allowed_site_ids GUC pass. When app.site_scope is not 'on' (normal
-- operator or agent path) the RESTRICTIVE policy is a no-op (passes all rows).
--
-- Pattern mirrors backup_snapshots_site_scope (m19, section 5c).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'site_screenshots'
          AND policyname = 'site_screenshots_site_scope'
    ) THEN
        CREATE POLICY "site_screenshots_site_scope" ON "public"."site_screenshots"
            AS RESTRICTIVE FOR ALL
            USING (
                coalesce(current_setting('app.site_scope', true), '') <> 'on'
                OR "site_id" = ANY (
                    string_to_array(
                        nullif(current_setting('app.allowed_site_ids', true), ''), ','
                    )::uuid[]
                )
            )
            WITH CHECK (
                coalesce(current_setting('app.site_scope', true), '') <> 'on'
                OR "site_id" = ANY (
                    string_to_array(
                        nullif(current_setting('app.allowed_site_ids', true), ''), ','
                    )::uuid[]
                )
            );
    END IF;
END;
$$;
