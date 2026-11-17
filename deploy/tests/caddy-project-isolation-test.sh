#!/usr/bin/env bash
# =============================================================================
#  P0 regression — the standalone Caddy edge lives in its OWN compose project.
#
#  The app stack runs as the compose project `fahimni`; the edge MUST be a
#  DIFFERENT project (`fahimni-edge`). Both are brought up with
#  `up -d --remove-orphans`; if they shared one project name, each side would
#  stop/remove the other's containers (and the app could lose its database
#  container). Proven two ways:
#
#    • static  — real `docker compose config` resolution of BOTH shipped files
#                yields two DISTINCT project names, and the installer wires the
#                edge with an explicit `-p fahimni-edge`.
#    • dynamic — REAL Docker: start the app data tier, write data, start the
#                edge, then re-run each `up --remove-orphans` and restart; assert
#                no container is removed/recreated and the data survives.
#
#  The dynamic part is skipped when Docker is unavailable, and REFUSES to run if
#  the real projects/volumes already exist (it never touches a live deployment).
#
#  Run: deploy/tests/caddy-project-isolation-test.sh
# =============================================================================
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd -P)"

APP_COMPOSE="$REPO_DIR/docker-compose.yml"
EDGE_COMPOSE="$REPO_DIR/deploy/caddy/docker-compose.caddy.yml"
EDGE_ENV_EXAMPLE="$REPO_DIR/deploy/caddy/caddy.env.example"
INSTALLER="$REPO_DIR/deploy/scripts/fresh-install.sh"

APP_PROJECT="fahimni"
EDGE_PROJECT="fahimni-edge"
NET="fahimni-proxy"

work="$(mktemp -d "${TMPDIR:-/tmp}/fahimni-isolation.XXXXXX")"
created_net=0

# shellcheck disable=SC2329  # invoked indirectly via the EXIT trap
cleanup() {
  if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
    ( cd "$work" && docker compose -p "$EDGE_PROJECT" \
        -f "$EDGE_COMPOSE" -f "$work/edge-override.yml" --env-file "$work/edge.env" \
        down -v --remove-orphans ) >/dev/null 2>&1 || true
    ( cd "$work" && docker compose -p "$APP_PROJECT" \
        -f "$work/app/docker-compose.yml" --env-file "$work/app/.env" \
        down -v --remove-orphans ) >/dev/null 2>&1 || true
    ( cd "$work" && docker compose -p "itest-orphans" \
        -f "$work/orphanA.yml" down -v --remove-orphans ) >/dev/null 2>&1 || true
    if [ "$created_net" = 1 ]; then docker network rm "$NET" >/dev/null 2>&1 || true; fi
  fi
  rm -rf "$work"
}
trap cleanup EXIT

failures=0
pass() { printf '  ok    %s\n' "$*"; }
fail() { printf '  FAIL  %s\n' "$*" >&2; failures=$((failures + 1)); }
skip() { printf '  skip  %s\n' "$*"; }

printf '\n== caddy project isolation tests ==\n'

if ! command -v docker >/dev/null 2>&1 || ! docker info >/dev/null 2>&1; then
  printf '\n  skip  Docker is not available — cannot run the isolation test\n'
  exit 0
fi
if ! docker compose version >/dev/null 2>&1; then
  printf '\n  skip  docker compose is not available\n'
  exit 0
fi

# ── prepare isolated (but real) project fixtures ─────────────────────────────
mkdir -p "$work/app"
cp "$APP_COMPOSE" "$work/app/docker-compose.yml"

cat > "$work/app/.env" <<EOF
COMPOSE_PROJECT_NAME=$APP_PROJECT
APP_ENV=production
APP_DEBUG=false
DB_DATABASE=fahimni_it
DB_USERNAME=fahimni_it
DB_PASSWORD=it-db-password-do-not-use
REDIS_PASSWORD=it-redis-password-do-not-use
REDIS_MAXMEMORY=64mb
BIND_ADDR=127.0.0.1
APP_PORT=18099
PROXY_ALIAS=fahimni-prod
FAHIMNI_PROXY_NETWORK=$NET
IMAGE_TAG=isolation-test
EOF

