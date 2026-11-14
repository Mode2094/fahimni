#!/usr/bin/env bash
# Deploy one release directory of Fahimni into a staging or production stack.
#
# Run from the release directory being deployed, e.g.:
#   cd /opt/fahimni/production/releases/<sha>
#   sudo -u fahimni ./deploy/scripts/deploy.sh --env production
#
# The script never edits Caddy, DNS or LooksLoop. It builds images tagged with
# the git commit, starts the stack, waits for health, runs local smoke checks,
# and only then switches the "current" symlink.
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

usage() {
  cat <<'EOF'
Usage: deploy.sh --env production|staging [options]

  --env NAME          target stack (required)
  --env-file PATH     secret env file (default: <release>/.env)
  --skip-build        reuse images already built for this IMAGE_TAG
  --allow-dirty       deploy a dirty worktree (not recommended)
  --wait SECONDS      health wait budget (default: 180)
  --no-smoke          skip local smoke checks (not recommended)
EOF
}

env_name="production"
env_file=""
skip_build=0
allow_dirty=0
wait_seconds=180
run_smoke=1

while [[ $# -gt 0 ]]; do
  case "$1" in
    --env)         env_name="${2:-}"; shift 2 ;;
    --env-file)    env_file="${2:-}"; shift 2 ;;
    --skip-build)  skip_build=1; shift ;;
    --allow-dirty) allow_dirty=1; shift ;;
    --wait)        wait_seconds="${2:-}"; shift 2 ;;
    --no-smoke)    run_smoke=0; shift ;;
    -h|--help)     usage; exit 0 ;;
    *) die "unknown argument: $1" ;;
  esac
done

validate_env_name "$env_name"
require_cmd git
require_cmd docker
docker compose version >/dev/null 2>&1 || die "docker compose plugin is not installed"

env_file="${env_file:-$REPO_DIR/.env}"
[[ -f "$env_file" ]] || die "missing env file: $env_file (run init-env.sh and link it as .env)"
[[ -f "$REPO_DIR/.env" ]] \
  || die "release has no .env; link it first: ln -sfn $env_file $REPO_DIR/.env"
[[ "$env_file" -ef "$REPO_DIR/.env" ]] \
  || die "--env-file ($env_file) must be the same file as $REPO_DIR/.env (compose reads it for service env)"
env_mode="$(stat -Lc '%a' "$env_file" 2>/dev/null || stat -Lf '%Lp' "$env_file")"
[[ "$env_mode" == "600" ]] || warn "env file mode is $env_mode (expected 600): $env_file"

load_env_file "$env_file"
[[ "${APP_ENV:-}" == "$env_name" ]] \
  || die "APP_ENV=$APP_ENV in $env_file does not match --env $env_name"
[[ -n "${COMPOSE_PROJECT_NAME:-}" ]] || die "COMPOSE_PROJECT_NAME is not set"

for key in APP_KEY DB_PASSWORD REDIS_PASSWORD VIDEO_SIGNING_KEY \
           SUPER_ADMIN_EMAIL SUPER_ADMIN_PASSWORD; do
  require_real_value "$key"
done
for key in MAIL_HOST MAIL_USERNAME MAIL_PASSWORD HORIZON_MAIL_TO; do
  if [[ -z "${!key:-}" || "${!key}" == CHANGE_ME* ]]; then
    warn "$key still looks like a placeholder; transactional email/alerts will not work"
  fi
done

if [[ "$allow_dirty" -ne 1 ]]; then
  [[ -z "$(git -C "$REPO_DIR" status --porcelain)" ]] \
    || die "worktree is dirty; commit/stash first or pass --allow-dirty"
fi

sha="$(git -C "$REPO_DIR" rev-parse HEAD)"
if [[ -z "${IMAGE_TAG:-}" || "${IMAGE_TAG}" == CHANGE_ME* ]]; then
  tag="$sha"
else
  tag="$IMAGE_TAG"
  [[ "$tag" == "$sha" ]] || warn "IMAGE_TAG ($tag) differs from HEAD ($sha); image tag wins"
fi
[[ "$tag" =~ ^[A-Za-z0-9._-]+$ ]] || die "unsafe IMAGE_TAG: $tag"
export IMAGE_TAG="$tag"

root="$(resolve_deploy_root)"
[[ -d "$root/releases" ]] || die "deployment root has no releases/ directory: $root"
acquire_lock "$root/.deploy.lock"
on_exit() {
  local status=$?
  trap - EXIT
  if (( status != 0 )); then
    warn "deploy failed"
  fi
  release_lock
  exit "$status"
}
trap on_exit EXIT

previous_tag=""
previous_release=""
[[ -f "$root/.deployed-tag" ]] && previous_tag="$(tr -d '[:space:]' < "$root/.deployed-tag")"
if [[ -L "$root/current" ]]; then
  previous_release="$(readlink -f "$root/current" 2>/dev/null || true)"
fi

restore_previous() {
  [[ -n "$previous_tag" ]] || return 0
  docker image inspect "fahimni-app:${previous_tag}" >/dev/null 2>&1 || return 0
  warn "attempting automatic restore of image tag $previous_tag"
  ( cd "$REPO_DIR" && IMAGE_TAG="$previous_tag" docker compose up -d --remove-orphans ) || true
  "$SCRIPT_DIR/healthcheck.sh" --env "$env_name" --env-file "$env_file" --wait 60 || true
}

fail_with_restore() {
  local message="$1"
  restore_previous
  die "$message"
}

log "validating compose configuration"
compose config --quiet || die "docker compose config is invalid"

if [[ "$skip_build" -eq 1 ]]; then
  log "skipping build; using existing tag $tag"
  docker image inspect "fahimni-app:${tag}" >/dev/null 2>&1 \
    || die "image fahimni-app:${tag} does not exist"
else
  log "building app/frontend/scheduler/horizon for $tag"
  compose build app frontend scheduler horizon \
    || fail_with_restore "image build failed"
fi

log "starting stack (migrations run in the one-shot migrate service)"
compose up -d --remove-orphans \
  || fail_with_restore "docker compose up failed"

log "waiting for health (budget ${wait_seconds}s)"
"$SCRIPT_DIR/healthcheck.sh" --env "$env_name" --env-file "$env_file" --wait "$wait_seconds" \
  || fail_with_restore "health checks failed"

if [[ "$run_smoke" -eq 1 ]]; then
  "$SCRIPT_DIR/smoke-test.sh" --env "$env_name" --env-file "$env_file" --local \
    || fail_with_restore "local smoke checks failed"
fi

if [[ -n "$previous_release" && "$previous_release" != "$REPO_DIR" ]]; then
  printf '%s\n' "$previous_release" > "$root/.previous-release"
  [[ -n "$previous_tag" ]] && printf '%s\n' "$previous_tag" > "$root/.previous-tag"
fi

printf '%s\n' "$tag" > "$root/.deployed-tag.new"
mv "$root/.deployed-tag.new" "$root/.deployed-tag"
ln -sfn "$REPO_DIR" "$root/current"

log "deployed $tag"
log "current -> $REPO_DIR"
