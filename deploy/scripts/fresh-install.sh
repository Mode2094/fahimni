#!/usr/bin/env bash
# =============================================================================
#  Fahimni — STANDALONE FRESH INSTALL (Ubuntu 24.04, clean VPS)
#
#  Brings up ONE production stack and nothing else:
#      Laravel (PHP-FPM) + Next.js + PostgreSQL + Redis + nginx + Docker
#      + a STANDALONE Caddy edge with automatic HTTPS on your domain.
#
#  Deliberately independent of the previous go-live tooling
#  (fahimni-golive.sh / fahimni-brand-rollout.sh): it restores no backup,
#  upgrades no prior release, touches no other project and creates a BRAND-NEW
#  empty database (migrations + seeders only).
#
#  SELF-CONTAINED: safe to pipe from curl; it `source`s nothing from the working
#  copy. The versioned tooling it needs comes from the pinned release it clones.
#
#  IDEMPOTENT / RE-RUNNABLE: every step converges. Re-running after a partial
#  failure or a reboot reuses the release, env file and volumes. It NEVER runs
#  migrate:fresh, NEVER drops volumes and NEVER rewrites secrets once the
#  database exists.
#
#  Quick start (root):
#    curl -fsSL <raw-url>/deploy/scripts/fresh-install.sh \
#      | sudo -E bash -s -- --acme-email you@fahimni.me
#
#  See deploy/FRESH_INSTALL.md for the full runbook.
# =============================================================================
set -Eeuo pipefail

# ── defaults (all overridable via flags or FAHIMNI_* env) ────────────────────
DOMAIN="${FAHIMNI_DOMAIN:-fahimni.me}"
API_DOMAIN="${FAHIMNI_API_DOMAIN:-}"
ACME_EMAIL="${ACME_EMAIL:-}"
BRANCH="${FAHIMNI_RELEASE_BRANCH:-fix/launch-blockers-rc}"
RELEASE_SHA="${FAHIMNI_RELEASE_SHA:-}"
ADMIN_EMAIL="${SUPER_ADMIN_EMAIL:-}"
ADMIN_PASSWORD="${SUPER_ADMIN_PASSWORD:-}"
ADMIN_NAME="${SUPER_ADMIN_NAME:-مسؤول منصة فهمني}"
STACK="${FAHIMNI_STACK_DIR:-/opt/fahimni/production}"
ENVF="${FAHIMNI_ENV_FILE:-/etc/fahimni/production.env}"
CADDY_ENVF="${FAHIMNI_CADDY_ENV_FILE:-/etc/fahimni/caddy.env}"
# The Caddy edge lives in its OWN compose project so that its `up --remove-orphans`
# can never stop/replace the app stack (project `fahimni`). See
# deploy/caddy/docker-compose.caddy.yml. Overridable only for tests.
EDGE_PROJECT="${FAHIMNI_EDGE_PROJECT:-fahimni-edge}"
CREDS_FILE="${FAHIMNI_ADMIN_CREDS_FILE:-/root/fahimni-admin-credentials.txt}"
REPO_URL="${FAHIMNI_REPO_URL:-https://github.com/Mode2094/fahimni.git}"
DEPLOY_USER="${FAHIMNI_DEPLOY_USER:-fahimni}"

DO_BOOTSTRAP=1
DO_FIREWALL=1
DO_SWAP=1
DO_CADDY=1
VERIFY_ONLY=0
WAIT_HEALTH="${FAHIMNI_WAIT_HEALTH:-240}"
ACME_GRACE="${FAHIMNI_ACME_GRACE:-90}"

usage() {
  cat <<'EOF'
Fahimni — standalone fresh install for a clean Ubuntu 24.04 VPS (must run as root).

  --domain NAME          apex domain                       (default fahimni.me)
  --api-domain NAME      api/webhook host                  (default api.<domain>)
  --acme-email EMAIL     Let's Encrypt account email       (REQUIRED for TLS)
  --branch NAME          release branch                    (default fix/launch-blockers-rc)
  --release-sha SHA      pin an exact commit               (default branch tip)
  --admin-email EMAIL    super-admin login                 (default admin@<domain>)
  --admin-password PW    super-admin password              (default: strong random)
  --admin-name NAME      super-admin display name          (default «مسؤول منصة فهمني»)
  --stack-dir PATH       install root                      (default /opt/fahimni/production)
  --env-file PATH        generated env file                (default /etc/fahimni/production.env)
  --skip-host-bootstrap  do not apt-install Docker/git/etc (host already prepared)
  --skip-firewall        do not configure UFW
  --skip-swap            do not create a swapfile
  --no-caddy             do not manage the Caddy edge (bring your own TLS)
  --verify-only          skip install; only re-run the verification suite
  -h | --help            this help
EOF
}

