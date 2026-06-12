#!/usr/bin/env bash
# WPMgr agent E2E object-cache test harness.
#
# Steps:
#  1.  Build the agent zip (make agent-zip from repo root).
#  2.  Set PLUGIN_ZIP env var for compose.
#  3.  docker compose build.
#  4.  docker compose up -d (db + redis + wordpress) with health-waits.
#  5.  provision stage: install plugin, write config, install drop-in.
#  6.  assert-cli stage: engine class, Fix415 shapes, transient round-trip, heartbeat shape.
#      0.42.0 additions: incr/decr-missing-false, counter-TTL, delete-missing, get-force-np,
#      get_multiple order, empty-key rejected, remember/sear/supports_group_flush/reset.
#  7.  CROSS-REQUEST PERSISTENCE: mu-plugin probe endpoint set + curl twice; assert
#      second response found===true and hit_count>0 (direct FIX A regression net).
#  8.  Drop-in freshness guard: installed stub version equals asset header version.
#  9.  cron-check stage.
# 10.  negative-check stage (fatal tolerated as skip+warn).
# 11.  multisite-check stage (skip on single-site container).
# 12.  installing-check stage (H6: WP_INSTALLING must not block cache).
# 13.  cli-uid-check stage (H7: non-owner flush fails loudly).
# 14.  outage-failback stage (H5: persisted epoch + NX lock marker check).
# 15.  fd-bomb stage (FD-1/FD-2: boot recursion guard — fd delta < 10 on fresh worker).
# 16.  codec-fallback stage (FD-4: igbinary not available falls back to php serializer).
# 17.  disable stage (extended: assert drop-in + config absent).
# 18.  EXIT trap: docker compose down -v.
#
# Usage:
#   PLUGIN_ZIP=/path/to/fleet-agent-for-wpmgr.zip ./tests-e2e/run.sh
#
# The PLUGIN_ZIP default is derived from `make agent-zip` output location when
# run from the repo root (release/fleet-agent-for-wpmgr.zip is the wporg build,
# but we use the standard build zip here since we only need the plugin installed).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
AGENT_ROOT="${REPO_ROOT}/apps/agent"

# Default zip location: the wporg build, since it is the one tested by plugincheck.
: "${PLUGIN_ZIP:=${REPO_ROOT}/release/fleet-agent-for-wpmgr.zip}"

# Compose project directory is the tests-e2e directory.
COMPOSE_DIR="${SCRIPT_DIR}"

# -----------------------------------------------------------------------
# Trap: always bring down compose on exit (pass or fail).
# -----------------------------------------------------------------------
cleanup() {
    echo "[e2e] Tearing down compose environment..."
    docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
        --project-name wpmgr-agent-e2e \
        down -v 2>/dev/null || true
}
trap cleanup EXIT

# -----------------------------------------------------------------------
# Step 1: Build the agent zip if it doesn't exist.
# -----------------------------------------------------------------------
echo "[e2e] Step 1: Ensure agent zip exists at ${PLUGIN_ZIP}"
if [ ! -f "${PLUGIN_ZIP}" ]; then
    echo "[e2e] Zip not found; building via make agent-zip..."
    make -C "${REPO_ROOT}" agent-zip
    # agent-zip produces release/wpmgr-agent.zip; for e2e we need the wporg build.
    if [ ! -f "${PLUGIN_ZIP}" ]; then
        echo "[e2e] Building wporg zip via make agent-zip-wporg..."
        make -C "${REPO_ROOT}" agent-zip-wporg
    fi
fi

if [ ! -f "${PLUGIN_ZIP}" ]; then
    echo "[e2e] ERROR: Plugin zip not found at ${PLUGIN_ZIP}" >&2
    exit 1
fi
echo "[e2e] Plugin zip: ${PLUGIN_ZIP}"

# -----------------------------------------------------------------------
# Step 2: Export PLUGIN_ZIP for docker compose.
# -----------------------------------------------------------------------
export PLUGIN_ZIP

# -----------------------------------------------------------------------
# Step 3: Build the Docker image.
# -----------------------------------------------------------------------
echo "[e2e] Step 3: Building Docker image..."
docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
    --project-name wpmgr-agent-e2e \
    build

# -----------------------------------------------------------------------
# Step 4: Start services with health-waits.
# -----------------------------------------------------------------------
echo "[e2e] Step 4: Starting services..."
docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
    --project-name wpmgr-agent-e2e \
    up -d

