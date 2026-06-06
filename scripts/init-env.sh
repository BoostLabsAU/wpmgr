#!/usr/bin/env bash
#
# WPMgr one-command self-host bootstrap (issue #4).
#
# What it does:
#   1. Copies .env.example -> .env (only if .env is absent, unless --force).
#   2. Generates the four boot-critical secrets and injects them into .env,
#      but only into keys that are still empty (existing secrets are preserved).
#   3. Prints the next steps.
#
# The four secrets are minted by `wpmgr-cli gen-secrets`, which produces them in
# the EXACT formats the control plane validates at boot (and self-tests each one
# through the server's own decode path). The generator is run via, in order of
# preference: a local Go toolchain, then Docker Compose, then host openssl +
# age-keygen as a last resort.
#
# Idempotent and safe to re-run: an existing .env is never overwritten without
# --force, and only EMPTY secret keys are filled.
#
# Usage:
#   scripts/init-env.sh           # bootstrap (preserves an existing .env)
#   scripts/init-env.sh --force   # overwrite .env from .env.example, then fill
#
set -euo pipefail

# Resolve the repo root (this script lives in scripts/).
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT_DIR}"

ENV_FILE="${ROOT_DIR}/.env"
ENV_EXAMPLE="${ROOT_DIR}/.env.example"
GEN_FILE=""
FORCE=0

# The secret keys this script manages (filled when empty OR still the committed
# DEV placeholder — see dev_sentinel below).
SECRET_KEYS=(
  WPMGR_SESSION_SECRET
  WPMGR_AGENT_SIGNING_PRIVATE_KEY
  WPMGR_AGENT_SIGNING_PUBLIC_KEY
  WPMGR_SITE_DEST_AGE_SECRET
)

# Committed DEV placeholders shipped in .env.example. These are PUBLIC and the
# control plane rejects the agent private key in production, so a fresh install
# must replace them. We treat them like an empty value (regenerate them).
DEV_SENTINEL_WPMGR_AGENT_SIGNING_PRIVATE_KEY='aWuH1W3DSfBwuE/V/H9BEmV9IAJfK5d6F2RDfYSj/raBW+b26qHT3spd1gHSw7aXEXxZkg9E9WMspibSjSFsnQ=='
DEV_SENTINEL_WPMGR_AGENT_SIGNING_PUBLIC_KEY='gVvm9uqh097KXdYB0sO2lxF8WZIPRPVjLKYm0o0hbJ0='

log() { printf '==> %s\n' "$*"; }
warn() { printf 'WARN: %s\n' "$*" >&2; }
die() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

cleanup() { [ -n "${GEN_FILE}" ] && rm -f "${GEN_FILE}"; }
trap cleanup EXIT

# current_value: prints the current value of KEY in .env (empty if absent/blank).
current_value() {
  grep -E "^$1=" "${ENV_FILE}" | head -n1 | cut -d= -f2- || true
}

# needs_value: true (0) when KEY is absent, present-but-empty, OR still holds the
# committed DEV placeholder for that key.
needs_value() {
  local key="$1" cur sentinel_var sentinel
  cur="$(current_value "${key}")"
  if [ -z "${cur}" ]; then
    return 0 # absent or empty
  fi
  sentinel_var="DEV_SENTINEL_${key}"
  sentinel="${!sentinel_var:-}"
  if [ -n "${sentinel}" ] && [ "${cur}" = "${sentinel}" ]; then
    return 0 # still the committed dev placeholder
  fi
  return 1 # has a real value
}

# set_env_value: portable (BSD/macOS + GNU) in-place set of KEY=VALUE in .env.
set_env_value() {
  local key="$1" value="$2" tmp esc
  tmp="$(mktemp)"
  # Escape sed replacement metacharacters in the value (\, &, and delimiter |).
  esc="$(printf '%s' "${value}" | sed -e 's/[\\&|]/\\&/g')"
  if grep -q "^${key}=" "${ENV_FILE}"; then
    sed "s|^${key}=.*|${key}=${esc}|" "${ENV_FILE}" >"${tmp}"
  else
    cp "${ENV_FILE}" "${tmp}"
    printf '%s=%s\n' "${key}" "${value}" >>"${tmp}"
  fi
  mv "${tmp}" "${ENV_FILE}"
}