while [ $# -gt 0 ]; do
  case "$1" in
    --domain)               DOMAIN="${2:?}"; shift 2 ;;
    --api-domain)           API_DOMAIN="${2:?}"; shift 2 ;;
    --acme-email)           ACME_EMAIL="${2:?}"; shift 2 ;;
    --branch)               BRANCH="${2:?}"; shift 2 ;;
    --release-sha)          RELEASE_SHA="${2:?}"; shift 2 ;;
    --admin-email)          ADMIN_EMAIL="${2:?}"; shift 2 ;;
    --admin-password)       ADMIN_PASSWORD="${2:?}"; shift 2 ;;
    --admin-name)           ADMIN_NAME="${2:?}"; shift 2 ;;
    --stack-dir)            STACK="${2:?}"; shift 2 ;;
    --env-file)             ENVF="${2:?}"; shift 2 ;;
    --skip-host-bootstrap)  DO_BOOTSTRAP=0; shift ;;
    --skip-firewall)        DO_FIREWALL=0; shift ;;
    --skip-swap)            DO_SWAP=0; shift ;;
    --no-caddy)             DO_CADDY=0; shift ;;
    --verify-only)          VERIFY_ONLY=1; shift ;;
    -h|--help)              usage; exit 0 ;;
    *) printf 'unknown argument: %s\n' "$1" >&2; usage >&2; exit 2 ;;
  esac
done

[ -n "$API_DOMAIN" ] || API_DOMAIN="api.$DOMAIN"