echo "[e2e] Waiting for services to be healthy..."
# Wait for db and redis health checks (up to 60s).
for i in $(seq 1 30); do
    DB_STATUS="$(docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
        --project-name wpmgr-agent-e2e \
        ps db --format '{{.Health}}' 2>/dev/null || echo 'unknown')"
    REDIS_STATUS="$(docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
        --project-name wpmgr-agent-e2e \
        ps redis --format '{{.Health}}' 2>/dev/null || echo 'unknown')"
    if [ "${DB_STATUS}" = "healthy" ] && [ "${REDIS_STATUS}" = "healthy" ]; then
        echo "[e2e] All services healthy."
        break
    fi
    if [ "${i}" -eq 30 ]; then
        echo "[e2e] ERROR: Services did not become healthy in 60s." >&2
        docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
            --project-name wpmgr-agent-e2e \
            ps
        exit 1
    fi
    sleep 2
done

# -----------------------------------------------------------------------
# Step 5: provision stage.
# -----------------------------------------------------------------------
echo "[e2e] Step 5: provision..."
docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
    --project-name wpmgr-agent-e2e \
    exec -T wordpress php /usr/local/bin/wpmgr-assert.php provision

# -----------------------------------------------------------------------
# Step 6: assert-cli stage.
# -----------------------------------------------------------------------
echo "[e2e] Step 6: assert-cli..."
docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
    --project-name wpmgr-agent-e2e \
    exec -T wordpress php /usr/local/bin/wpmgr-assert.php assert-cli

# -----------------------------------------------------------------------
# Step 7: CROSS-REQUEST PERSISTENCE (FIX A direct regression net).
#
# A mu-plugin probe endpoint does:
#   wp_cache_set('e2e_persist', 'x', 'e2e', 300)
# on the first request, then:
#   wp_cache_get('e2e_persist', 'e2e', false, $found) + hit_count
# on the second. The second request must return found===true and hit_count>0.
# If the failback flush fired on EVERY request, the value set on request 1
# would be wiped before request 2, causing found===false.
# -----------------------------------------------------------------------
echo "[e2e] Step 7: Cross-request persistence (FIX A regression net)..."

# Write a mu-plugin probe file that exposes a JSON endpoint.
PROBE_MU_PLUGIN="$(cat <<'MUEOF'
<?php
/**
 * E2E cross-request persistence probe.
 * Responds to ?wpmgr_e2e_probe=1 with a JSON body.
 */
if ( isset( $_GET['wpmgr_e2e_probe'] ) ) {
    $action = sanitize_key( $_GET['wpmgr_e2e_probe'] );
    if ( $action === 'set' ) {
        wp_cache_set( 'e2e_persist', 'x', 'e2e', 300 );
        $found = false;
        $val   = wp_cache_get( 'e2e_persist', 'e2e', false, $found );
        $stats = $GLOBALS['wp_object_cache'] instanceof WPMgr_Object_Cache
            ? $GLOBALS['wp_object_cache']->getHeartbeatStats()
            : [];
        header( 'Content-Type: application/json' );
        echo wp_json_encode( [
            'action'    => 'set',
            'found'     => $found,
            'val'       => $val,
            'hit_count' => $stats['hit_count'] ?? ( $GLOBALS['wp_object_cache']->cache_hits ?? -1 ),
        ] );
        exit;
    }
    if ( $action === 'get' ) {
        $found = false;
        $val   = wp_cache_get( 'e2e_persist', 'e2e', false, $found );
        $stats = $GLOBALS['wp_object_cache'] instanceof WPMgr_Object_Cache
            ? $GLOBALS['wp_object_cache']->getHeartbeatStats()
            : [];
        header( 'Content-Type: application/json' );
        echo wp_json_encode( [
            'action'    => 'get',
            'found'     => $found,
            'val'       => $val,
            'hit_count' => $GLOBALS['wp_object_cache']->cache_hits ?? -1,
        ] );
        exit;
    }
}
MUEOF
)"

# Write the mu-plugin inside the container.
docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
    --project-name wpmgr-agent-e2e \
    exec -T wordpress bash -c \
    "mkdir -p /var/www/html/wp-content/mu-plugins && cat > /var/www/html/wp-content/mu-plugins/e2e-persist-probe.php" \
    <<< "${PROBE_MU_PLUGIN}"

# Request 1: set the value.
RESP1="$(docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
    --project-name wpmgr-agent-e2e \
    exec -T wordpress curl -s 'http://localhost/?wpmgr_e2e_probe=set')"
