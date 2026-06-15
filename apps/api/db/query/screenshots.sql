-- M72 Screenshots queries. Every statement is tenant-scoped both explicitly
-- (tenant_id in the WHERE/VALUES) and by RLS (app.tenant_id / app.agent policy).
-- The repo wraps each call in InTenantTx (operator) or InAgentTx (worker).
-- updated_at is set here via now(); there is no trigger.

-- name: GetScreenshot :one
SELECT * FROM site_screenshots
WHERE site_id = @site_id AND tenant_id = @tenant_id;

-- name: UpsertScreenshotPending :one
-- Called by the CP (operator or enroll trigger) before enqueuing the capture job.
-- Sets status=pending, clears failed_reason; does NOT touch screenshot keys.
INSERT INTO site_screenshots (site_id, tenant_id, status, updated_at)
VALUES (@site_id, @tenant_id, 'pending', now())
ON CONFLICT (site_id) DO UPDATE
    SET status        = 'pending',
        failed_reason = NULL,
        updated_at    = now()
RETURNING *;

-- name: UpsertScreenshotReady :one
-- Called by the capture worker (InAgentTx) on a successful capture.
INSERT INTO site_screenshots (
    site_id, tenant_id,
    screenshot_key, screenshot_key_2x,
    width, height,
    status, failed_reason,
    captured_at, etag,
    updated_at
) VALUES (
    @site_id, @tenant_id,
    @screenshot_key, @screenshot_key_2x,
    @width, @height,
    'ready', NULL,
    @captured_at, @etag,
    now()
)
ON CONFLICT (site_id) DO UPDATE
    SET screenshot_key    = EXCLUDED.screenshot_key,
        screenshot_key_2x = EXCLUDED.screenshot_key_2x,
        width             = EXCLUDED.width,
        height            = EXCLUDED.height,
        status            = 'ready',
        failed_reason     = NULL,
        captured_at       = EXCLUDED.captured_at,
        etag              = EXCLUDED.etag,
        updated_at        = now()
RETURNING *;

-- name: UpsertScreenshotFailed :one
-- Called by the capture worker (InAgentTx) when a capture fails permanently.
INSERT INTO site_screenshots (site_id, tenant_id, status, failed_reason, updated_at)
VALUES (@site_id, @tenant_id, 'failed', @failed_reason, now())
ON CONFLICT (site_id) DO UPDATE
    SET status        = 'failed',
        failed_reason = EXCLUDED.failed_reason,
        updated_at    = now()
RETURNING *;

-- name: ListScreenshotsForSites :many
-- Batch lookup for the sites list: one query for N site IDs (matches the
-- ListLatestBackupsForSites / ListClientNamesForSites batched-JOIN pattern).
-- Returns only rows that exist; absent sites produce no row (treated as nil by caller).
SELECT site_id, tenant_id, screenshot_key, screenshot_key_2x,
       width, height, status, failed_reason, captured_at, etag,
       created_at, updated_at
FROM site_screenshots
WHERE tenant_id = @tenant_id
  AND site_id = ANY(@site_ids::uuid[])
ORDER BY site_id;
