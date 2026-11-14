#!/usr/bin/env bash
# Shared helpers for Fahimni deployment scripts. Source, do not execute.
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
DEPLOY_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd -P)"
REPO_DIR="$(cd -- "$DEPLOY_DIR/.." && pwd -P)"

log()  { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }
warn() { printf '[%s] WARN: %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; }
die()  { printf '[%s] ERROR: %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; exit 1; }

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "required command not found: $1"
}

require_root() {
  [[ "${EUID:-$(id -u)}" -eq 0 ]] || die "this command must run as root (use sudo)"
}

require_real_value() {
  local name="$1"
  local value="${!name:-}"
  [[ -n "$value" && "$value" != CHANGE_ME* ]] \
    || die "$name is unset or still a CHANGE_ME placeholder"
}

validate_env_name() {
  case "${1:-}" in
    production|staging) ;;
    *) die "environment must be 'production' or 'staging' (got '${1:-}')" ;;
  esac
}

# Load a key=value env file without printing it. The file is expected to be
# root/deploy-user owned and mode 600.
load_env_file() {
  local file="${1:?env file required}"
  [[ -f "$file" ]] || die "env file not found: $file"
  [[ -r "$file" ]] || die "env file is not readable: $file"
  set -a
  # shellcheck disable=SC1090
  source "$file"
  set +a
}

# The deployment root holds releases/, current symlink and deploy state.
# On the server the layout is:
#   /opt/fahimni/production/{releases/<sha>,current,.deployed-tag,...}
# DEPLOY_ROOT may be set explicitly by the operator.
resolve_deploy_root() {
  if [[ -n "${DEPLOY_ROOT:-}" ]]; then
    printf '%s\n' "$DEPLOY_ROOT"
    return
  fi
  local parent
  parent="$(dirname "$REPO_DIR")"
  if [[ "$(basename "$parent")" == "releases" ]]; then
    dirname "$parent"
  else
    printf '%s\n' "$parent"
  fi
}

# docker compose always runs from the release directory so its .env and
# relative paths (certs/, tests/load/) resolve exactly as written.
compose() {
  ( cd "$REPO_DIR" && docker compose "$@" )
}

compose_in() {
  local dir="$1"
  shift
  ( cd "$dir" && docker compose "$@" )
}

acquire_lock() {
  local lock="$1"
  if ! mkdir "$lock" 2>/dev/null; then
    die "another operation holds the lock: $lock"
  fi
  LOCK_DIR="$lock"
}

release_lock() {
  if [[ -n "${LOCK_DIR:-}" ]]; then
    rmdir "$LOCK_DIR" 2>/dev/null || true
    LOCK_DIR=""
  fi
}

# Run a command with a secret passed on stdin instead of argv.
# Usage: curl_with_auth <user> <password> <curl args...>
curl_with_auth() {
  local user="$1" password="$2" escaped_user escaped_password
  shift 2
  require_cmd curl
  escaped_user="${user//\\/\\\\}"
  escaped_user="${escaped_user//\"/\\\"}"
  escaped_password="${password//\\/\\\\}"
  escaped_password="${escaped_password//\"/\\\"}"
  printf 'user = "%s:%s"\n' "$escaped_user" "$escaped_password" \
    | curl --config - "$@"
}

http_code() {
  curl -sS -o /dev/null -w '%{http_code}' --max-time "${HTTP_TIMEOUT:-10}" "$@" 2>/dev/null || printf '000'
}

http_ok() {
  local url="$1"
  local code
  code="$(http_code "$url")"
  [[ "$code" == "200" ]]
}

header_value() {
  local url="$1" name="$2"
  curl -sSI --max-time "${HTTP_TIMEOUT:-10}" "$url" 2>/dev/null \
    | tr -d '\r' \
    | awk -v n="$(printf '%s' "$name" | tr '[:upper:]' '[:lower:]')" '
        index(tolower($0), n ":") == 1 { sub(/^[^:]+:[[:space:]]*/, ""); print; exit }'
}

container_health() {
  local service="$1" id state health
  id="$(compose ps -q "$service" 2>/dev/null || true)"
  [[ -n "$id" ]] || { printf 'missing'; return; }
  state="$(docker inspect -f '{{.State.Status}}' "$id" 2>/dev/null || printf 'unknown')"
  health="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$id" 2>/dev/null || printf 'unknown')"
  printf '%s/%s' "$state" "$health"
}

disk_used_percent() {
  df -P "$1" | awk 'NR==2 { gsub("%", "", $5); print $5 }'
}

mask_url() {
  # Never print credentials embedded in a URL.
  printf '%s' "$1" | sed -E 's#(https?://)[^/@]+@#\1***@#'
}

# ── memory sizing ────────────────────────────────────────────────────────────
# Normalize docker-style sizes (512mb, 256M, 1G, 1g) to MiB. A bare number is
# treated as MiB. Returns non-zero on anything unparseable.
mem_to_mib() {
  local v="${1:-}" num
  num="${v//[^0-9]/}"
  [[ -n "$num" ]] || return 1
  case "$v" in
    *[gG]*) printf '%s' $((num * 1024)) ;;
    *[mM]*) printf '%s' "$num" ;;
    *[kK]*) printf '%s' $((num / 1024)) ;;
    *)      printf '%s' "$num" ;;
  esac
}

# Redis must be able to evict BEFORE the container limit kills it, and must
# survive the AOF-rewrite copy-on-write spike. Require >=32MiB headroom.
REDIS_MIN_HEADROOM_MIB=32
validate_redis_memory() {
  local cap max
  cap="$(mem_to_mib "${MEM_REDIS:-256M}")" \
    || die "MEM_REDIS is not a valid size: '${MEM_REDIS:-}'"
  max="$(mem_to_mib "${REDIS_MAXMEMORY:-192mb}")" \
    || die "REDIS_MAXMEMORY is not a valid size: '${REDIS_MAXMEMORY:-}'"
  (( max + REDIS_MIN_HEADROOM_MIB <= cap )) \
    || die "REDIS_MAXMEMORY=${REDIS_MAXMEMORY:-192mb} leaves <${REDIS_MIN_HEADROOM_MIB}MiB below MEM_REDIS=${MEM_REDIS:-256M}; Redis would be OOM-killed before evicting (raise MEM_REDIS or lower REDIS_MAXMEMORY)"
}
