-- M28 — infer the hosting/infrastructure provider for each site.
--
-- The control plane already observes the agent's real public egress IP on every
-- signed metadata/diagnostics request (the WordPress origin's outbound IP, seen
-- even behind Cloudflare). An offline ASN lookup (embedded DB-IP IP-to-ASN Lite,
-- CC BY 4.0) maps that IP to a provider name (DigitalOcean, Hetzner, AWS, ...),
-- filling the gap where the agent's PHP defined()-based HostFlags find no managed
-- host and the UI would otherwise show "Unrecognized".
--
-- This is a CP-DERIVED column (like wp_timezone), not agent-pushed: no agent
-- change, no customer IP leaves the operator's control plane. Best-effort hint;
-- a positive HostFlag always wins, so host_provider is shown only as a fallback.
--
-- Additive + idempotent (ADD COLUMN IF NOT EXISTS). Rows fill on their next
-- diagnostics push; no backfill is possible without an observed IP.

DO $$
BEGIN
    ALTER TABLE "public"."sites"
        ADD COLUMN IF NOT EXISTS "host_provider"            text NOT NULL DEFAULT '',
        ADD COLUMN IF NOT EXISTS "host_provider_ip"         text NOT NULL DEFAULT '',
        ADD COLUMN IF NOT EXISTS "host_provider_checked_at" timestamptz;
END;
$$;