# ── logging / helpers ────────────────────────────────────────────────────────
log()  { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }
warn() { printf '[%s] WARN: %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; }
die()  { printf '[%s] ERROR: %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; exit 1; }

if [ "${EUID:-$(id -u)}" -ne 0 ]; then
  printf 'ERROR: this installer must run as root (use sudo)\n' >&2
  exit 1
fi

sha256_file() {
  if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1
  else shasum -a 256 "$1" | cut -d' ' -f1; fi
}

http_code() { # http_code <url> [curl args...]
  local url="$1"; shift
  curl -sS -o /dev/null -w '%{http_code}' --max-time "${HTTP_TIMEOUT:-15}" "$@" "$url" 2>/dev/null || printf '000'
}

set_env() { # set_env <file> <key> <value>  (never prints the value)
  local file="$1" key="$2" value="$3" tmp
  tmp="$(mktemp)"
  if grep -q "^${key}=" "$file"; then
    awk -v k="$key" -v v="$value" 'BEGIN{FS=OFS="="} $1==k{print k"="v; next} {print}' "$file" > "$tmp"
  else
    cat "$file" > "$tmp"
    printf '%s=%s\n' "$key" "$value" >> "$tmp"
  fi
  cat "$tmp" > "$file"
  rm -f "$tmp"
}

env_value() { sed -n "s/^$2=//p" "$1" | head -1; }

# ── 1. host bootstrap (idempotent) ───────────────────────────────────────────
bootstrap_host() {
  if [ "$DO_BOOTSTRAP" -ne 1 ]; then log "host bootstrap skipped (--skip-host-bootstrap)"; return 0; fi
  command -v apt-get >/dev/null 2>&1 || die "no apt-get found — this installer targets Ubuntu 24.04"

  log "installing base packages (idempotent)"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y -qq ca-certificates curl git openssl jq gnupg ufw >/dev/null

  if ! command -v docker >/dev/null 2>&1; then
    log "installing Docker Engine + Compose plugin"
    apt-get install -y -qq docker.io docker-compose-v2 >/dev/null 2>&1 || true
  fi
  if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
    log "distro docker/compose missing — adding Docker's apt repository"
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    chmod a+r /etc/apt/keyrings/docker.gpg
    printf 'deb [arch=%s signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu %s stable\n' \
      "$(dpkg --print-architecture)" "$(sed -n 's/^VERSION_CODENAME=//p' /etc/os-release 2>/dev/null | head -1 || true)" \
      > /etc/apt/sources.list.d/docker.list
    apt-get update -qq
    apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-compose-plugin >/dev/null
  fi

  command -v docker >/dev/null 2>&1 || die "Docker installation failed"
  docker compose version >/dev/null 2>&1 || die "docker compose plugin is not available"
  if command -v systemctl >/dev/null 2>&1; then systemctl enable --now docker >/dev/null 2>&1 || true; fi
  docker info >/dev/null 2>&1 || die "the Docker daemon is not reachable"
  log "Docker ready: $(docker --version)"
}

# ── 2. swap (idempotent) ─────────────────────────────────────────────────────
ensure_swap() {
  if [ "$DO_SWAP" -ne 1 ]; then return 0; fi
  if [ -n "$(swapon --show=NAME --noheadings 2>/dev/null || true)" ]; then log "swap already active"; return 0; fi
  local mem_mb
  mem_mb="$(awk '/MemTotal/{printf "%d", $2/1024}' /proc/meminfo 2>/dev/null || echo 0)"
  if [ "${mem_mb:-0}" -ge 3800 ]; then log "host has ${mem_mb}MiB RAM — swapfile not required"; return 0; fi
  log "creating a 2G swapfile (host RAM ${mem_mb}MiB)"
  if [ ! -f /swapfile ]; then
    fallocate -l 2G /swapfile || dd if=/dev/zero of=/swapfile bs=1M count=2048 status=none
    chmod 600 /swapfile
    mkswap /swapfile >/dev/null
  fi
  swapon /swapfile 2>/dev/null || warn "swapfile could not be enabled"
  grep -q '^/swapfile ' /etc/fstab || printf '/swapfile none swap sw 0 0\n' >> /etc/fstab
}

# ── 3. firewall (idempotent) ─────────────────────────────────────────────────
configure_firewall() {
  if [ "$DO_FIREWALL" -ne 1 ]; then log "firewall configuration skipped"; return 0; fi
  if ! command -v ufw >/dev/null 2>&1; then warn "ufw not installed — skipping firewall"; return 0; fi
  log "configuring UFW: default deny inbound; allow 22, 80, 443 only"
  ufw --force default deny incoming >/dev/null
  ufw --force default allow outgoing >/dev/null
  ufw allow 22/tcp >/dev/null
  ufw allow 80/tcp >/dev/null
  ufw allow 443/tcp >/dev/null
  ufw allow 443/udp >/dev/null
  ufw --force enable >/dev/null
  log "PostgreSQL and Redis publish no host ports — nothing else is exposed"
}

# ── 4. deploy user ───────────────────────────────────────────────────────────
ensure_user() {
  if ! id -u "$DEPLOY_USER" >/dev/null 2>&1; then
    log "creating system user '$DEPLOY_USER'"
    useradd --system --shell /usr/sbin/nologin --home-dir /opt/fahimni "$DEPLOY_USER" 2>/dev/null \
      || adduser --system --no-create-home --shell /usr/sbin/nologin "$DEPLOY_USER"
  fi
  if getent group docker >/dev/null 2>&1; then usermod -aG docker "$DEPLOY_USER" 2>/dev/null || true; fi
}

# ── 5. resolve + clone the pinned release (idempotent) ───────────────────────
resolve_sha() {
  if [ -n "$RELEASE_SHA" ]; then printf '%s\n' "$RELEASE_SHA"; return 0; fi
  log "resolving the tip of branch '$BRANCH'" >&2
  git ls-remote "$REPO_URL" "refs/heads/$BRANCH" | awk '{print $1}' | head -1
}

clone_release() {
  local sha
  sha="$(resolve_sha)"
  [ -n "$sha" ] || die "could not resolve a release commit for branch '$BRANCH'"
  REL="$STACK/releases/$sha"
  mkdir -p "$STACK/releases" "$STACK/backups"

  if [ -d "$REL/.git" ] && [ "$(git -c safe.directory='*' -C "$REL" rev-parse HEAD 2>/dev/null || true)" = "$sha" ]; then
    log "release $sha already present — reusing"
  else
    log "cloning $REPO_URL @ $sha"
    rm -rf "$REL"
    git clone --branch "$BRANCH" --single-branch --quiet "$REPO_URL" "$REL" || die "git clone failed"
    git -c safe.directory='*' -C "$REL" checkout --detach --quiet "$sha" || die "could not check out $sha"
    [ "$(git -c safe.directory='*' -C "$REL" rev-parse HEAD)" = "$sha" ] || die "checked-out commit is not $sha"
  fi
  [ -f "$REL/docker-compose.yml" ] || die "release has no docker-compose.yml"
  chown -R "$DEPLOY_USER:$DEPLOY_USER" "$STACK" 2>/dev/null || true
  printf '%s\n' "$sha" > "$STACK/.release-sha"
  log "release ready: $REL"
}

# ── 6. generate / reuse the secret env file ──────────────────────────────────
generate_env() {
  mkdir -p "$(dirname "$ENVF")"
  chmod 700 "$(dirname "$ENVF")" 2>/dev/null || true

  local existing_db
  existing_db="$(env_value "$ENVF" DB_PASSWORD 2>/dev/null || true)"
  if [ -n "$existing_db" ] && [ "$existing_db" != "CHANGE_ME" ]; then
    log "reusing existing env file $ENVF (secrets are NOT rotated)"
  else
    log "generating a new env file with fresh secrets: $ENVF"
    bash "$REL/deploy/scripts/init-env.sh" --env production --out "$ENVF" --force
  fi
  chmod 600 "$ENVF"

  set_env "$ENVF" APP_ENV production
  set_env "$ENVF" APP_DEBUG false
  set_env "$ENVF" APP_NAME "فهمني"
  set_env "$ENVF" APP_URL "https://$API_DOMAIN"
  set_env "$ENVF" FRONTEND_URL "https://$DOMAIN"
  set_env "$ENVF" SESSION_DOMAIN ""
  set_env "$ENVF" SANCTUM_STATEFUL_DOMAINS "$DOMAIN,www.$DOMAIN,$API_DOMAIN"
  set_env "$ENVF" CORS_ALLOWED_ORIGINS "https://$DOMAIN,https://www.$DOMAIN"
  set_env "$ENVF" NEXT_PUBLIC_API_URL "/api/v1"
  set_env "$ENVF" NEXT_PUBLIC_PLATFORM_DOMAIN "$DOMAIN"
  set_env "$ENVF" CACHE_PREFIX fahimni_prod_
  set_env "$ENVF" HORIZON_PREFIX fahimni_prod_horizon_
  set_env "$ENVF" BIND_ADDR 127.0.0.1
  set_env "$ENVF" APP_PORT "${APP_PORT:-8080}"
  set_env "$ENVF" IMAGE_TAG "$(cat "$STACK/.release-sha")"

  # Never replace an admin login that is already configured unless the operator
  # explicitly passes --admin-email. On a re-run the existing value is reused.
  local existing_admin
  existing_admin="$(env_value "$ENVF" SUPER_ADMIN_EMAIL)"
  if [ -n "$ADMIN_EMAIL" ]; then
    set_env "$ENVF" SUPER_ADMIN_EMAIL "$ADMIN_EMAIL"
  elif [ -n "$existing_admin" ] && [ "$existing_admin" != "CHANGE_ME" ]; then
    ADMIN_EMAIL="$existing_admin"
    log "keeping the existing admin email from $ENVF (pass --admin-email to change it)"
  else
    ADMIN_EMAIL="admin@$DOMAIN"
    set_env "$ENVF" SUPER_ADMIN_EMAIL "$ADMIN_EMAIL"
  fi
  set_env "$ENVF" SUPER_ADMIN_NAME "$ADMIN_NAME"
  if [ -n "$ADMIN_PASSWORD" ]; then set_env "$ENVF" SUPER_ADMIN_PASSWORD "$ADMIN_PASSWORD"; fi

  local pw
  pw="$(env_value "$ENVF" SUPER_ADMIN_PASSWORD)"
  case "$pw" in
    ""|CHANGE_ME*|password|12345678) die "SUPER_ADMIN_PASSWORD is empty, a placeholder or too weak in $ENVF" ;;
  esac

  case "$(env_value "$ENVF" MAIL_FROM_ADDRESS)" in
    ""|CHANGE_ME*|*example.com) set_env "$ENVF" MAIL_FROM_ADDRESS "noreply@$DOMAIN" ;;
  esac
  set_env "$ENVF" MAIL_FROM_NAME "منصة فهمني"
  case "$(env_value "$ENVF" HORIZON_MAIL_TO)" in
    ""|CHANGE_ME*) set_env "$ENVF" HORIZON_MAIL_TO "$ADMIN_EMAIL" ;;
  esac

  ln -sfn "$ENVF" "$REL/.env"
  chown -h "$DEPLOY_USER:$DEPLOY_USER" "$REL/.env" 2>/dev/null || true

  umask 077
  {
    printf '# منصة فهمني — initial super-admin credentials (keep secret)\n'
    printf 'url=%s\n' "https://$DOMAIN/admin/login"
    printf 'email=%s\n' "$(env_value "$ENVF" SUPER_ADMIN_EMAIL)"
    printf 'password=%s\n' "$pw"
  } > "$CREDS_FILE"
  chmod 600 "$CREDS_FILE"

  local unresolved
  unresolved="$(awk -F= '/^[A-Z0-9_]+=CHANGE_ME/ && $1 != "IMAGE_TAG" {printf "%s ", $1}' "$ENVF")"
  if [ -n "$unresolved" ]; then warn "still needs real values in $ENVF: $unresolved (mail/SMTP/alerts only)"; fi
  log "env file ready: $ENVF (mode 600)"
}

