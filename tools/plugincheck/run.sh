#!/usr/bin/env bash
#
# AUTHORITATIVE WordPress.org Plugin Check on a real WordPress, via Docker.
# Spins up mariadb + wordpress:cli, installs WP + the official plugin-check
# plugin, installs the plugin-under-test from its built zip, and runs
# `wp plugin check`. Exits non-zero on ANY error row.
#
# Usage:  PLUGIN_ZIP=/abs/path/to/fleet-agent-for-wpmgr.zip ./run.sh
# (driven by `make agent-plugincheck`).
#
set -euo pipefail
cd "$(dirname "$0")"

: "${PLUGIN_ZIP:?set PLUGIN_ZIP to the built fleet-agent-for-wpmgr.zip}"
[ -f "$PLUGIN_ZIP" ] || { echo "run.sh: PLUGIN_ZIP not found: $PLUGIN_ZIP" >&2; exit 2; }

SLUG="fleet-agent-for-wpmgr"
export PLUGIN_ZIP

cleanup() { docker compose down -v >/dev/null 2>&1 || true; }
trap cleanup EXIT

# The bind-mounted WP dir must be writable by the container user.
mkdir -p wp && chmod -R 777 wp

docker compose up -d

# Wait for the db healthcheck.
for _ in $(seq 1 40); do
  [ "$(docker compose ps db --format '{{.Health}}' 2>/dev/null)" = "healthy" ] && break
  sleep 3
done

# WP-CLI core-download spikes RAM during extract; raise the limit for every call.
WP() { docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp "$@"; }

WP core download --force
WP config create --dbname=wp --dbuser=root --dbpass=wp --dbhost=db --force
WP core install --url=http://localhost --title=pc \
  --admin_user=admin --admin_password=admin --admin_email=a@b.test --skip-email
WP plugin install plugin-check --activate
WP plugin install /tmp/plugin.zip --force

echo "==================== wp plugin check: $SLUG ===================="
OUT="$(WP plugin check "$SLUG" --format=csv 2>/dev/null || true)"
echo "$OUT"
echo "==============================================================="

# CSV rows are `line,column,type,code,message` grouped under `FILE:` headers.
# Fail on any ERROR row.
if printf '%s\n' "$OUT" | grep -q ',ERROR,'; then
  echo "PLUGIN CHECK FAILED: ERROR rows above. Fix or justify before shipping." >&2
  exit 1
fi
echo "Plugin Check: 0 errors. (Review any WARNING rows above.)"