gen_with_go() {
  command -v go >/dev/null 2>&1 || return 1
  log "Generating secrets with the local Go toolchain (wpmgr-cli gen-secrets)"
  (cd "${ROOT_DIR}/apps/api" && go run ./cmd/wpmgr-cli gen-secrets) >"${GEN_FILE}" 2>/dev/null
}

gen_with_docker() {
  command -v docker >/dev/null 2>&1 || return 1
  docker compose version >/dev/null 2>&1 || return 1
  log "Generating secrets via Docker (docker compose run api wpmgr-cli gen-secrets)"
  # The api image's entrypoint is the wpmgr server binary; override it to run the
  # bundled CLI. --no-deps avoids starting Postgres/Redis just to print keys.
  docker compose -f infra/docker-compose.yml run --rm --no-deps \
    --entrypoint wpmgr-cli api gen-secrets >"${GEN_FILE}" 2>/dev/null
}

gen_with_host_tools() {
  command -v openssl >/dev/null 2>&1 || return 1
  command -v age-keygen >/dev/null 2>&1 || return 1
  command -v xxd >/dev/null 2>&1 || return 1
  log "Generating secrets with host openssl + age-keygen (fallback)"
  local tmp seed_hex pub_hex priv64_b64 pub_b64
  tmp="$(mktemp -d)"
  openssl genpkey -algorithm ed25519 -out "${tmp}/priv.pem" 2>/dev/null
  # Raw 32-byte ed25519 seed = final 32 bytes of the PKCS8 private-key DER.
  seed_hex="$(openssl pkey -in "${tmp}/priv.pem" -outform DER 2>/dev/null | tail -c 32 | xxd -p | tr -d '\n')"
  # Raw 32-byte public key = final 32 bytes of the SPKI DER.
  pub_hex="$(openssl pkey -in "${tmp}/priv.pem" -pubout -outform DER 2>/dev/null | tail -c 32 | xxd -p | tr -d '\n')"
  # The app expects base64-std of the 64-byte Go layout: seed || public.
  priv64_b64="$(printf '%s%s' "${seed_hex}" "${pub_hex}" | xxd -r -p | base64 | tr -d '\n')"
  pub_b64="$(printf '%s' "${pub_hex}" | xxd -r -p | base64 | tr -d '\n')"
  {
    printf 'WPMGR_SESSION_SECRET=%s\n' "$(openssl rand -base64 48 | tr -d '\n')"
    printf 'WPMGR_AGENT_SIGNING_PRIVATE_KEY=%s\n' "${priv64_b64}"
    printf 'WPMGR_AGENT_SIGNING_PUBLIC_KEY=%s\n' "${pub_b64}"
    printf 'WPMGR_SITE_DEST_AGE_SECRET=%s\n' "$(age-keygen 2>/dev/null | grep '^AGE-SECRET-KEY-')"
  } >"${GEN_FILE}"
  rm -rf "${tmp}"
}

print_next_steps() {
  cat <<'EOF'

Next steps:
  1. Review .env — set WPMGR_PUBLIC_BASE_URL to a URL your agents can reach, and
     (for production) set WPMGR_ENV=production and override the dev DB / S3
     passwords. WPMGR_S3_ENDPOINT must be reachable by remote agents.
  2. Bring up the stack:
       docker compose -f infra/docker-compose.yml up -d        # build from source
       # or, with the prebuilt GHCR images:
       docker compose -f infra/docker-compose.yml -f infra/docker-compose.prod.yml up -d
  3. Verify:
       curl localhost:8080/healthz   # {"status":"ok"}
       curl localhost:8080/readyz    # 200 once dependencies are reachable
       open http://localhost          # the dashboard (nginx)

See docs/install.md for the full guide.
EOF
}