render_caddy_env() {
  if [ "$DO_CADDY" -ne 1 ]; then return 0; fi
  local proxy_alias net existing_acme
  proxy_alias="$(env_value "$ENVF" PROXY_ALIAS)"
  [ -n "$proxy_alias" ] || proxy_alias=fahimni-prod
  net="$(env_value "$ENVF" FAHIMNI_PROXY_NETWORK)"
  [ -n "$net" ] || net=fahimni-proxy
  # Keep the ACME account email already on disk unless one was given explicitly.
  existing_acme="$(env_value "$CADDY_ENVF" ACME_EMAIL 2>/dev/null || true)"
  if [ -z "$ACME_EMAIL" ]; then
    if [ -n "$existing_acme" ]; then ACME_EMAIL="$existing_acme"; else ACME_EMAIL="admin@$DOMAIN"; fi
  fi
  umask 077
  {
    # NOTE: no COMPOSE_PROJECT_NAME here on purpose. The edge project is fixed
    # to `fahimni-edge` (compose top-level `name:` + `-p` below) so it can never
    # be merged with the app project `fahimni`.
    printf 'FAHIMNI_PROXY_NETWORK=%s\n' "$net"
    printf 'FAHIMNI_DOMAIN=%s\n' "$DOMAIN"
    printf 'FAHIMNI_API_DOMAIN=%s\n' "$API_DOMAIN"
    printf 'FAHIMNI_UPSTREAM=%s:80\n' "$proxy_alias"
    printf 'ACME_EMAIL=%s\n' "$ACME_EMAIL"
  } > "$CADDY_ENVF"
  chmod 600 "$CADDY_ENVF"
  log "Caddy env ready: $CADDY_ENVF"
}

