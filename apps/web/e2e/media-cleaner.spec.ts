import { test, expect, type Page, type Route } from "@playwright/test";

// Hermetic tests for the Media Cleaner panel (#190).
// All backend endpoints are route-mocked; no live control plane is required.

const ME = {
  user: {
    id: "33333333-3333-3333-3333-333333333333",
    email: "admin@example.com",
    name: "Admin",
    created_at: "2026-01-01T00:00:00Z",
    updated_at: "2026-01-01T00:00:00Z",
  },
  memberships: [
    {
      user_id: "33333333-3333-3333-3333-333333333333",
      tenant_id: "22222222-2222-2222-2222-222222222222",
      role: "owner",
    },
  ],
  active_tenant_id: "22222222-2222-2222-2222-222222222222",
};

const SITE = {
  id: "11111111-1111-1111-1111-111111111111",
  tenant_id: "22222222-2222-2222-2222-222222222222",
  url: "https://example.com",
  name: "Example Site",
  status: "active",
  wp_version: "6.7.1",
  php_version: "8.3",
  health_status: "healthy",
  server_info: "nginx/1.25",
  multisite: false,
  active_theme: "twentytwentyfour",
  tags: [],
  enrolled: true,
  enrolled_at: "2026-05-20T00:00:00Z",
  last_seen_at: new Date(Date.now() - 2 * 60_000).toISOString(),
  components: { plugins: [], themes: [] },
  created_at: "2026-01-01T00:00:00Z",
  updated_at: "2026-01-02T00:00:00Z",
};

const CANDIDATE_A = {
  id: 42,
  title: "hero-banner",
  url: "https://example.com/wp-content/uploads/2024/01/hero-banner.jpg",
  thumb: null,
  file_size: 204800,
  sizes_count: 3,
};

const CANDIDATE_B = {
  id: 57,
  title: "old-product-shot",
  url: "https://example.com/wp-content/uploads/2024/03/old-product-shot.png",
  thumb: "https://example.com/wp-content/uploads/2024/03/old-product-shot-150x150.png",
  file_size: 512000,
  sizes_count: 5,
};

const MANIFEST_ID = "abcdef0123456789abcdef0123456789";

async function mountCommon(page: Page): Promise<void> {
  await page.route("**/api/v1/me", (r: Route) => r.fulfill({ json: ME }));
  await page.route("**/api/v1/sites/" + SITE.id, (r: Route) =>
    r.fulfill({ json: SITE }),
  );
  await page.route("**/api/v1/sites?**", (r: Route) =>
    r.fulfill({ json: { items: [SITE], total: 1, limit: 50, offset: 0 } }),
  );
  // Snapshot + search-replace panels stub — avoid unhandled network errors.
  await page.route(
    "**/api/v1/sites/11111111-1111-1111-1111-111111111111/perf/db/snapshots",
    (r: Route) => r.fulfill({ json: { ok: true, snapshots: [] } }),
  );
}

const SCAN_URL =
  "**/api/v1/sites/11111111-1111-1111-1111-111111111111/media/clean/scan**";
const ISOLATE_URL =
  "**/api/v1/sites/11111111-1111-1111-1111-111111111111/media/clean/isolate";
const RESTORE_URL =
  "**/api/v1/sites/11111111-1111-1111-1111-111111111111/media/clean/restore";
const DELETE_URL =
  "**/api/v1/sites/11111111-1111-1111-1111-111111111111/media/clean/delete";

const TOOLS_PATH = "/sites/11111111-1111-1111-1111-111111111111/tools";

