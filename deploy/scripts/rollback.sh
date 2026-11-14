#!/usr/bin/env bash
# Roll back to the release recorded by the last successful deploy.
#
# Code rollback is not a database rollback. If a release ran destructive or
# incompatible migrations, fix forward or restore a verified backup instead.
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

usage() {
  cat <<'EOF'
Usage: rollback.sh --env production|staging [options]

  --env NAME          target stack (required)
  --to-release PATH   release directory (default: <root>/.previous-release)
  --to-tag TAG        image tag (default: <root>/.previous-tag or release basename)
  --yes               do not prompt
  --wait SECONDS      health wait budget (default: 120)
EOF
}

env_name="production"
to_release=""
to_tag=""
yes=0
wait_seconds=120

while [[ $# -gt 0 ]]; do
  case "$1" in
    --env)        env_name="${2:-}"; shift 2 ;;
    --to-release) to_release="${2:-}"; shift 2 ;;
    --to-tag)     to_tag="${2:-}"; shift 2 ;;
    --yes)        yes=1; shift ;;
    --wait)       wait_seconds="${2:-}"; shift 2 ;;
    -h|--help)    usage; exit 0 ;;
    *) die "unknown argument: $1" ;;
  esac
done

validate_env_name "$env_name"
require_cmd docker
docker compose version >/dev/null 2>&1 || die "docker compose plugin is not installed"

root="$(resolve_deploy_root)"
[[ -d "$root/releases" ]] || die "deployment root has no releases/ directory: $root"

if [[ -z "$to_release" ]]; then
  [[ -f "$root/.previous-release" ]] || die "no previous release recorded"
  to_release="$(tr -d '[:space:]' < "$root/.previous-release")"
fi
[[ -d "$to_release" ]] || die "previous release directory not found: $to_release"
[[ -f "$to_release/.env" ]] || die "previous release has no .env: $to_release/.env"

if [[ -z "$to_tag" ]]; then
  if [[ -f "$root/.previous-tag" ]]; then
    to_tag="$(tr -d '[:space:]' < "$root/.previous-tag")"
  else
    to_tag="$(basename "$to_release")"
  fi
fi
[[ "$to_tag" =~ ^[A-Za-z0-9._-]+$ ]] || die "unsafe image tag: $to_tag"
docker image inspect "fahimni-app:${to_tag}" >/dev/null 2>&1 \
  || die "image fahimni-app:${to_tag} is not available locally"

current="$(readlink -f "$root/current" 2>/dev/null || true)"
current_tag="$( [[ -f "$root/.deployed-tag" ]] && tr -d '[:space:]' < "$root/.deployed-tag" || true )"

if [[ "$yes" -ne 1 ]]; then
  printf 'Roll back %s from %s to %s (image %s)? [y/N] ' \
    "$env_name" "${current:-unknown}" "$to_release" "$to_tag"
  answer=""
  read -r answer || true
  [[ "$answer" =~ ^[yY]$ ]] || die "cancelled"
fi

load_env_file "$to_release/.env"
[[ "${APP_ENV:-}" == "$env_name" ]] || die "APP_ENV mismatch in $to_release/.env"
[[ -n "${COMPOSE_PROJECT_NAME:-}" ]] || die "COMPOSE_PROJECT_NAME missing in env file"

acquire_lock "$root/.rollback.lock"
on_exit() {
  local status=$?
  trap - EXIT
  release_lock
  exit "$status"
}
trap on_exit EXIT

log "starting image $to_tag from $to_release"
( cd "$to_release" && IMAGE_TAG="$to_tag" docker compose up -d --remove-orphans ) \
  || die "docker compose up failed during rollback"

health_script="$to_release/deploy/scripts/healthcheck.sh"
[[ -x "$health_script" ]] || health_script="$SCRIPT_DIR/healthcheck.sh"
smoke_script="$to_release/deploy/scripts/smoke-test.sh"
[[ -x "$smoke_script" ]] || smoke_script="$SCRIPT_DIR/smoke-test.sh"

"$health_script" --env "$env_name" --env-file "$to_release/.env" --wait "$wait_seconds" \
  || die "health checks failed after rollback; current symlink was not switched"
"$smoke_script" --env "$env_name" --env-file "$to_release/.env" --local \
  || die "smoke checks failed after rollback; current symlink was not switched"

# Record the failed release so a second rollback can move forward again.
if [[ -n "$current" && "$current" != "$to_release" ]]; then
  printf '%s\n' "$current" > "$root/.previous-release"
  printf '%s\n' "$current_tag" > "$root/.previous-tag"
fi
printf '%s\n' "$to_tag" > "$root/.deployed-tag.new"
mv "$root/.deployed-tag.new" "$root/.deployed-tag"
ln -sfn "$to_release" "$root/current"

log "rollback complete: $to_tag ($to_release)"
warn "database schema may still be newer than this code; verify before declaring recovery"