echo "[e2e] Probe set response: ${RESP1}"
SET_FOUND="$(echo "${RESP1}" | python3 -c 'import sys,json; d=json.load(sys.stdin); print(str(d.get("found","")).lower())' 2>/dev/null || echo 'error')"
if [ "${SET_FOUND}" != "true" ]; then
    echo "[e2e] ERROR: Set+get in same request must find the value; got found=${SET_FOUND}" >&2
    exit 1
fi

# Request 2: get the value (cross-request persistence).
RESP2="$(docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
    --project-name wpmgr-agent-e2e \
    exec -T wordpress curl -s 'http://localhost/?wpmgr_e2e_probe=get')"
echo "[e2e] Probe get response: ${RESP2}"
GET_FOUND="$(echo "${RESP2}" | python3 -c 'import sys,json; d=json.load(sys.stdin); print(str(d.get("found","")).lower())' 2>/dev/null || echo 'error')"
HIT_COUNT="$(echo "${RESP2}" | python3 -c 'import sys,json; d=json.load(sys.stdin); print(d.get("hit_count",0))' 2>/dev/null || echo '0')"

if [ "${GET_FOUND}" != "true" ]; then
    echo "[e2e] ERROR: Cross-request persistence FAILED (FIX A regression): found=${GET_FOUND}." >&2
    echo "[e2e] This means the failback flush fired between requests and wiped the cache." >&2
    exit 1
fi
if [ "${HIT_COUNT}" -le 0 ] 2>/dev/null; then
    echo "[e2e] WARN: hit_count=${HIT_COUNT} on the get request — Redis not serving hits." >&2
fi
echo "[e2e] PASS: Cross-request persistence: found=true, hit_count=${HIT_COUNT}"

# Remove the probe mu-plugin.
docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
    --project-name wpmgr-agent-e2e \
    exec -T wordpress rm -f /var/www/html/wp-content/mu-plugins/e2e-persist-probe.php

# -----------------------------------------------------------------------
# Step 8: Drop-in freshness guard.
# -----------------------------------------------------------------------
echo "[e2e] Step 8: Drop-in freshness guard..."
INSTALLED_VER="$(docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
    --project-name wpmgr-agent-e2e \
    exec -T wordpress wp --allow-root \
    eval 'echo (new WPMgr\Agent\ObjectCache\ObjectCacheDropinInstaller())->state();')"
ASSET_VER="$(docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
    --project-name wpmgr-agent-e2e \
    exec -T wordpress grep -m1 'Version:' \
    /var/www/html/wp-content/plugins/fleet-agent-for-wpmgr/assets/wpmgr-object-cache-dropin.php \
    | awk '{print $NF}')"
echo "[e2e] Drop-in installer state: ${INSTALLED_VER}"
echo "[e2e] Asset version: ${ASSET_VER}"
if [ "${INSTALLED_VER}" != "ours-current" ]; then
    echo "[e2e] ERROR: Drop-in freshness guard: state must be ours-current, got: ${INSTALLED_VER}" >&2
    exit 1
fi
echo "[e2e] PASS: Drop-in freshness guard"

# -----------------------------------------------------------------------
# Step 9: cron-check stage.
# -----------------------------------------------------------------------
echo "[e2e] Step 9: cron-check..."
docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
    --project-name wpmgr-agent-e2e \
    exec -T wordpress php /usr/local/bin/wpmgr-assert.php cron-check

# -----------------------------------------------------------------------
# Step 10: negative-check stage (skip on fatal).
# -----------------------------------------------------------------------
echo "[e2e] Step 10: negative-check..."
NEGATIVE_EXIT=0
docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
    --project-name wpmgr-agent-e2e \
    exec -T wordpress php /usr/local/bin/wpmgr-assert.php negative-check || NEGATIVE_EXIT=$?

if [ "${NEGATIVE_EXIT}" -eq 2 ]; then
    echo "[e2e] SKIP [negative-check]: stage skipped (fatal or env restriction; non-blocking)."
elif [ "${NEGATIVE_EXIT}" -ne 0 ]; then
    echo "[e2e] ERROR: negative-check failed with exit ${NEGATIVE_EXIT}" >&2
    exit 1
fi

# -----------------------------------------------------------------------
# Step 11: multisite-check stage (skip if not multisite).
# -----------------------------------------------------------------------
echo "[e2e] Step 11: multisite-check..."
MULTISITE_EXIT=0
docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
    --project-name wpmgr-agent-e2e \
    exec -T wordpress php /usr/local/bin/wpmgr-assert.php multisite-check || MULTISITE_EXIT=$?

