#!/usr/bin/env bash
#
# Print the boot-critical WPMgr secrets as ready-to-paste env lines (issue #4).
#
# Emits, in the EXACT formats the control plane validates at startup:
#
#   WPMGR_SESSION_SECRET             random >=32-byte string
#   WPMGR_AGENT_SIGNING_PRIVATE_KEY  base64-std of the raw 64-byte ed25519 key
#   WPMGR_AGENT_SIGNING_PUBLIC_KEY   base64-std of the raw 32-byte ed25519 key
#   WPMGR_SITE_DEST_AGE_SECRET       age X25519 secret (AGE-SECRET-KEY-1...)
#
# The values are minted by `wpmgr-cli gen-secrets`, which generates each one with
# the SAME crypto packages the server uses and then SELF-VERIFIES it by decoding
# it back through the server's own boot-time parsers (agent.DecodePublicKey,
# agentcmd.DecodePrivateKey, cryptbox.NewAgeIdentity). That is what makes a
# printed line guaranteed-parseable.
#
# This replaces the old approach of base64-ing the whole PEM file: the runtime
# wants base64 of the RAW key bytes (32B public / 64B private), so a base64'd PEM
# decoded to the wrong size and the app rejected it. It also never minted the
# session secret or the age secret a production boot requires.
#
# This script only PRINTS to stdout; it does not touch .env. To bootstrap .env in
# one step (copy + inject only the empty keys), use scripts/init-env.sh instead.
#
# Usage:
#   ./scripts/gen-keys.sh                  # print the four env lines
#   ./scripts/gen-keys.sh >> .env          # append them to an existing .env
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

# Prefer a local Go toolchain; fall back to running the CLI inside the api image
# via Docker Compose. Both invoke the same `wpmgr-cli gen-secrets` subcommand.
if command -v go >/dev/null 2>&1; then
  ( cd "${ROOT_DIR}/apps/api" && go run ./cmd/wpmgr-cli gen-secrets )
elif command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  # The api image's entrypoint is the wpmgr binary; override it to run the CLI.
  docker compose -f "${ROOT_DIR}/infra/docker-compose.yml" \
    run --rm --no-deps --entrypoint wpmgr-cli api gen-secrets
else
  echo "gen-keys: need a Go toolchain or Docker Compose to run 'wpmgr-cli gen-secrets'." >&2
  echo "          (install Go, or bring up Docker, then re-run.)" >&2
  exit 1
fi
