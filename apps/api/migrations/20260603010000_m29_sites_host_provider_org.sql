-- M29 — store the raw AS organization string alongside the canonical provider.
--
-- M28 stored only the canonical provider name (mapped from a small in-repo ASN
-- table) and the observed IP. When the ASN is real but not in that table, the
-- provider was "" and the UI showed "Unrecognized" even though we DO know the
-- network (e.g. "OVH SAS"). Storing the raw org lets the UI fall back to the
-- real network name and lets operators see exactly what was inferred.
--
-- Additive + idempotent. Rows fill on their next diagnostics push.

DO $$
BEGIN
    ALTER TABLE "public"."sites"
        ADD COLUMN IF NOT EXISTS "host_provider_org" text NOT NULL DEFAULT '';
END;
$$;