# ── 7. network + stack ───────────────────────────────────────────────────────
compose() { ( cd "$REL" && docker compose "$@" ); }

create_network() {
  local net
  net="$(env_value "$ENVF" FAHIMNI_PROXY_NETWORK)"
  [ -n "$net" ] || net=fahimni-proxy
  if docker network inspect "$net" >/dev/null 2>&1; then
    log "proxy network already exists: $net"
  else
    log "creating shared proxy network: $net"
    docker network create "$net" >/dev/null
  fi
}

build_and_up() {
  log "validating compose configuration"
  compose config --quiet || die "docker compose config is invalid"
  log "building application images (first run takes a few minutes)"
  compose build app frontend scheduler horizon || die "image build failed"
  log "starting stack (migrations + seed run in the one-shot migrate service)"
  compose up -d --remove-orphans || die "docker compose up failed"
}

wait_for_migrate() {
  log "waiting for the one-shot migrate service"
  local deadline=$(( SECONDS + WAIT_HEALTH )) id state exit_code
  while [ "$SECONDS" -lt "$deadline" ]; do
    id="$(compose ps -a -q migrate 2>/dev/null || true)"
    if [ -n "$id" ]; then
      state="$(docker inspect -f '{{.State.Status}}' "$id" 2>/dev/null || echo unknown)"
      exit_code="$(docker inspect -f '{{.State.ExitCode}}' "$id" 2>/dev/null || echo unknown)"
      if [ "$state" = exited ]; then
        if [ "$exit_code" != 0 ]; then die "migrations/seed failed (migrate exited $exit_code)"; fi
        log "migrate finished OK (schema + fresh seed)"
        return 0
      fi
    fi
    sleep 5
  done
  die "migrate did not finish within ${WAIT_HEALTH}s"
}

wait_for_health() {
  local base port deadline
  port="$(env_value "$ENVF" APP_PORT)"; [ -n "$port" ] || port=8080
  base="http://127.0.0.1:$port"
  log "waiting for health on $base"
  deadline=$(( SECONDS + WAIT_HEALTH ))
  while [ "$SECONDS" -lt "$deadline" ]; do
    if [ "$(http_code "$base/up")" = 200 ] \
      && [ "$(http_code "$base/api/health/live")" = 200 ] \
      && [ "$(http_code "$base/api/health/ready")" = 200 ] \
      && [ "$(http_code "$base/")" = 200 ]; then
      log "health: OK"
      return 0
    fi
    sleep 5
  done
  die "health did not become OK within ${WAIT_HEALTH}s (check: docker compose logs)"
}