cat > "$work/edge.env" <<EOF
FAHIMNI_PROXY_NETWORK=$NET
FAHIMNI_DOMAIN=localhost
FAHIMNI_API_DOMAIN=api.localhost
FAHIMNI_UPSTREAM=fahimni-prod:80
ACME_EMAIL=it@example.com
FAHIMNI_CADDYFILE=$work/edge.caddyfile
EOF

# Plain-HTTP edge: no TLS/ACME in a test, and no host 80/443 binding.
cat > "$work/edge.caddyfile" <<'CADDY'
{
	admin off
}
:80 {
	respond "edge-ok" 200
}
CADDY

host_port=18080
for _ in 1 2 3 4 5; do
  if ! nc -z 127.0.0.1 "$host_port" >/dev/null 2>&1; then break; fi
  host_port=$((host_port + 1))
done
cat > "$work/edge-override.yml" <<EOF
services:
  caddy:
    ports: !override
      - "127.0.0.1:$host_port:80"
EOF

app()  { ( cd "$work/app" && docker compose -p "$APP_PROJECT" -f docker-compose.yml --env-file .env "$@" ); }
edge() { ( cd "$work" && docker compose -p "$EDGE_PROJECT" -f "$EDGE_COMPOSE" -f edge-override.yml --env-file edge.env "$@" ); }
ids()  { docker ps -a --filter "label=com.docker.compose.project=$1" --format '{{.ID}}' | sort; }
proj_label() { docker inspect -f '{{index .Config.Labels "com.docker.compose.project"}}' "$1" 2>/dev/null || true; }

# ── T0: both shipped composes resolve to DISTINCT projects ───────────────────
printf '\n-- T0 shipped project names are distinct\n'
app_name="$(docker compose --env-file "$work/app/.env" -f "$work/app/docker-compose.yml" \
  --project-directory "$work/app" config --format json 2>/dev/null | jq -r '.name' 2>/dev/null || true)"
edge_name="$(docker compose --env-file "$EDGE_ENV_EXAMPLE" -f "$EDGE_COMPOSE" \
  --project-directory "$work" config --format json 2>/dev/null | jq -r '.name' 2>/dev/null || true)"
if [ "$app_name" = "$APP_PROJECT" ]; then pass "app stack resolves to project '$app_name'"; else fail "app project is '$app_name' (want $APP_PROJECT)"; fi
if [ "$edge_name" = "$EDGE_PROJECT" ]; then pass "edge resolves to project '$edge_name'"; else fail "edge project is '$edge_name' (want $EDGE_PROJECT)"; fi
if [ -n "$app_name" ] && [ -n "$edge_name" ] && [ "$app_name" != "$edge_name" ]; then
  pass "the two project names can never collide"
else
  fail "the two project names are not distinct"
fi

# ── T1: the installer wires the edge explicitly ──────────────────────────────
printf '\n-- T1 installer wiring\n'
# shellcheck disable=SC2016  # the $-signs are literal, we grep the source text
if grep -q -- '-p "$EDGE_PROJECT"' "$INSTALLER"; then pass "installer passes -p \"\$EDGE_PROJECT\" to the edge"; else fail "installer does not pin the edge project"; fi
# shellcheck disable=SC2016
if grep -q 'EDGE_PROJECT="${FAHIMNI_EDGE_PROJECT:-fahimni-edge}"' "$INSTALLER"; then pass "edge project defaults to fahimni-edge"; else fail "edge project variable is not fahimni-edge"; fi
if grep -q '^name: fahimni-edge' "$EDGE_COMPOSE"; then pass "edge compose pins top-level name: fahimni-edge"; else fail "edge compose has no top-level name"; fi
# The original P0 cause: COMPOSE_PROJECT_NAME=fahimni forced both into one project.
if grep -qE '^[[:space:]]*COMPOSE_PROJECT_NAME=fahimni[[:space:]]*$' "$EDGE_ENV_EXAMPLE" "$EDGE_COMPOSE"; then
  fail "the edge env still sets COMPOSE_PROJECT_NAME=fahimni (the P0 cause)"