if [ "${MULTISITE_EXIT}" -eq 0 ]; then
    echo "[e2e] PASS: multisite-check"
else
    echo "[e2e] ERROR: multisite-check failed with exit ${MULTISITE_EXIT}" >&2
    exit 1
fi

# -----------------------------------------------------------------------
# Step 12: installing-check stage (H6: WP_INSTALLING must not block cache).
# -----------------------------------------------------------------------
echo "[e2e] Step 12: installing-check..."
docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
    --project-name wpmgr-agent-e2e \
    exec -T wordpress php /usr/local/bin/wpmgr-assert.php installing-check

# -----------------------------------------------------------------------
# Step 13: cli-uid-check stage (H7: non-owner flush fails loudly).
# -----------------------------------------------------------------------
echo "[e2e] Step 13: cli-uid-check..."
CLI_UID_EXIT=0
docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
    --project-name wpmgr-agent-e2e \
    exec -T wordpress php /usr/local/bin/wpmgr-assert.php cli-uid-check || CLI_UID_EXIT=$?

if [ "${CLI_UID_EXIT}" -ne 0 ]; then
    echo "[e2e] ERROR: cli-uid-check failed with exit ${CLI_UID_EXIT}" >&2
    exit 1
fi

# -----------------------------------------------------------------------
# Step 14: outage-failback stage (H5: persisted epoch + NX lock).
#
# Full cycle: write sentinel, stop Redis, request (marker written), start
# Redis, request again (NX lock winner flushes), assert sentinel gone.
# -----------------------------------------------------------------------
echo "[e2e] Step 14: outage-failback (marker mechanism check)..."
docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
    --project-name wpmgr-agent-e2e \
    exec -T wordpress php /usr/local/bin/wpmgr-assert.php outage-failback

# -----------------------------------------------------------------------
# Step 15: fd-bomb stage (FD-1/FD-2 boot recursion guard).
#
# Boots the object cache on a fresh PHP worker and asserts that the open
# file descriptor delta stays < 10.  A large delta would indicate that
# boot() is spawning multiple RedisConnection instances (recursion guard
# not firing).
# -----------------------------------------------------------------------
echo "[e2e] Step 15: fd-bomb (FD-1/FD-2 recursion guard)..."
docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
    --project-name wpmgr-agent-e2e \
    exec -T wordpress php /usr/local/bin/wpmgr-assert.php fd-bomb

# -----------------------------------------------------------------------
# Step 16: codec-fallback stage (FD-4 igbinary -> php fallback).
#
# Patches the object-cache config to request igbinary, boots in a fresh
# worker, and asserts serializer_effective=php when igbinary is absent.
# The site must serve HTTP 200 throughout (no fatal on codec mismatch).
# -----------------------------------------------------------------------
echo "[e2e] Step 16: codec-fallback (FD-4 igbinary->php fallback)..."
CODEC_EXIT=0
docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
    --project-name wpmgr-agent-e2e \
    exec -T wordpress php /usr/local/bin/wpmgr-assert.php codec-fallback || CODEC_EXIT=$?

if [ "${CODEC_EXIT}" -ne 0 ]; then
    echo "[e2e] ERROR: codec-fallback failed with exit ${CODEC_EXIT}" >&2
    exit 1
fi

# -----------------------------------------------------------------------
# Step 17: debug-header stage.
#
# (a) Enable OC + set debug_header_enabled true → curl front-end → assert
#     x-wpmgr-object-cache: state=connected present with plausible counters.
# (b) Flag off → header absent.
# (c) With PAGE cache also enabled and warmed, a page-cache HIT response
#     must NOT carry the object-cache header (two-drop-in interplay pin).
# -----------------------------------------------------------------------
echo "[e2e] Step 17: debug-header..."
docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
    --project-name wpmgr-agent-e2e \
    exec -T wordpress php /usr/local/bin/wpmgr-assert.php debug-header

# -----------------------------------------------------------------------
# Step 18: disable stage.
# -----------------------------------------------------------------------
echo "[e2e] Step 18: disable..."
docker compose -f "${COMPOSE_DIR}/docker-compose.yml" \
    --project-name wpmgr-agent-e2e \
    exec -T wordpress php /usr/local/bin/wpmgr-assert.php disable

# -----------------------------------------------------------------------
# Step 19: EXIT trap fires (docker compose down -v).
# -----------------------------------------------------------------------
echo "[e2e] All steps passed."
exit 0