# ── 8. standalone Caddy edge ─────────────────────────────────────────────────
wait_public_edge() {
  if [ "$DO_CADDY" -ne 1 ]; then return 0; fi
  log "waiting up to ${ACME_GRACE}s for automatic HTTPS on https://$DOMAIN"
  local deadline=$(( SECONDS + ACME_GRACE ))
  while [ "$SECONDS" -lt "$deadline" ]; do
    if [ "$(http_code "https://$DOMAIN/")" = 200 ]; then log "edge is serving HTTPS"; return 0; fi
    sleep 5
  done
  warn "the HTTPS edge has not answered 200 yet — certificates may still be issuing"
}

setup_caddy() {
  if [ "$DO_CADDY" -ne 1 ]; then log "Caddy edge skipped (--no-caddy)"; return 0; fi
  [ -f "$REL/deploy/caddy/docker-compose.caddy.yml" ] || die "caddy compose file missing from the release"
  log "starting standalone Caddy edge (project $EDGE_PROJECT; automatic HTTPS for $DOMAIN, $API_DOMAIN)"
  # `-p "$EDGE_PROJECT"` is explicit and has the highest precedence: the edge is
  # ALWAYS its own compose project, never the app's `fahimni`. This is what stops
  # `--remove-orphans` on either side from touching the other stack's containers.
  ( cd "$REL" && docker compose -p "$EDGE_PROJECT" -f deploy/caddy/docker-compose.caddy.yml \
      --env-file "$CADDY_ENVF" up -d --remove-orphans ) || die "could not start the Caddy edge"
}

# ── 9. verification (real checks — nothing is announced on faith) ────────────
VERIFY_FAILS=0
vpass() { printf '  ✅ %s\n' "$*"; }
vfail() { printf '  ❌ %s\n' "$*" >&2; VERIFY_FAILS=$((VERIFY_FAILS + 1)); }
vwarn() { printf '  ⚠️  %s\n' "$*"; }

platform_name_of() { # $1 = JSON body -> decoded platform_name
  if command -v jq >/dev/null 2>&1; then
    printf '%s' "$1" | jq -r '.settings.platform_name // empty' 2>/dev/null || true
  elif command -v python3 >/dev/null 2>&1; then
    printf '%s' "$1" | python3 -c 'import json,sys
try: print(json.load(sys.stdin).get("settings",{}).get("platform_name",""))
except Exception: pass' 2>/dev/null || true
  fi
}

resolve_a() { # resolve_a <host> -> one IPv4 per line (empty when it does not resolve)
  if command -v getent >/dev/null 2>&1; then
    getent ahostsv4 "$1" 2>/dev/null | awk '{print $1}' | sort -u
  elif command -v dig >/dev/null 2>&1; then
    dig +short A "$1" 2>/dev/null | grep -E '^[0-9]'
  elif command -v host >/dev/null 2>&1; then
    host -t A "$1" 2>/dev/null | awk '/has address/{print $NF}'
  fi
}

verify_all() {
  local base port sha pub
  port="$(env_value "$ENVF" APP_PORT)"; [ -n "$port" ] || port=8080
  base="http://127.0.0.1:$port"
  sha="$(cat "$STACK/.release-sha" 2>/dev/null || echo)"
  # Public origin used for the app-surface checks: the HTTPS edge when we own
  # it, otherwise the loopback origin (bring-your-own TLS mode).
  pub="https://$DOMAIN"
  if [ "$DO_CADDY" -ne 1 ]; then pub="$base"; fi

  printf '\n=== Docker & containers ===\n'
  if docker info >/dev/null 2>&1; then vpass "Docker daemon reachable"; else vfail "Docker daemon unreachable"; fi
  local svc id state
  for svc in postgres redis app frontend nginx scheduler horizon; do
    id="$(compose ps -q "$svc" 2>/dev/null || true)"
    if [ -n "$id" ]; then state="$(docker inspect -f '{{.State.Status}}' "$id" 2>/dev/null || echo unknown)"; else state=missing; fi
    if [ "$state" = running ]; then vpass "container $svc running"; else vfail "container $svc is '$state'"; fi
  done
  local mid mstate mexit
  mid="$(compose ps -a -q migrate 2>/dev/null || true)"
  if [ -n "$mid" ]; then
    mstate="$(docker inspect -f '{{.State.Status}}' "$mid" 2>/dev/null || echo '?')"
    mexit="$(docker inspect -f '{{.State.ExitCode}}' "$mid" 2>/dev/null || echo '?')"
    if [ "$mstate" = exited ] && [ "$mexit" = 0 ]; then vpass "migrate exited 0"; else vfail "migrate is $mstate (exit $mexit)"; fi
  else
    vfail "no migrate container"
  fi

  printf '\n=== loopback health (Laravel + Next.js) ===\n'
  local p code
  for p in /up /api/health /api/health/live /api/health/ready /; do
    code="$(http_code "$base$p")"
    if [ "$code" = 200 ]; then vpass "GET $p -> 200"; else vfail "GET $p -> $code"; fi
  done

  printf '\n=== database (new, schema up, admin present) ===\n'
  local mig admin_count
  mig="$(compose exec -T app php artisan migrate:status --no-ansi 2>/dev/null || true)"
  if printf '%s' "$mig" | grep -q 'Ran'; then vpass "all migrations ran"; else vwarn "could not confirm migration status"; fi
  admin_count="$(compose exec -T app php artisan tinker --execute='echo \App\Models\User::where("is_super_admin", true)->count();' 2>/dev/null | tr -dc '0-9' || true)"
  if [ "${admin_count:-0}" -ge 1 ]; then vpass "super-admin account exists ($admin_count)"; else vfail "no super-admin account found"; fi

  if [ "$DO_CADDY" -eq 1 ]; then
    printf '\n=== DNS (hostnames must point at this VPS before ACME can issue) ===\n'
    local apex_ips api_ips www_ips
    apex_ips="$(resolve_a "$DOMAIN" | tr '\n' ' ')"
    api_ips="$(resolve_a "$API_DOMAIN" | tr '\n' ' ')"
    www_ips="$(resolve_a "www.$DOMAIN" | tr '\n' ' ')"
    if [ -n "${apex_ips// /}" ]; then vpass "$DOMAIN -> ${apex_ips% }"; else vfail "$DOMAIN does not resolve"; fi
    if [ -n "${api_ips// /}" ]; then vpass "$API_DOMAIN -> ${api_ips% }"; else vfail "$API_DOMAIN does not resolve"; fi
    if [ -n "${www_ips// /}" ]; then vpass "www.$DOMAIN -> ${www_ips% }"; else vwarn "www.$DOMAIN does not resolve (needed for the www redirect)"; fi
    if [ -n "${apex_ips// /}" ] && [ -n "${api_ips// /}" ] \
       && [ "${apex_ips% }" != "${api_ips% }" ]; then vwarn "apex and api resolve to different addresses"; fi
  fi

  if [ "$DO_CADDY" -eq 1 ]; then
    printf '\n=== public HTTPS (SSL) ===\n'
    code="$(http_code "https://$DOMAIN/")"
    if [ "$code" = 200 ]; then vpass "https://$DOMAIN/ -> 200"; else vfail "https://$DOMAIN/ -> $code"; fi
    code="$(http_code "https://$API_DOMAIN/up")"
    if [ "$code" = 200 ]; then vpass "https://$API_DOMAIN/up -> 200"; else vfail "https://$API_DOMAIN/up -> $code"; fi
    code="$(http_code "https://www.$DOMAIN/")"
    case "$code" in 301|308) vpass "www.$DOMAIN -> apex ($code)" ;; *) vwarn "www redirect -> $code (check the www DNS record)" ;; esac
    local hsts
    hsts="$(curl -sSI --max-time 15 "https://$DOMAIN/" 2>/dev/null | tr -d '\r' | awk 'tolower($0) ~ /^strict-transport-security:/ {print; exit}')"
    if [ -n "$hsts" ]; then vpass "HSTS present"; else vfail "HSTS header missing"; fi
    if echo | openssl s_client -servername "$DOMAIN" -connect "$DOMAIN:443" \
          -verify_hostname "$DOMAIN" -verify_return_error >/dev/null 2>&1; then
      vpass "TLS certificate valid for $DOMAIN"
    else
      vfail "TLS certificate for $DOMAIN is invalid/untrusted (ACME not complete?)"
    fi
  else
    vwarn "public HTTPS checks skipped (--no-caddy)"
  fi

  printf '\n=== branding (منصة فهمني — no legacy identity) ===\n'
  local ps name
  ps="$(curl -sS --max-time 15 "$pub/api/v1/platform-settings" 2>/dev/null || true)"
  name="$(platform_name_of "$ps")"
  if [ "$name" = 'منصة فهمني' ]; then vpass "platform name is «منصة فهمني»"; else vfail "platform name is '$name' (want «منصة فهمني»)"; fi
  if printf '%s' "$ps" | grep -qi 'looksloop\|tadreesly\|تدريسلي'; then vfail "a legacy brand string is served by the API"; else vpass "no legacy brand string in the API"; fi
  if [ -f "$REL/frontend/public/logo.png" ]; then
    local expect got served
    expect="$(sha256_file "$REL/frontend/public/logo.png")"
    served="$(mktemp)"
    curl -sS --max-time 15 -o "$served" "$pub/logo.png" 2>/dev/null || true
    got="$(sha256_file "$served")"; rm -f "$served"
    if [ "$expect" = "$got" ]; then vpass "served logo matches the release"; else vfail "served logo differs from the release"; fi
  fi

  printf '\n=== login & admin surfaces ===\n'
  code="$(http_code "$pub/teacher/login")"
  if [ "$code" = 200 ]; then vpass "teacher login page -> 200"; else vfail "teacher login page -> $code"; fi
  code="$(http_code "$pub/admin/login")"
  if [ "$code" = 200 ]; then vpass "admin login page -> 200"; else vfail "admin login page -> $code"; fi
  local lc
  lc="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 \
        -H 'Content-Type: application/json' -H 'Accept: application/json' \
        -d '{"email":"probe@invalid.test","password":"definitely-wrong"}' \
        "$pub/api/v1/auth/login" 2>/dev/null || echo 000)"
  case "$lc" in
    401|422) vpass "login endpoint rejects bad credentials ($lc)" ;;
    000)     vfail "login endpoint did not answer" ;;
    *)       vfail "login endpoint returned $lc (want 401/422, not 5xx)" ;;
  esac

  printf '\n=== secrets & exposure ===\n'
  local ports
  ports="$(compose ps --format '{{.Service}} {{.Ports}}' 2>/dev/null || true)"
  if printf '%s' "$ports" | grep -E '^(postgres|redis) ' | grep -q '0\.0\.0\.0\|::'; then
    vfail "PostgreSQL/Redis publish a public port"
  else
    vpass "PostgreSQL/Redis publish no public port"
  fi
  local mode
  mode="$(stat -c '%a' "$ENVF" 2>/dev/null || stat -f '%Lp' "$ENVF" 2>/dev/null || echo '?')"
  if [ "$mode" = 600 ]; then vpass "env file mode is 600"; else vfail "env file mode is $mode (want 600)"; fi

  printf '\n=== deployed release ===\n'
  if [ -n "$sha" ]; then vpass "release $sha"; else vwarn "release SHA file missing"; fi

  printf '\n── verification: %s ──\n' "$([ "$VERIFY_FAILS" -eq 0 ] && echo 'ALL CHECKS PASSED' || echo "$VERIFY_FAILS CHECK(S) FAILED")"
  return "$VERIFY_FAILS"
}

