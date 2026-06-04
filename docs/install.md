# Install (self-host)

Self-host WPMgr with Docker Compose. The stack runs the control plane (Go),
dashboard (React), and data plane (Postgres, Redis, SeaweedFS, ClickHouse).

## Prerequisites

- **Docker** 24+ with the Compose plugin (`docker compose`, not `docker-compose`)
- ~2 GB free RAM for the full stack

## 1. Configure env

```bash
cp .env.example .env
```

Edit `.env`. At minimum change these before exposing the service:

```bash
WPMGR_SESSION_SECRET=...      # 32-byte base64; openssl rand -base64 32
WPMGR_DB_PASSWORD=...         # not the default
WPMGR_S3_SECRET_KEY=...       # not the default
```

Generate the Ed25519 control-plane signing keypair used for the agent protocol:

```bash
./scripts/gen-keys.sh
# writes WPMGR_AGENT_SIGNING_PRIVATE_KEY / _PUBLIC_KEY into .env
```

Key env vars (all prefixed `WPMGR_`):

| Var | Purpose | Default |
|-----|---------|---------|
| `WPMGR_HTTP_ADDR` | API listen address | `:8080` |
| `WPMGR_DB_HOST` | Postgres host | `localhost` |
| `WPMGR_DB_PORT` | Postgres port | `5432` |
| `WPMGR_DB_NAME` | Postgres database | `wpmgr` |
| `WPMGR_DB_USER` | Postgres user (the `wpmgr_app` role) | `wpmgr_app` |
| `WPMGR_DB_PASSWORD` | Password for `wpmgr_app` | (required) |
| `WPMGR_DB_MIGRATION_DSN` | Full DSN for the migration-owner role (see below) | falls back to app DSN |
| `WPMGR_REDIS_ADDR` | Redis | `localhost:6379` |
| `WPMGR_S3_ENDPOINT` | SeaweedFS S3 gateway | `http://localhost:8333` |
| `WPMGR_S3_FORCE_PATH_STYLE` | required for SeaweedFS | `true` |
| `WPMGR_CLICKHOUSE_ADDR` | ClickHouse | `localhost:9000` |
| `WPMGR_OTEL_EXPORTER_OTLP_ENDPOINT` | OTLP collector | `http://localhost:4318` |
| `VITE_API_BASE_URL` | API base for the SPA | `http://localhost:8080` |

### Postgres: two-DSN model and the `wpmgr_app` role

WPMgr uses two Postgres connection strings:

| Setting | Role | Purpose |
|---------|------|---------|
| `WPMGR_DB_*` | `wpmgr_app` (NOSUPERUSER, NOBYPASSRLS) | Runtime application queries |
| `WPMGR_DB_MIGRATION_DSN` | Database owner or superuser | Migrations (DDL, role creation) |

`WPMGR_DB_MIGRATION_DSN` accepts a full `postgres://` DSN. When unset it falls
back to the app DSN, which works for local dev where a single user can run
migrations. Production deployments should set it to a privileged owner role.

**The application must connect as `wpmgr_app` (or a similarly restricted role).
A superuser or BYPASSRLS role skips Row-Level Security entirely and the server
will refuse to boot.**

Migrations create the `wpmgr_app` role (`NOLOGIN NOSUPERUSER NOBYPASSRLS`,
idempotent) and grant it table privileges. You must enable login + set a password
out of band after the first migration run:

```sql
-- run once after migrations, as the owner role:
ALTER ROLE wpmgr_app LOGIN PASSWORD 'your-password';
```

Then set `WPMGR_DB_USER=wpmgr_app` and `WPMGR_DB_PASSWORD=your-password`.

The `plugin_signatures` corpus table (used by the Database Cleaner) is
**insert-only protected**: `wpmgr_app` has `SELECT` only at runtime. The corpus
seed migration temporarily grants itself `INSERT/UPDATE` (owner bypasses RLS),
populates the table, then REVOKEs write access from `wpmgr_app`. This
GRANT-self/REVOKE pattern means the corpus migration requires the owner role
(`WPMGR_DB_MIGRATION_DSN`); running it as `wpmgr_app` will fail the REVOKE step.

The `WPMGR_ALLOW_RLS_BYPASS_ROLE=true` env var (default `false`) downgrades
the boot-time RLS check to a warning. Intended only for single-node local dev
sharing the bootstrap superuser; never set it in production.

## 2. Bring up the stack

```bash
docker compose -f infra/docker-compose.yml up -d
```

This starts Postgres, Redis, SeaweedFS (S3 gateway on `:8333`), ClickHouse, the
API (`:8080`), and the web dashboard (served by nginx on `:80`).

## 3. Verify

```bash
curl localhost:8080/healthz   # {"status":"ok"}
curl localhost:8080/readyz    # 200 once DB/Redis/S3 are reachable
```

- `GET /healthz` — liveness (process is up).
- `GET /readyz` — readiness (dependencies reachable).

Open the dashboard at `http://localhost` (the `web` service serves the built
SPA via nginx and proxies to the API).

## Optional: Media Optimizer

Image encoding (JPEG/PNG to WebP/AVIF) runs on a separate encoder service that
is opt-in. Enable it with the `media` Compose profile:

```bash
docker compose -f infra/docker-compose.yml --profile media up -d
```

Without the profile the core API starts fine; the Media Optimizer tab in the
dashboard is unavailable. See [features/media-optimizer.md](./features/media-optimizer.md).

## Observability profile

Traces (Tempo) + metrics (Prometheus) + Grafana are opt-in via a Compose
profile (ADR-011):

```bash
docker compose -f infra/docker-compose.yml --profile observability up -d
```

Grafana then ships with the WPMgr dashboards pre-provisioned. See
[architecture.md](./architecture.md#observability).

## First-run notes

- **Migrations** run automatically on API startup (Atlas, ADR-002).
- **Default credentials in `.env.example` are for local dev only** — rotate the
  session secret, DB password, and S3 keys before any network-exposed deploy.
- Put a TLS-terminating reverse proxy (the bundled `infra/nginx/` config, or
  your own) in front of `:8080` for production.

For local development with hot-reload overrides, use `make dev` (runs
`docker-compose.yml` + `docker-compose.dev.yml`) — see
[contributing.md](./contributing.md).

## Adding a WordPress site

Once running, install the agent plugin on each managed site and pair it from the
dashboard. See [agent.md](./agent.md).

> **Note:** auto-migrations on startup and the live agent enrollment exchange
> are completed across Phase 4–5; the install steps above are stable.