else
  pass "the edge never sets COMPOSE_PROJECT_NAME=fahimni"
fi
if grep -qE '^[[:space:]]*name: fahimni-edge_caddy_(data|config)' "$EDGE_COMPOSE"; then
  pass "edge volumes use fixed project-independent names"
else
  fail "edge volumes are not pinned to namespaced fixed names"
fi

# ── dynamic (real Docker) ────────────────────────────────────────────────────
printf '\n-- T2 real Docker: app -> edge -> re-run -> restart\n'

if docker volume inspect "${APP_PROJECT}_postgres_data" >/dev/null 2>&1 \
   || docker volume inspect "${APP_PROJECT}_redis_data" >/dev/null 2>&1 \
   || docker ps -a --filter "label=com.docker.compose.project=$APP_PROJECT" --format '{{.ID}}' | grep -q . \
   || docker ps -a --filter "label=com.docker.compose.project=$EDGE_PROJECT" --format '{{.ID}}' | grep -q .; then
  skip "a real '$APP_PROJECT'/'$EDGE_PROJECT' stack already exists — refusing to touch it"
else
  if docker network inspect "$NET" >/dev/null 2>&1; then
    pass "reusing the existing proxy network '$NET'"
  else
    docker network create "$NET" >/dev/null && created_net=1
    pass "created proxy network '$NET'"
  fi

  # 1) start the app DATA tier (real postgres + redis from the real compose file)
  if app up -d --remove-orphans postgres redis >/dev/null 2>&1; then
    pass "app stack started (postgres + redis)"
  else
    fail "could not start the app stack"
  fi

  for _ in $(seq 1 30); do
    if app exec -T postgres pg_isready -U fahimni_it -d fahimni_it >/dev/null 2>&1; then break; fi
    sleep 2
  done
  if app exec -T postgres pg_isready -U fahimni_it -d fahimni_it >/dev/null 2>&1; then
    pass "postgres is ready"
  else
    fail "postgres never became ready"
  fi

  app exec -T postgres psql -q -U fahimni_it -d fahimni_it \
    -c "CREATE TABLE IF NOT EXISTS it_probe(id serial primary key, v text);" \
    -c "INSERT INTO it_probe(v) VALUES ('keep-me');" >/dev/null 2>&1
  rows1="$(app exec -T postgres psql -tA -U fahimni_it -d fahimni_it -c 'SELECT count(*) FROM it_probe;' 2>/dev/null | tr -d '[:space:]')"
  if [ "${rows1:-0}" -ge 1 ]; then pass "wrote a row to the app database ($rows1)"; else fail "could not write app data"; fi

  app_ids_before="$(ids "$APP_PROJECT")"
  pg_id_before="$(app ps -q postgres)"
  if [ "$(proj_label "$pg_id_before")" = "$APP_PROJECT" ]; then pass "app container is labelled project $APP_PROJECT"; else fail "app container has the wrong project label"; fi

  # 2) start the edge (real caddy from the real edge compose)
  if edge up -d --remove-orphans >/dev/null 2>&1; then
    pass "edge started"
  else
    fail "could not start the edge"
  fi
  edge_id="$(edge ps -q caddy)"
  if [ -n "$edge_id" ] && [ "$(docker inspect -f '{{.State.Status}}' "$edge_id" 2>/dev/null)" = running ]; then
    pass "edge container is running"
  else
    fail "edge container is not running"
  fi
  if [ "$(proj_label "$edge_id")" = "$EDGE_PROJECT" ]; then pass "edge container is labelled project $EDGE_PROJECT"; else fail "edge container has the wrong project label"; fi

  # 3) the edge's --remove-orphans must NOT have touched the app
  if [ "$(ids "$APP_PROJECT")" = "$app_ids_before" ]; then
    pass "edge start removed/recreated no app container"
  else
    fail "edge start changed the app containers"
  fi

  # 4) re-running the app's --remove-orphans must not remove the edge
  app up -d --remove-orphans postgres redis >/dev/null 2>&1 || fail "app re-run failed"
  if [ "$(app ps -q postgres)" = "$pg_id_before" ]; then
    pass "app re-run did not recreate postgres (same container id)"
  else
    fail "app re-run recreated postgres"
  fi
  if [ -n "$(docker inspect -f '{{.State.Status}}' "$edge_id" 2>/dev/null)" ] \
     && [ "$(docker inspect -f '{{.State.Status}}' "$edge_id")" = running ]; then
    pass "app re-run did NOT remove the edge (P0 would have)"
  else
    fail "the app re-run removed the edge container"
  fi

  # 5) re-running the edge's --remove-orphans must not remove the app
  edge up -d --remove-orphans >/dev/null 2>&1 || fail "edge re-run failed"
  if [ "$(ids "$APP_PROJECT")" = "$app_ids_before" ]; then
    pass "edge re-run did NOT remove app containers"
  else
    fail "the edge re-run removed app containers"
  fi
  if [ "$(edge ps -q caddy)" = "$edge_id" ]; then
    pass "edge re-run did not recreate the edge"
  else
    fail "edge re-run recreated the edge"
  fi

  # 6) restart each project; data and the other project survive
  app restart >/dev/null 2>&1 || fail "app restart failed"
  edge restart >/dev/null 2>&1 || fail "edge restart failed"
  for _ in $(seq 1 30); do
    if app exec -T postgres pg_isready -U fahimni_it -d fahimni_it >/dev/null 2>&1; then break; fi
    sleep 2
  done
  rows2="$(app exec -T postgres psql -tA -U fahimni_it -d fahimni_it -c 'SELECT count(*) FROM it_probe;' 2>/dev/null | tr -d '[:space:]')"
  if [ "${rows2:-0}" -ge 1 ]; then pass "app data survived the restarts ($rows2 rows)"; else fail "app data was lost"; fi
  if [ "$(docker inspect -f '{{.State.Status}}' "$edge_id" 2>/dev/null)" = running ]; then
    pass "edge is still running after the restarts"
  else
    fail "edge did not survive the restarts"
  fi