summary() {
  local admin_email
  admin_email="$(env_value "$ENVF" SUPER_ADMIN_EMAIL)"
  cat <<EOF

────────────────────────────────────────────────────────────────────────────
  منصة فهمني — installation complete
────────────────────────────────────────────────────────────────────────────
  Site         : https://$DOMAIN
  Admin        : https://$DOMAIN/admin/login
  API          : https://$API_DOMAIN
  Admin login  : $admin_email
  Credentials  : $CREDS_FILE   (root-only; read with: sudo cat $CREDS_FILE)
  Env file     : $ENVF (mode 600)
  Release      : $(cat "$STACK/.release-sha" 2>/dev/null || echo '?')

  Re-run safely at any time (idempotent):
    sudo bash $REL/deploy/scripts/fresh-install.sh --verify-only
────────────────────────────────────────────────────────────────────────────
EOF
}

# ── main ─────────────────────────────────────────────────────────────────────
main() {
  if [ "$VERIFY_ONLY" -eq 1 ]; then
    [ -f "$ENVF" ] || die "no env file at $ENVF — nothing to verify"
    REL="$(readlink -f "$STACK/current" 2>/dev/null || true)"
    if [ -z "$REL" ] || [ ! -d "$REL" ]; then
      REL="$(find "$STACK/releases" -mindepth 1 -maxdepth 1 -type d 2>/dev/null | sort | tail -1 || true)"
    fi
    [ -n "$REL" ] && [ -d "$REL" ] || die "could not locate a release under $STACK"
    verify_all
    exit "$VERIFY_FAILS"
  fi

  log "Fahimni fresh install — domain=$DOMAIN api=$API_DOMAIN stack=$STACK"
  bootstrap_host
  ensure_swap
  configure_firewall
  ensure_user
  clone_release
  generate_env
  render_caddy_env
  create_network
  build_and_up
  wait_for_migrate
  wait_for_health
  setup_caddy
  wait_public_edge

  ln -sfn "$REL" "$STACK/current"
  chown -h "$DEPLOY_USER:$DEPLOY_USER" "$STACK/current" 2>/dev/null || true

  if verify_all; then
    summary
    log "RESULT: منصة فهمني is installed and verified"
    exit 0
  fi
  warn "installation finished but $VERIFY_FAILS verification check(s) failed — resolve them before announcing launch"
  summary
  exit 1
}

main "$@"
