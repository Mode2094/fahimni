#!/usr/bin/env bash
# Health check for a running Fahimni stack.
#
# Checks container health (postgres, redis, migrate, app, frontend, nginx,
# scheduler, horizon), the Laravel liveness/readiness endpoints, the frontend,
# and host disk headroom. It never prints env values or container logs.
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

usage() {
  cat <<'EOF'
Usage: healthcheck.sh --env production|staging [options]

  --env NAME        target stack (default: production)
  --env-file PATH   secret env file (default: <release>/.env)
  --wait SECONDS    retry until healthy or this budget expires (default: 0)
EOF
}

env_name="production"
env_file=""
wait_seconds=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --env)      env_name="${2:-}"; shift 2 ;;
    --env-file) env_file="${2:-}"; shift 2 ;;
    --wait)     wait_seconds="${2:-}"; shift 2 ;;
    -h|--help)  usage; exit 0 ;;
    *) die "unknown argument: $1" ;;
  esac
done

validate_env_name "$env_name"
require_cmd docker
require_cmd curl
docker compose version >/dev/null 2>&1 || die "docker compose plugin is not installed"

env_file="${env_file:-$REPO_DIR/.env}"
load_env_file "$env_file"
[[ "${APP_ENV:-}" == "$env_name" ]] || die "APP_ENV mismatch in $env_file"

port="${APP_PORT:-8080}"
base="http://127.0.0.1:${port}"

docker info >/dev/null 2>&1 || die "Docker daemon is not reachable"

check_once() {
  local ok=0

  for service in postgres redis app frontend nginx scheduler horizon; do
    local state
    state="$(container_health "$service")"
    if [[ "$state" != "running/healthy" ]]; then
      warn "service $service is $state"
      ok=1
    fi
  done

  local migrate_id migrate_state migrate_exit
  migrate_id="$(compose ps -a -q migrate 2>/dev/null || true)"
  if [[ -z "$migrate_id" ]]; then
    warn "service migrate has no container"
    ok=1
  else
    migrate_state="$(docker inspect -f '{{.State.Status}}' "$migrate_id" 2>/dev/null || printf 'unknown')"
    migrate_exit="$(docker inspect -f '{{.State.ExitCode}}' "$migrate_id" 2>/dev/null || printf 'unknown')"
    if [[ "$migrate_state" != "exited" || "$migrate_exit" != "0" ]]; then
      warn "service migrate is $migrate_state (exit $migrate_exit)"
      ok=1
    fi
  fi

  if ! http_ok "$base/up"; then
    warn "HTTP $base/up is not 200"
    ok=1
  fi
  if ! http_ok "$base/api/health/live"; then
    warn "HTTP $base/api/health/live is not 200"
    ok=1
  fi
  if ! http_ok "$base/api/health/ready"; then
    warn "HTTP $base/api/health/ready is not 200"
    ok=1
  fi
  if ! http_ok "$base/"; then
    warn "HTTP $base/ (frontend) is not 200"
    ok=1
  fi

  local used
  used="$(disk_used_percent "$REPO_DIR")"
  if [[ -n "$used" ]]; then
    if (( used >= 95 )); then
      warn "disk usage is ${used}% (critical)"
      ok=1
    elif (( used >= 85 )); then
      warn "disk usage is ${used}% (high)"
    fi
  fi

  return "$ok"
}

if [[ "$wait_seconds" -le 0 ]]; then
  check_once || die "health check failed"
  log "health: OK (${env_name}, port ${port})"
  exit 0
fi

deadline=$(( SECONDS + wait_seconds ))
while (( SECONDS < deadline )); do
  if check_once; then
    log "health: OK (${env_name}, port ${port}) after $(( wait_seconds - (deadline - SECONDS) ))s"
    exit 0
  fi
  sleep 5
done

check_once || die "health check did not pass within ${wait_seconds}s"
log "health: OK (${env_name}, port ${port})"
