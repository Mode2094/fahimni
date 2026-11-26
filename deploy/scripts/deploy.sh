#!/usr/bin/env bash
# Deploy one release directory of Fahimni into a staging or production stack.
#
# Run from the release directory being deployed, e.g.:
#   cd /opt/fahimni/production/releases/<sha>
#   sudo -u fahimni ./deploy/scripts/deploy.sh --env production
#
# The script never edits Caddy or DNS. It builds images tagged with
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
  --image-tag TAG     tag the built images as TAG (default: the git commit)
  --skip-build        reuse images already built for this IMAGE_TAG
  --allow-dirty       deploy a dirty worktree (not recommended)
  --wait SECONDS      health wait budget (default: 180)
  --no-smoke          skip local smoke checks (not recommended)
EOF
}

env_name="production"
env_file=""
image_tag=""
skip_build=0
allow_dirty=0
wait_seconds=180
run_smoke=1

while [[ $# -gt 0 ]]; do
  case "$1" in
    --env)         env_name="${2:-}"; shift 2 ;;
    --env-file)    env_file="${2:-}"; shift 2 ;;
    --image-tag)   image_tag="${2:-}"; shift 2 ;;
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
[[ -n "${PROXY_ALIAS:-}" && "${PROXY_ALIAS}" != CHANGE_ME* ]] \
  || die "PROXY_ALIAS is not set (unique nginx alias on the shared proxy network)"

# External networks are never created by compose. The proxy network must exist
# before `compose up`, but is created (not deleted) here so a re-deploy or a
# rollback never removes the bridge to the Caddy edge.
proxy_net="${FAHIMNI_PROXY_NETWORK:-fahimni-proxy}"
if ! docker network inspect "$proxy_net" >/dev/null 2>&1; then
  log "creating shared reverse-proxy network: $proxy_net"
  docker network create "$proxy_net" >/dev/null
fi

# Fail before building if Redis could be OOM-killed before it evicts.
validate_redis_memory

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

# The image tag must track the code actually built. fresh-install.sh pins
# IMAGE_TAG to the install sha in the env file, but nothing advanced it on a
# plain re-deploy, so the pin went stale: brand-new images were retagged with
# an old commit and tag-based rollback silently pointed at them. Trust the git
# commit by default; --image-tag is the explicit override.
sha="$(git -C "$REPO_DIR" rev-parse HEAD)"
tag="${image_tag:-$sha}"
if [[ -z "$image_tag" && -n "${IMAGE_TAG:-}" && "${IMAGE_TAG}" != CHANGE_ME* && "${IMAGE_TAG}" != "$tag" ]]; then
  warn "ignoring stale env IMAGE_TAG ($IMAGE_TAG); tagging by git commit ($tag)"
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
  reload_nginx
  "$SCRIPT_DIR/healthcheck.sh" --env "$env_name" --env-file "$env_file" --wait 60 || true
}

fail_with_restore() {
  local message="$1"
  restore_previous
  die "$message"
}

# Keep the env file's IMAGE_TAG truthful after a successful deploy. fresh-install
# and the brand/golive rollouts all read it back, so a stale pin left here
# misleads them (and any operator) about which build is running.
persist_image_tag() {
  local file="$1" value="$2" mode tmp
  [[ -w "$file" ]] || { warn "cannot persist IMAGE_TAG to $file (not writable)"; return 0; }
  mode="$(stat -Lc '%a' "$file" 2>/dev/null || stat -Lf '%Lp' "$file")"
  tmp="$(mktemp "${file}.XXXXXX")" || { warn "could not create a temp file for $file"; return 0; }
  if awk -v v="$value" '
        /^IMAGE_TAG=/ { print "IMAGE_TAG=" v; seen=1; next }
        { print }
        END { if (!seen) print "IMAGE_TAG=" v }
      ' "$file" > "$tmp"; then
    chmod "$mode" "$tmp" 2>/dev/null || true
    # GNU coreutils only; harmless no-op elsewhere.
    chown --reference="$file" "$tmp" 2>/dev/null || true
    mv -f "$tmp" "$file"
  else
    warn "could not rewrite $file; leaving IMAGE_TAG as-is"
    rm -f "$tmp"
  fi
}

# nginx caches the addresses of app:9000 and frontend:3000 when it loads its
# config (docker/nginx/default.conf). Recreating app/frontend hands them NEW
# addresses, so a running nginx keeps proxying to the DEAD ones until it
# re-reads its config — the "502 after a redeploy" regression that takes the
# whole UI/API down. Validate, then reload (re-read + re-resolve) BEFORE the
# health check so a perfectly healthy deploy is never reported as failed and
# never triggers a pointless rollback. A container restart is the fallback.
reload_nginx() {
  if ! compose exec -T nginx nginx -t >/dev/null 2>&1; then
    warn "nginx -t failed after deploy — restarting nginx to re-resolve upstreams"
    compose restart nginx >/dev/null 2>&1 || warn "could not restart nginx"
    return 0
  fi
  if compose exec -T nginx nginx -s reload >/dev/null 2>&1; then
    log "nginx config reloaded — app/frontend upstream addresses re-resolved"
  else
    warn "nginx -s reload failed — restarting nginx"
    compose restart nginx >/dev/null 2>&1 || warn "could not restart nginx"
  fi
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

# Re-resolve the recreated upstream container addresses before health checking.
reload_nginx

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
persist_image_tag "$env_file" "$tag"
ln -sfn "$REPO_DIR" "$root/current"

log "deployed $tag"
log "current -> $REPO_DIR"
