import { test, expect, type Page, type Route } from "@playwright/test";

// Hermetic tests for the Database Snapshots panel (#189).
// All backend endpoints are route-mocked so the suite runs without a live
// control plane. Routes are registered newest-first so snapshot-specific
// routes win over generic site routes.

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

const SNAP_A = {
  id: "snap_aabbccddeeff00112233aabb",
  label: "Before plugin update",
  created_at: Math.floor(Date.now() / 1000) - 3600, // 1 hour ago
  size: 2_097_152,
  table_count: 12,
};

const SNAP_B = {
  id: "snap_ffeeddccbbaa00112233ffee",
  label: "",
  created_at: Math.floor(Date.now() / 1000) - 86400, // 1 day ago
  size: 1_048_576,
  table_count: 10,
};

// Helper: mount all the common API mocks.
async function mountMocks(page: Page): Promise<void> {
  await page.route("**/api/v1/me", (r: Route) =>
    r.fulfill({ json: ME }),
  );
  await page.route("**/api/v1/sites/" + SITE.id, (r: Route) =>
    r.fulfill({ json: SITE }),
  );
  await page.route("**/api/v1/sites?**", (r: Route) =>
    r.fulfill({ json: { items: [SITE], total: 1, limit: 50, offset: 0 } }),
  );
}

async function mountSnapshotList(
  page: Page,
  snapshots: typeof SNAP_A[] = [],
): Promise<void> {
  await page.route(
    "**/api/v1/sites/11111111-1111-1111-1111-111111111111/perf/db/snapshots",
    (r: Route) => r.fulfill({ json: { ok: true, snapshots } }),
  );
}