test.describe("Media Cleaner panel", () => {
  test("shows panel heading and backup advisory on load", async ({ page }) => {
    await mountCommon(page);
    await page.goto(TOOLS_PATH);

    await expect(
      page.getByRole("heading", { name: "Unused Image Cleaner" }),
    ).toBeVisible();

    await expect(
      page.getByText(/Back up your site before isolating/),
    ).toBeVisible();
  });

  test("initial state shows no-scan-yet callout before Scan is clicked", async ({
    page,
  }) => {
    await mountCommon(page);
    await page.goto(TOOLS_PATH);

    // Before Scan is clicked no scan request is made; empty callout is shown.
    await expect(
      page.getByText(/Click Scan to check your media library/),
    ).toBeVisible();
  });

  test("Scan button calls scan endpoint and renders candidate rows", async ({
    page,
  }) => {
    await mountCommon(page);
    await page.route(SCAN_URL, (r: Route) =>
      r.fulfill({
        json: {
          ok: true,
          total: 2,
          candidates: [CANDIDATE_A, CANDIDATE_B],
          has_more: false,
        },
      }),
    );

    await page.goto(TOOLS_PATH);
    await page.getByRole("button", { name: /Scan/i }).click();

    // Both candidates visible by title
    await expect(page.getByText("hero-banner")).toBeVisible();
    await expect(page.getByText("old-product-shot")).toBeVisible();

    // File size formatted
    await expect(page.getByText("200.0 KB")).toBeVisible();

    // Sizes count badge
    await expect(page.getByText(/\+ 3 sizes?/)).toBeVisible();
  });

  test("empty-result state when scan returns no candidates", async ({
    page,
  }) => {
    await mountCommon(page);
    await page.route(SCAN_URL, (r: Route) =>
      r.fulfill({
        json: { ok: true, total: 0, candidates: [], has_more: false },
      }),
    );

    await page.goto(TOOLS_PATH);
    await page.getByRole("button", { name: /Scan/i }).click();

    await expect(
      page.getByText(/No unused attachments found/),
    ).toBeVisible();
  });

  test("scan error state shows Retry button", async ({ page }) => {
    await mountCommon(page);
    await page.route(SCAN_URL, (r: Route) =>
      r.fulfill({ status: 500, json: { code: "internal", message: "boom" } }),
    );

    await page.goto(TOOLS_PATH);
    await page.getByRole("button", { name: /Scan/i }).click();

    await expect(page.getByText("Scan failed.")).toBeVisible();
    await expect(page.getByRole("button", { name: "Retry" })).toBeVisible();
  });

  test("selecting a candidate row enables Isolate selected button", async ({
    page,
  }) => {
    await mountCommon(page);
    await page.route(SCAN_URL, (r: Route) =>
      r.fulfill({
        json: {
          ok: true,
          total: 1,
          candidates: [CANDIDATE_A],
          has_more: false,
        },
      }),
    );

    await page.goto(TOOLS_PATH);
    await page.getByRole("button", { name: /Scan/i }).click();

    await expect(page.getByText("hero-banner")).toBeVisible();

    // Check the checkbox for CANDIDATE_A
    await page.getByRole("checkbox", { name: "Select hero-banner" }).check();

    // Bulk Isolate button should now be visible
    await expect(
      page.getByRole("button", { name: /Isolate 1 selected/i }),
    ).toBeVisible();
  });

  test("Isolate opens confirm dialog with reversibility note", async ({
    page,
  }) => {
    await mountCommon(page);
    await page.route(SCAN_URL, (r: Route) =>
      r.fulfill({
        json: {
          ok: true,
          total: 1,
          candidates: [CANDIDATE_A],
          has_more: false,
        },
      }),
    );

    await page.goto(TOOLS_PATH);
    await page.getByRole("button", { name: /Scan/i }).click();
    await expect(page.getByText("hero-banner")).toBeVisible();

    // Click per-row Isolate button
    await page.getByRole("button", { name: "Isolate hero-banner" }).click();

    // Dialog appears
    await expect(page.getByRole("dialog")).toBeVisible();
    await expect(
      page.getByRole("heading", { name: /Isolate 1 attachment/ }),
    ).toBeVisible();
    // Reversibility note
    await expect(page.getByText(/This is reversible/)).toBeVisible();
  });

  test("confirming Isolate calls isolate endpoint and switches to Quarantine tab", async ({
    page,
  }) => {
    await mountCommon(page);
    await page.route(SCAN_URL, (r: Route) =>
      r.fulfill({
        json: {
          ok: true,
          total: 1,
          candidates: [CANDIDATE_A],
          has_more: false,
        },
      }),
    );
    await page.route(ISOLATE_URL, (r: Route) =>
      r.fulfill({
        json: {
          ok: true,
          job_id: "some-job-id",
          moved: 4,
          manifest_id: MANIFEST_ID,
        },
      }),
    );

    await page.goto(TOOLS_PATH);
    await page.getByRole("button", { name: /Scan/i }).click();
    await expect(page.getByText("hero-banner")).toBeVisible();

    await page.getByRole("button", { name: "Isolate hero-banner" }).click();
    await expect(page.getByRole("dialog")).toBeVisible();

    // Confirm isolation
    await page
      .getByRole("button", { name: /Isolate 1 attachment/ })
      .last()
      .click();

    // After success the Quarantine tab should be active
    await expect(
      page.getByText(new RegExp(MANIFEST_ID.slice(0, 8))),
    ).toBeVisible();
  });

  test("Quarantine tab shows session-loss advisory", async ({ page }) => {
    await mountCommon(page);
    await page.route(SCAN_URL, (r: Route) =>
      r.fulfill({
        json: {
          ok: true,
          total: 1,
          candidates: [CANDIDATE_A],
          has_more: false,
        },
      }),
    );
    await page.route(ISOLATE_URL, (r: Route) =>
      r.fulfill({
        json: { ok: true, job_id: "j", moved: 1, manifest_id: MANIFEST_ID },
      }),
    );

    await page.goto(TOOLS_PATH);
    await page.getByRole("button", { name: /Scan/i }).click();
    await expect(page.getByText("hero-banner")).toBeVisible();
    await page.getByRole("button", { name: "Isolate hero-banner" }).click();
    await page.getByRole("button", { name: /Isolate 1 attachment/ }).last().click();

    // Session-loss advisory must be visible
    await expect(
      page.getByText(/Refreshing the page clears this list/),
    ).toBeVisible();
  });

  test("Delete button on quarantine row opens confirm dialog requiring DELETE", async ({
    page,
  }) => {
    await mountCommon(page);
    await page.route(SCAN_URL, (r: Route) =>
      r.fulfill({
        json: {
          ok: true,
          total: 1,
          candidates: [CANDIDATE_A],
          has_more: false,
        },
      }),
    );
    await page.route(ISOLATE_URL, (r: Route) =>
      r.fulfill({
        json: { ok: true, job_id: "j", moved: 2, manifest_id: MANIFEST_ID },
      }),
    );

    await page.goto(TOOLS_PATH);
    await page.getByRole("button", { name: /Scan/i }).click();
    await expect(page.getByText("hero-banner")).toBeVisible();
    await page.getByRole("button", { name: "Isolate hero-banner" }).click();
    await page.getByRole("button", { name: /Isolate 1 attachment/ }).last().click();

    // Now on Quarantine tab — click the per-row delete icon
    await page
      .getByRole("button", { name: "Permanently delete quarantined attachment" })
      .click();

    await expect(page.getByRole("dialog")).toBeVisible();
    await expect(
      page.getByRole("heading", { name: /Permanently delete/ }),
    ).toBeVisible();

    // Delete permanently button disabled before typing
    const deleteBtn = page.getByRole("button", { name: "Delete permanently" });
    await expect(deleteBtn).toBeDisabled();

    // Type wrong text — stays disabled
    await page.getByLabel(/Type.*to confirm/).fill("delete");
    await expect(deleteBtn).toBeDisabled();

    // Type exact word — enabled
    await page.getByLabel(/Type.*to confirm/).fill("DELETE");
    await expect(deleteBtn).toBeEnabled();
  });

  test("confirming permanent delete calls delete endpoint and removes batch from list", async ({
    page,
  }) => {
    await mountCommon(page);
    await page.route(SCAN_URL, (r: Route) =>
      r.fulfill({
        json: {
          ok: true,
          total: 1,
          candidates: [CANDIDATE_A],
          has_more: false,
        },
      }),
    );
    await page.route(ISOLATE_URL, (r: Route) =>
      r.fulfill({
        json: { ok: true, job_id: "j", moved: 4, manifest_id: MANIFEST_ID },
      }),
    );
    await page.route(DELETE_URL, (r: Route) =>
      r.fulfill({ json: { ok: true, job_id: "j2", deleted: 1 } }),
    );

    await page.goto(TOOLS_PATH);
    await page.getByRole("button", { name: /Scan/i }).click();
    await expect(page.getByText("hero-banner")).toBeVisible();
    await page.getByRole("button", { name: "Isolate hero-banner" }).click();
    await page.getByRole("button", { name: /Isolate 1 attachment/ }).last().click();

    // Delete
    await page
      .getByRole("button", { name: "Permanently delete quarantined attachment" })
      .click();
    await page.getByLabel(/Type.*to confirm/).fill("DELETE");
    await page.getByRole("button", { name: "Delete permanently" }).click();

    // Quarantine tab should now show empty state
    await expect(
      page.getByText(/No quarantined items in this session/),
    ).toBeVisible();
  });

  test("Restore button calls restore endpoint and removes batch from quarantine list", async ({
    page,
  }) => {
    await mountCommon(page);
    await page.route(SCAN_URL, (r: Route) =>
      r.fulfill({
        json: {
          ok: true,
          total: 1,
          candidates: [CANDIDATE_A],
          has_more: false,
        },
      }),
    );
    await page.route(ISOLATE_URL, (r: Route) =>
      r.fulfill({
        json: { ok: true, job_id: "j", moved: 4, manifest_id: MANIFEST_ID },
      }),
    );
    await page.route(RESTORE_URL, (r: Route) =>
      r.fulfill({ json: { ok: true, job_id: "j3", restored: 4 } }),
    );

    await page.goto(TOOLS_PATH);
    await page.getByRole("button", { name: /Scan/i }).click();
    await expect(page.getByText("hero-banner")).toBeVisible();
    await page.getByRole("button", { name: "Isolate hero-banner" }).click();
    await page.getByRole("button", { name: /Isolate 1 attachment/ }).last().click();

    // Restore
    await page
      .getByRole("button", { name: "Restore attachment files from quarantine" })
      .click();

    // Quarantine tab should now show empty state
    await expect(
      page.getByText(/No quarantined items in this session/),
    ).toBeVisible();
  });

  test("select-all toggle selects all candidates and enables bulk Isolate", async ({
    page,
  }) => {
    await mountCommon(page);
    await page.route(SCAN_URL, (r: Route) =>
      r.fulfill({
        json: {
          ok: true,
          total: 2,
          candidates: [CANDIDATE_A, CANDIDATE_B],
          has_more: false,
        },
      }),
    );

    await page.goto(TOOLS_PATH);
    await page.getByRole("button", { name: /Scan/i }).click();
    await expect(page.getByText("hero-banner")).toBeVisible();

    // Click select-all
    await page.getByRole("button", { name: "Select all" }).click();

    // Bulk isolate should now show 2
    await expect(
      page.getByRole("button", { name: /Isolate 2 selected/i }),
    ).toBeVisible();

    // Click again — deselect all
    await page.getByRole("button", { name: "Deselect all" }).click();
    await expect(
      page.getByRole("button", { name: /Isolate 2 selected/i }),
    ).not.toBeVisible();
  });
});