fi

# ── T3 negative control: a SHARED project really does remove containers ─────
printf '\n-- T3 negative control (the bug this guards against)\n'
sh_img="$(awk '/image: redis@sha256:/{print $2; exit}' "$APP_COMPOSE")"
[ -n "$sh_img" ] || sh_img="redis:7-alpine"
cat > "$work/orphanA.yml" <<EOF
services:
  keep:
    image: $sh_img
    command: ["sh", "-c", "sleep 3600"]
    entrypoint: [""]
EOF
cat > "$work/orphanB.yml" <<EOF
services:
  other:
    image: $sh_img
    command: ["sh", "-c", "sleep 3600"]
    entrypoint: [""]
EOF
if ( cd "$work" && docker compose -p itest-orphans -f orphanA.yml up -d --remove-orphans ) >/dev/null 2>&1; then
  keep_id="$(cd "$work" && docker compose -p itest-orphans -f orphanA.yml ps -q keep)"
  ( cd "$work" && docker compose -p itest-orphans -f orphanB.yml up -d --remove-orphans ) >/dev/null 2>&1 || true
  if [ -n "$keep_id" ] && [ -z "$(docker inspect -f '{{.State.Status}}' "$keep_id" 2>/dev/null || true)" ]; then
    pass "proved a shared project's --remove-orphans removes the other file's container"
  else
    fail "negative control did not reproduce (fix may be masking a different issue)"
  fi
else
  skip "could not run the negative control (image $sh_img unavailable)"
fi
( cd "$work" && docker compose -p itest-orphans -f orphanA.yml down -v --remove-orphans ) >/dev/null 2>&1 || true

printf '\n'
if [ "$failures" -eq 0 ]; then
  echo "✅ caddy project isolation tests passed"
else
  echo "❌ $failures caddy project isolation test(s) failed" >&2
fi
exit "$(( failures > 0 ? 1 : 0 ))"