test.describe("Database Snapshots panel", () => {
  test("shows empty state when no snapshots exist", async ({ page }) => {
    await mountMocks(page);
    await mountSnapshotList(page, []);

    await page.goto("/sites/11111111-1111-1111-1111-111111111111/tools");

    // Panel heading
    await expect(
      page.getByRole("heading", { name: "Database Snapshots" }),
    ).toBeVisible();

    // Empty state hint
    await expect(
      page.getByText("No snapshots yet. Take one before a risky change."),
    ).toBeVisible();
  });

  test("lists snapshots with label, age, size, and table count", async ({
    page,
  }) => {
    await mountMocks(page);
    await mountSnapshotList(page, [SNAP_A, SNAP_B]);

    await page.goto("/sites/11111111-1111-1111-1111-111111111111/tools");

    // Labeled snapshot
    await expect(page.getByText("Before plugin update")).toBeVisible();
    // Size shown in readable form (2 MB)
    await expect(page.getByText(/2\.0\s*MB/)).toBeVisible();
    // Table count
    await expect(page.getByText(/12 tables/)).toBeVisible();
  });

  test("Take snapshot button calls create endpoint and refreshes list", async ({
    page,
  }) => {
    await mountMocks(page);
    // First call returns empty list; after create it returns SNAP_A.
    let callCount = 0;
    await page.route(
      "**/api/v1/sites/11111111-1111-1111-1111-111111111111/perf/db/snapshots",
      async (r: Route) => {
        if (r.request().method() === "GET") {
          if (callCount === 0) {
            await r.fulfill({ json: { ok: true, snapshots: [] } });
          } else {
            await r.fulfill({ json: { ok: true, snapshots: [SNAP_A] } });
          }
          callCount++;
        } else if (r.request().method() === "POST") {
          await r.fulfill({
            json: { ok: true, snapshot: SNAP_A, detail: "snapshot created" },
          });
        } else {
          await r.continue();
        }
      },
    );

    await page.goto("/sites/11111111-1111-1111-1111-111111111111/tools");

    // Empty state first
    await expect(
      page.getByText("No snapshots yet. Take one before a risky change."),
    ).toBeVisible();

    // Fill label
    await page.getByLabel("Label (optional)").fill("My test snap");

    // Click Take snapshot
    await page.getByRole("button", { name: "Take snapshot" }).click();

    // The panel should now show the snapshot from the refreshed list
    await expect(page.getByText("Before plugin update")).toBeVisible();
  });

  test("Revert button opens destructive-confirm dialog", async ({ page }) => {
    await mountMocks(page);
    await mountSnapshotList(page, [SNAP_A]);

    await page.goto("/sites/11111111-1111-1111-1111-111111111111/tools");

    // Click Revert for SNAP_A
    await page
      .getByRole("button", { name: /Revert database to snapshot/ })
      .first()
      .click();

    // Dialog appears
    await expect(
      page.getByRole("dialog"),
    ).toBeVisible();
    await expect(
      page.getByRole("heading", { name: "Revert database?" }),
    ).toBeVisible();

    // The warning copy is present
    await expect(
      page.getByText("This replaces your entire database."),
    ).toBeVisible();

    // Confirm button is initially disabled (no text typed)
    await expect(
      page.getByRole("button", { name: "Revert database" }),
    ).toBeDisabled();
  });

  test("Revert confirm requires typing the snapshot label", async ({ page }) => {
    await mountMocks(page);
    await mountSnapshotList(page, [SNAP_A]);

    await page.goto("/sites/11111111-1111-1111-1111-111111111111/tools");

    await page
      .getByRole("button", { name: /Revert database to snapshot/ })
      .first()
      .click();

    const confirmInput = page.getByLabel(
      /Type.*to confirm/,
    );
    const revertBtn = page.getByRole("button", { name: "Revert database" });

    // Wrong text → button still disabled
    await confirmInput.fill("wrong label");
    await expect(revertBtn).toBeDisabled();

    // Correct label text → button enabled
    await confirmInput.fill("Before plugin update");
    await expect(revertBtn).toBeEnabled();
  });

  test("confirmed revert calls POST /:snapshotId/revert with confirm=REVERT", async ({
    page,
  }) => {
    await mountMocks(page);
    await mountSnapshotList(page, [SNAP_A]);

    let revertBody: Record<string, unknown> = {};
    await page.route(
      `**/api/v1/sites/${SITE.id}/perf/db/snapshots/${SNAP_A.id}/revert`,
      async (r: Route) => {
        revertBody = (await r.request().postDataJSON()) as Record<string, unknown>;
        await r.fulfill({
          json: { ok: true, detail: "database reverted", safety_id: "snap_safetyXYZ" },
        });
      },
    );

    await page.goto("/sites/11111111-1111-1111-1111-111111111111/tools");

    await page
      .getByRole("button", { name: /Revert database to snapshot/ })
      .first()
      .click();

    await page.getByLabel(/Type.*to confirm/).fill("Before plugin update");
    await page.getByRole("button", { name: "Revert database" }).click();

    // Verify the correct confirm token was sent
    expect(revertBody["confirm"]).toBe("REVERT");

    // Success toast
    await expect(page.getByText("Database reverted.")).toBeVisible();
  });

  test("Delete button opens confirm dialog then calls DELETE endpoint", async ({
    page,
  }) => {
    await mountMocks(page);

    let deleteCalled = false;
    await page.route(
      "**/api/v1/sites/11111111-1111-1111-1111-111111111111/perf/db/snapshots",
      async (r: Route) => {
        if (r.request().method() === "GET") {
          await r.fulfill({ json: { ok: true, snapshots: deleteCalled ? [] : [SNAP_A] } });
        } else {
          await r.continue();
        }
      },
    );
    await page.route(
      `**/api/v1/sites/${SITE.id}/perf/db/snapshots/${SNAP_A.id}`,
      async (r: Route) => {
        if (r.request().method() === "DELETE") {
          deleteCalled = true;
          await r.fulfill({ json: { ok: true, detail: "snapshot deleted" } });
        } else {
          await r.continue();
        }
      },
    );

    await page.goto("/sites/11111111-1111-1111-1111-111111111111/tools");

    // Click the delete (trash) icon
    await page
      .getByRole("button", { name: /Delete snapshot/ })
      .first()
      .click();

    // Confirm dialog
    await expect(page.getByRole("dialog")).toBeVisible();
    await expect(
      page.getByRole("heading", { name: "Delete snapshot?" }),
    ).toBeVisible();

    // Click the destructive button
    await page.getByRole("button", { name: "Delete snapshot" }).click();

    // Success toast
    await expect(page.getByText("Snapshot deleted.")).toBeVisible();
    expect(deleteCalled).toBe(true);
  });
});
