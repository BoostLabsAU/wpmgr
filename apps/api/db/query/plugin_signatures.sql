-- M40 plugin_signatures queries.
-- Global reference data (no tenant_id). The corpus table uses ENABLE RLS with a
-- permissive SELECT policy; wpmgr_app has SELECT only. These queries run under the
-- normal pool (no tenant GUC needed — any authenticated session may read).

-- name: GetPluginSignatureBySlug :one
SELECT slug, corpus_version, option_patterns, transient_patterns, table_patterns, cron_hook_patterns, updated_at
FROM plugin_signatures
WHERE slug = @slug;

-- name: AllPluginSignatures :many
SELECT slug, corpus_version, option_patterns, transient_patterns, table_patterns, cron_hook_patterns, updated_at
FROM plugin_signatures
ORDER BY slug;