# ---------------------------------------------------------------------------
# Parse args.
# ---------------------------------------------------------------------------
for arg in "$@"; do
  case "${arg}" in
    --force) FORCE=1 ;;
    -h | --help)
      sed -n '3,24p' "${BASH_SOURCE[0]}" | sed 's/^#\{0,1\} \{0,1\}//'
      exit 0
      ;;
    *)
      echo "init-env: unknown argument '${arg}' (try --help)" >&2
      exit 2
      ;;
  esac
done

# ---------------------------------------------------------------------------
# 1. Create .env from .env.example.
# ---------------------------------------------------------------------------
[ -f "${ENV_EXAMPLE}" ] || die ".env.example not found at ${ENV_EXAMPLE}"

if [ -f "${ENV_FILE}" ] && [ "${FORCE}" -eq 0 ]; then
  log ".env already exists — keeping it (use --force to overwrite from .env.example)"
else
  if [ -f "${ENV_FILE}" ] && [ "${FORCE}" -eq 1 ]; then
    cp "${ENV_FILE}" "${ENV_FILE}.bak"
    log "Backed up existing .env -> .env.bak"
  fi
  cp "${ENV_EXAMPLE}" "${ENV_FILE}"
  log "Wrote .env from .env.example"
fi

# ---------------------------------------------------------------------------
# 2. Skip generation if every managed secret is already set.
# ---------------------------------------------------------------------------
ANY_EMPTY=0
for key in "${SECRET_KEYS[@]}"; do
  if needs_value "${key}"; then
    ANY_EMPTY=1
  fi
done

if [ "${ANY_EMPTY}" -eq 0 ]; then
  log "All managed secrets already set in .env — nothing to generate"
  print_next_steps
  exit 0
fi

# ---------------------------------------------------------------------------
# 3. Generate secrets as KEY=VALUE lines into a temp file.
# ---------------------------------------------------------------------------
GEN_FILE="$(mktemp)"

if ! gen_with_go && ! gen_with_docker && ! gen_with_host_tools; then
  die "could not generate secrets — need one of: a Go toolchain, Docker Compose, or (openssl + age-keygen + xxd)."
fi

# Sanity: the generator must have emitted all four keys with non-empty values.
for key in "${SECRET_KEYS[@]}"; do
  val="$(grep -E "^${key}=" "${GEN_FILE}" | head -n1 | cut -d= -f2-)"
  [ -n "${val}" ] || die "secret generator did not produce a value for ${key}"
done

# ---------------------------------------------------------------------------
# 4. Inject generated values into .env, ONLY for keys that need one.
# ---------------------------------------------------------------------------
# The agent private/public keys are a MATCHED PAIR — if either needs a value we
# must replace BOTH from the same freshly generated keypair, never mix an old
# half with a new one.
if needs_value WPMGR_AGENT_SIGNING_PRIVATE_KEY || needs_value WPMGR_AGENT_SIGNING_PUBLIC_KEY; then
  FORCE_AGENT_PAIR=1
else
  FORCE_AGENT_PAIR=0
fi

INJECTED=()
for key in "${SECRET_KEYS[@]}"; do
  replace=0
  case "${key}" in
    WPMGR_AGENT_SIGNING_PRIVATE_KEY | WPMGR_AGENT_SIGNING_PUBLIC_KEY)
      [ "${FORCE_AGENT_PAIR}" -eq 1 ] && replace=1
      ;;
    *)
      needs_value "${key}" && replace=1
      ;;
  esac
  if [ "${replace}" -eq 1 ]; then
    val="$(grep -E "^${key}=" "${GEN_FILE}" | head -n1 | cut -d= -f2-)"
    set_env_value "${key}" "${val}"
    INJECTED+=("${key}")
  else
    log "${key} already set in .env — left unchanged"
  fi
done

if [ "${#INJECTED[@]}" -gt 0 ]; then
  log "Injected fresh secrets: ${INJECTED[*]}"
fi

print_next_steps
