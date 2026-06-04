# ADR-047 — Hand-written Gin routes for the Performance Suite (OpenAPI exception)

**Status:** Accepted
**Date:** 2026-06-04
**Relates:** ADR-046 (Performance Suite architecture), ADR-038 (SSE channel scoping)

## Context

WPMgr uses a contract-first OpenAPI pipeline for its core API:
`packages/openapi/openapi.yaml` is the single source of truth, ogen generates the
Go server interface (`internal/api/gen/`), and `@hey-api/openapi-ts` generates the
TypeScript client (`packages/openapi-client/`). The ogen router is intentionally
NOT mounted — only Gin is used. Every new domain normally adds its paths to the
spec and regenerates.

The Performance Suite (ADR-046) was built under the "scan / media" convention
adopted for earlier velocity-sensitive features: hand-rolled Gin handlers with
local DTO structs and `c.JSON`, no ogen types. The initial cache and RUCSS routes
were later promoted into the spec to give the TypeScript client typed wrappers for
the high-frequency dashboard calls. The Database Cleaner Phase 3 routes (five
endpoints) were shipped at the same time as that promotion and were left out of the
spec for two reasons:

1. The TS web layer was already calling them via the raw `client.get` / `client.post`
   transport from the `@wpmgr/api` package, not via generated typed functions; the
   absence of generated wrappers was not blocking any consumer.
2. The schemas for these endpoints are complex (nested orphan items, per-table DDL
   results, tenant-level aggregates) and the destructive routes carry multi-layer
   permission gates (route-level + handler-body) that do not model cleanly in an
   OpenAPI `security` block. Freezing the schemas before the surface stabilized
   would create churn in the generated files.

The result is a bifurcated state: most perf routes are in the spec; five are not.
This ADR documents that state, explains the governance rule for future routes, and
establishes the documentation contract for hand-written routes.

## Decision

### 1. The five Phase 3 Database Cleaner routes remain hand-written Gin for now

These routes are served by `apps/api/internal/perf/handler.go` via Gin and are
documented in `docs/api/perf.md` rather than `packages/openapi/openapi.yaml`:

| Route | Phase | Notes |
|-------|-------|-------|
| `GET  /api/v1/sites/{siteId}/perf/db/health` | P3.4 | DB-size trend + growth summary |
| `GET  /api/v1/sites/{siteId}/perf/db/orphans` | P3.5 | Corpus-classified orphan report |
| `POST /api/v1/sites/{siteId}/perf/db/table-action` | P2.2/2.5 | Per-table DDL (optimize / repair / drop / empty / analyze / convert_innodb) |
| `POST /api/v1/sites/{siteId}/perf/db/orphan-delete` | P3.8 | Destructive orphan deletion (async, re-classify + confirm gate) |
| `GET  /api/v1/perf/db/fleet-health` | P3.7 | Tenant-level aggregate (org-scope only) |

### 2. Governance rule: when a route belongs in the spec versus hand-written

A route MUST be added to `packages/openapi/openapi.yaml` when any of the following
are true:

- The TypeScript dashboard needs a typed wrapper from `@wpmgr/api` (i.e. the route
  is called with the generated function, not `client.get` / `client.post`).
- The route surface is stable: request and response shapes are unlikely to change
  within the next two releases.
- External integrators (API key users, CI pipelines, third-party dashboards) are
  the primary consumers and benefit from generated client bindings.
- The route is simple enough that the OpenAPI schema adds value without obscuring
  the multi-layer permission semantics.

A route MAY remain hand-written when all of the following are true:

- The consumer calls it via raw HTTP (no typed wrapper needed yet).
- The schema is actively evolving (new fields expected soon).
- The permission model involves handler-body gates that are hard to represent
  accurately in OpenAPI `security` blocks.
- The route was shipped under the "velocity-first" convention and the TS layer
  already works without regeneration.

Hand-written routes MUST be documented in `docs/api/perf.md` (or the relevant
domain's `docs/api/<domain>.md` file) with the full method, path, permission,
request body, response body, and any advisory headers.

### 3. Promotion path

When a hand-written route meets all three of the "MUST be in spec" criteria, it
is promoted by:

1. Adding the path and schemas to `packages/openapi/openapi.yaml`.
2. Running `go generate ./internal/api/gen/...` to update the Go ogen types.
3. Running `pnpm -C packages/openapi-client generate` to update the TS client.
4. Replacing the raw `client.get` / `client.post` call in the web hooks with the
   generated typed function.
5. Removing the hand-written TS type stubs that were duplicating the schema.
6. Verifying `go build ./...` and the web typecheck both pass.

The five Phase 3 routes are candidates for promotion once the DB Cleaner surface
stabilizes (no new fields expected for two consecutive releases).

## Consequences

- The five Phase 3 routes have no ogen-generated Go types or TS wrappers. The web
  layer uses `client.get` / `client.post` with locally-declared TypeScript
  interfaces. This is the same pattern used by the security, scan, and diagnostics
  domains before they were promoted.
- `docs/api/perf.md` is the authoritative reference for all five routes. It must be
  kept in sync with `apps/api/internal/perf/handler.go` and `perf/model.go`
  whenever the request/response shape changes.
- The build is not affected: ogen only generates code for paths declared in the
  spec; adding hand-written Gin routes does not touch the generated files.
- Future engineers adding a new DB Cleaner route must apply the governance rule
  above before deciding where to put it.
