-- M40 — plugin_signatures corpus table.
--
-- Global read-only reference data: one row per WordPress.org plugin slug,
-- holding the known option/transient/table/cron-hook name patterns for that
-- plugin. Used by the DB-Cleaner classifier (P3.2+) to attribute orphaned
-- wp_options rows and WP-Cron events to their owning plugin.
--
-- KEY DESIGN DECISIONS:
--
-- 1. NO tenant_id column — this is shared reference data, not per-tenant.
--    All tenants read the same corpus; there is no per-tenant copy.
--
-- 2. ENABLE ROW LEVEL SECURITY (NOT FORCE): the migration runner connects as
--    the schema owner (a superuser or BYPASSRLS role) and must INSERT the seed
--    rows in the next migration file. FORCE RLS would apply RLS to the owner too,
--    and with no INSERT policy defined the seed INSERTs would fail. Using plain
--    ENABLE allows the owner to bypass RLS at seed time while still enforcing
--    the SELECT policy for wpmgr_app at runtime. The write guard is a Postgres
--    GRANT model: INSERT/UPDATE/DELETE are explicitly REVOKED from wpmgr_app
--    below, so the RLS layer is a second defence, not the first.
--
-- 3. SELECT policy USING (true): any authenticated session (any GUC state, any
--    role that has SELECT privilege) can read the corpus. There is no tenant
--    filter because the corpus is global.
--
-- 4. REVOKE INSERT/UPDATE/DELETE FROM wpmgr_app: the ALTER DEFAULT PRIVILEGES
--    grant in migration m1 (auth_multitenancy) gives wpmgr_app full DML on all
--    new tables created by the owner. We undo that here for this table because
--    corpus mutations are only allowed via the owner DSN at migration time.

CREATE TABLE plugin_signatures (
    slug               text        NOT NULL,
    corpus_version     integer     NOT NULL DEFAULT 1,
    option_patterns    jsonb       NOT NULL DEFAULT '[]',
    transient_patterns jsonb       NOT NULL DEFAULT '[]',
    table_patterns     jsonb       NOT NULL DEFAULT '[]',
    cron_hook_patterns jsonb       NOT NULL DEFAULT '[]',
    updated_at         timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT plugin_signatures_pkey PRIMARY KEY (slug)
);

CREATE INDEX plugin_signatures_corpus_version_idx ON plugin_signatures (corpus_version);

-- ENABLE (not FORCE): the owner bypasses RLS so the seed (m40.1) can INSERT;
-- wpmgr_app is governed by the privilege revocation in m40.1 AND by the absence
-- of a write policy.
ALTER TABLE plugin_signatures ENABLE ROW LEVEL SECURITY;

-- One permissive SELECT policy: any role that can connect and has SELECT
-- privilege on this table may read all rows (global reference data).
CREATE POLICY plugin_signatures_read ON plugin_signatures
    FOR SELECT USING (true);

-- NOTE: the REVOKE of INSERT/UPDATE/DELETE from wpmgr_app is DEFERRED to the
-- end of the seed migration (m40.1), AFTER the corpus rows are inserted. This
-- keeps the seed resilient to the single-DSN model (where the migration runner
-- IS wpmgr_app and also owns this table): the rows insert while the m1 default
-- INSERT grant is still in force, then the privilege is revoked. The end state
-- is identical under either DSN model — wpmgr_app ends with SELECT only.
