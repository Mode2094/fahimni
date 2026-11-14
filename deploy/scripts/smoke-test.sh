#!/usr/bin/env bash
# Public (or loopback) smoke test for Fahimni.
#
# Production: frontend, API health, www redirect, security headers, TLS expiry.
# Staging:    basic-auth challenge (401 without credentials), noindex header,
#             and authenticated health when SMOKE_USER/SMOKE_PASSWORD are set.
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

usage() {
  cat <<'EOF'
Usage: smoke-test.sh --env production|staging [options]

  --env NAME        target stack (default: production)
  --env-file PATH   secret env file (default: <release>/.env)
  --local           hit 127.0.0.1:$APP_PORT instead of public HTTPS domains
  --base URL        override frontend base URL
  --api URL         override API base URL

Staging public mode uses SMOKE_USER / SMOKE_PASSWORD from the environment.
Never pass the password as a command-line argument.
EOF
}

env_name="production"
env_file=""
local_mode=0
base_override=""
api_override=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --env)      env_name="${2:-}"; shift 2 ;;
    --env-file) env_file="${2:-}"; shift 2 ;;
    --local)    local_mode=1; shift ;;
    --base)     base_override="${2:-}"; shift 2 ;;
    --api)      api_override="${2:-}"; shift 2 ;;
    -h|--help)  usage; exit 0 ;;
    *) die "unknown argument: $1" ;;
  esac
done

validate_env_name "$env_name"
require_cmd curl
require_cmd openssl

env_file="${env_file:-$REPO_DIR/.env}"
load_env_file "$env_file"
[[ "${APP_ENV:-}" == "$env_name" ]] || die "APP_ENV mismatch in $env_file"

if [[ "$local_mode" -eq 1 ]]; then
  base="${base_override:-http://127.0.0.1:${APP_PORT:-8080}}"
  api="${api_override:-$base}"
else
  base="${base_override:-${FRONTEND_URL:?FRONTEND_URL missing from env}}"
  api="${api_override:-${APP_URL:?APP_URL missing from env}}"
fi
base="${base%/}"
api="${api%/}"

failures=0
check_code() {
  local label="$1" expected="$2" url="$3"
  shift 3
  local code
  code="$(http_code "$url" "$@")"
  if [[ "$code" == "$expected" ]]; then
    log "OK   $label ($code)"
  else
    warn "FAIL $label (expected $expected, got $code): $(mask_url "$url")"
    failures=$((failures + 1))
  fi
}

check_header_contains() {
  local label="$1" header="$2" needle="$3" url="$4"
  local value
  value="$(header_value "$url" "$header")"
  if [[ "$(printf '%s' "$value" | tr '[:upper:]' '[:lower:]')" == *"$(printf '%s' "$needle" | tr '[:upper:]' '[:lower:]')"* ]]; then
    log "OK   $label"
  else
    warn "FAIL $label ($header='$value')"
    failures=$((failures + 1))
  fi
}

log "smoke target: $(mask_url "$base")"
check_code "frontend" 200 "$base/"
check_code "laravel /up" 200 "$api/up"
check_code "api liveness" 200 "$api/api/health/live"
check_code "api readiness" 200 "$api/api/health/ready"

if [[ "$local_mode" -eq 0 ]]; then
  if [[ "$env_name" == "production" ]]; then
    check_header_contains "HSTS" "Strict-Transport-Security" "max-age=" "$base/"
    check_header_contains "nosniff" "X-Content-Type-Options" "nosniff" "$base/"
    check_code "api domain does not serve the UI" 404 "$api/"

    www="https://www.${NEXT_PUBLIC_PLATFORM_DOMAIN#www.}"
    code="$(http_code "$www/")"
    if [[ "$code" == "301" || "$code" == "308" ]]; then
      log "OK   www redirect ($code)"
    else
      warn "FAIL www redirect (expected 301/308, got $code): $www/"
      failures=$((failures + 1))
    fi

    for host_url in "$base" "$api"; do
      host="${host_url#https://}"
      host="${host%%/*}"
      if echo | openssl s_client -servername "$host" -connect "${host}:443" \
           -verify_hostname "$host" -verify_return_error >/dev/null 2>&1 \
         && echo | openssl s_client -servername "$host" -connect "${host}:443" 2>/dev/null \
              | openssl x509 -checkend $((14 * 86400)) -noout >/dev/null 2>&1; then
        log "OK   TLS $host valid for at least 14 days"
      else
        warn "FAIL TLS $host (untrusted chain or expires in <14 days)"
        failures=$((failures + 1))
      fi
    done
  else
    # Staging must be closed by default.
    check_code "staging basic-auth challenge (frontend)" 401 "$base/"
    check_code "staging basic-auth challenge (api)" 401 "$api/up"

    if [[ -n "${SMOKE_USER:-}" && -n "${SMOKE_PASSWORD:-}" ]]; then
      code="$(curl_with_auth "$SMOKE_USER" "$SMOKE_PASSWORD" \
        -sS -o /dev/null -w '%{http_code}' --max-time 15 "$base/" || printf '000')"
      if [[ "$code" == "200" ]]; then
        log "OK   authenticated staging frontend (200)"
      else
        warn "FAIL authenticated staging frontend ($code)"
        failures=$((failures + 1))
      fi

      noindex="$(curl_with_auth "$SMOKE_USER" "$SMOKE_PASSWORD" -sSI --max-time 15 "$base/" 2>/dev/null \
        | tr -d '\r' | awk 'tolower($0) ~ /^x-robots-tag:/ { print; exit }')"
      if [[ "$noindex" == *"noindex"* ]]; then
        log "OK   staging noindex header"
      else
        warn "FAIL staging noindex header missing"
        failures=$((failures + 1))
      fi
    else
      warn "SMOKE_USER/SMOKE_PASSWORD not set; authenticated staging checks skipped"
    fi
  fi
fi

if [[ "$failures" -gt 0 ]]; then
  die "smoke checks failed: $failures"
fi
log "smoke: OK ($env_name)"
