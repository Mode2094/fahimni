#!/usr/bin/env bash
# =============================================================================
#  Tests for deploy/scripts/fresh-install.sh (the standalone fresh installer).
#
#  Two layers:
#    • static     — the installer parses, is self-contained, refuses non-root,
#                   contains no destructive command, and ships a standalone
#                   Caddy edge that is NOT wired to any other project.
#    • hermetic   — the installer runs end-to-end against a stubbed Docker/git
#                   and is proven IDEMPOTENT: a second run reuses the release
#                   and the env file and never rotates the DB password.
#
#  Run: deploy/tests/fresh-install-test.sh
# =============================================================================
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd -P)"
SCRIPT="$REPO_DIR/deploy/scripts/fresh-install.sh"
CADDYFILE="$REPO_DIR/deploy/caddy/fahimni-standalone.caddy"
CADDY_COMPOSE="$REPO_DIR/deploy/caddy/docker-compose.caddy.yml"
CADDY_ENV_EXAMPLE="$REPO_DIR/deploy/caddy/caddy.env.example"

work="$(mktemp -d "${TMPDIR:-/tmp}/fahimni-fresh-test.XXXXXX")"
# shellcheck disable=SC2329  # invoked indirectly via the EXIT trap
cleanup() {
  # The hermetic run below runs the installer through sudo, so $work can contain
  # root-owned files that a plain rm (as the CI user) cannot delete. Fall back to
  # a root rm and never let the trap change the test's exit status.
  rm -rf "$work" 2>/dev/null || true
  if [ -d "$work" ]; then
    if [ "${EUID:-$(id -u)}" -eq 0 ]; then rm -rf "$work" 2>/dev/null || true
    else sudo rm -rf "$work" 2>/dev/null || true; fi
  fi
  return 0
}
trap cleanup EXIT

failures=0
pass() { printf '  ok    %s\n' "$*"; }
fail() { printf '  FAIL  %s\n' "$*" >&2; failures=$((failures + 1)); }

# Read a file that the sudo-run installer may have created as root (mode 600 in
# a 0700 directory), which the current (non-root) user cannot open directly.
read_root() {
  if [ "${EUID:-$(id -u)}" -eq 0 ] || [ -r "$1" ]; then cat "$1"
  else sudo cat "$1" 2>/dev/null; fi
}

printf '\n== fresh-install tests ==\n'

# ── T0: the installer parses ─────────────────────────────────────────────────
printf '\n-- T0 the installer parses\n'
if [ -f "$SCRIPT" ]; then pass "deploy/scripts/fresh-install.sh exists"; else fail "installer missing"; exit 1; fi
if bash -n "$SCRIPT"; then pass "bash -n"; else fail "bash -n"; fi
if [ -x "$SCRIPT" ]; then pass "installer is executable"; else fail "installer is not executable"; fi

# ── T1: --help works without root and names the key flags ────────────────────
printf '\n-- T1 --help is safe and informative\n'
if out="$(bash "$SCRIPT" --help 2>&1)"; then
  case "$out" in
    *--acme-email*) pass "--help documents --acme-email" ;;
    *) fail "--help does not document --acme-email" ;;
  esac
  case "$out" in
    *--verify-only*) pass "--help documents --verify-only" ;;
    *) fail "--help does not document --verify-only" ;;
  esac
else
  fail "--help exited non-zero"
fi

# ── T2: refuses to run as a non-root user ────────────────────────────────────
printf '\n-- T2 a non-root user is refused\n'
if [ "${EUID:-$(id -u)}" -ne 0 ]; then
  if bash "$SCRIPT" --skip-host-bootstrap >/dev/null 2>&1; then
    fail "the installer ran as a non-root user"
  else
    rc=$?
    if [ "$rc" -ne 0 ]; then pass "non-root run refused (exit $rc)"; else fail "non-root run: unexpected exit"; fi
  fi
else
  printf '  skip  running as root; cannot exercise the refusal path\n'
fi

# ── T3: self-contained (no sourcing of repo files) ───────────────────────────
printf '\n-- T3 the installer is self-contained\n'
if grep -Eq '^[[:space:]]*(\.|source)[[:space:]]' "$SCRIPT"; then
  fail "the installer sources another file (breaks curl | bash)"
else
  pass "no source/. directives"
fi

# ── T4: no destructive database/volume command ───────────────────────────────
printf '\n-- T4 no destructive command\n'
# Ignore comment lines: the documentation names the commands it refuses to run.
code_only="$(mktemp)"
grep -v '^[[:space:]]*#' "$SCRIPT" > "$code_only"
if grep -Eq 'migrate:fresh|migrate:rollback|down -v|volume rm|DROP DATABASE' "$code_only"; then
  fail "a destructive command is present in the installer"
  grep -nE 'migrate:fresh|migrate:rollback|down -v|volume rm|DROP DATABASE' "$code_only" >&2 || true
else
  pass "no migrate:fresh / down -v / volume rm / DROP DATABASE in executable code"
fi
rm -f "$code_only"

# ── T5: fresh database + admin are created via migrations/seeders ────────────
printf '\n-- T5 creates a fresh DB and the first admin\n'
if grep -q 'init-env.sh' "$SCRIPT"; then pass "reuses init-env.sh to generate secrets"; else fail "does not generate secrets"; fi
if grep -q 'SUPER_ADMIN_PASSWORD' "$SCRIPT"; then pass "wires the super-admin password"; else fail "no super-admin handling"; fi
if grep -q 'migrate' "$SCRIPT" && grep -q 'seed' "$SCRIPT"; then pass "waits for the migrate+seed service"; else fail "no migrate/seed handling"; fi

# ── T6: standalone Caddy edge is independent of other projects ───────────────
printf '\n-- T6 the Caddy edge is standalone\n'
for f in "$CADDYFILE" "$CADDY_COMPOSE" "$CADDY_ENV_EXAMPLE"; do
  if [ -f "$f" ]; then pass "present: $(basename "$f")"; else fail "missing: $f"; fi
done
if grep -qi 'looksloop' "$CADDYFILE" "$CADDY_COMPOSE" "$CADDY_ENV_EXAMPLE" 2>/dev/null; then
  fail "the Caddy edge references LooksLoop"
else
  pass "the Caddy edge never references LooksLoop"
fi
if grep -q 'reverse_proxy' "$CADDYFILE" && grep -q 'FAHIMNI_UPSTREAM' "$CADDYFILE"; then
  pass "Caddyfile proxies to the configurable upstream"
else
  fail "Caddyfile has no reverse_proxy/upstream"
fi
if grep -qF "email {\$ACME_EMAIL}" "$CADDYFILE"; then pass "ACME email is configured (automatic HTTPS)"; else fail "no ACME email block"; fi
if grep -q 'admin off' "$CADDYFILE"; then pass "Caddy admin API is disabled"; else fail "Caddy admin API is not disabled"; fi
# Balanced braces are a cheap proxy for a well-formed config.
open="$(tr -cd '{' < "$CADDYFILE" | wc -c | tr -d ' ')"
close="$(tr -cd '}' < "$CADDYFILE" | wc -c | tr -d ' ')"
if [ "$open" = "$close" ] && [ "$open" -gt 0 ]; then pass "Caddyfile braces balanced ($open)"; else fail "Caddyfile braces unbalanced ($open/$close)"; fi

# ── T6b: custom teacher domains are served without a per-domain edit ─────────
# The apex/www/api hosts are written literally, but a teacher's OWN domain is
# unknown when the file is generated. It is served by the `:443` catch-all:
# Cloudflare for SaaS terminates public TLS at the edge and forwards to the
# zone's fallback origin, so the origin only presents Caddy's internal
# certificate on the Cloudflare→origin hop (zone SSL mode "Full"). No public
# certificate is ever requested per custom domain, and connecting a new domain
# needs no server edit and no reload. The on-demand-TLS `ask` gate is retained
# as defence-in-depth in case the catch-all is switched back to `tls on_demand`.
printf '\n-- T6b custom domains are handled by the Cloudflare-for-SaaS catch-all\n'
if grep -q 'public/tls/ask' "$CADDYFILE"; then pass "ask gate retained (defence-in-depth)"; else fail "no ask gate (any hostname could request a cert)"; fi
if grep -qE '^:443 \{' "$CADDYFILE"; then pass ":443 catch-all present for unknown hosts"; else fail "no :443 catch-all (custom domains are never served)"; fi
if awk '/^:443 \{/{f=1} f&&/tls internal/{print;exit}' "$CADDYFILE" | grep -q 'tls internal'; then
  pass "catch-all presents the origin's internal certificate (Cloudflare terminates public TLS)"
else
  fail "catch-all does not use tls internal"
fi
if awk '/^:443 \{/{f=1} f&&/tls *\{/{print;exit}' "$CADDYFILE" | grep -q 'on_demand'; then
  fail "catch-all still issues public certificates on demand (should be Cloudflare-terminated)"
else
  pass "catch-all does not request public certificates"
fi
if grep -qE '^:80 \{' "$CADDYFILE"; then pass ":80 catch-all redirects custom domains to HTTPS"; else fail "no :80 catch-all"; fi

# ── T7: the Caddy edge is pinned and publishes only 80/443 ───────────────────
printf '\n-- T7 the Caddy edge is pinned and minimal\n'
if grep -Eq 'image: caddy@sha256:[0-9a-f]{64}' "$CADDY_COMPOSE"; then pass "Caddy image pinned by digest"; else fail "Caddy image is not pinned"; fi
if grep -q '"443:443"' "$CADDY_COMPOSE" && grep -q '"80:80"' "$CADDY_COMPOSE"; then pass "publishes 80/443"; else fail "does not publish 80/443"; fi
if grep -q 'external: true' "$CADDY_COMPOSE"; then pass "joins the external proxy network only"; else fail "proxy network is not external"; fi

# ── T7b: the Caddy edge is its OWN compose project (P0 regression guard) ─────
# The app stack is project `fahimni`; the edge must be `fahimni-edge`. Both use
# `up --remove-orphans`, so a shared project name would let either side stop or
# remove the other's containers.
printf '\n-- T7b the Caddy edge is a separate compose project\n'
if grep -q '^name: fahimni-edge' "$CADDY_COMPOSE"; then pass "edge compose pins top-level name: fahimni-edge"; else fail "edge compose has no top-level project name"; fi
if grep -qE '^[[:space:]]*name: fahimni-edge_caddy_(data|config)' "$CADDY_COMPOSE"; then
  pass "edge volumes use fixed, project-independent names"
else
  fail "edge volumes are not pinned to fahimni-edge_* names"
fi
if grep -qE '^[[:space:]]*COMPOSE_PROJECT_NAME=fahimni[[:space:]]*$' "$CADDY_ENV_EXAMPLE" "$CADDY_COMPOSE"; then
  fail "the edge still sets COMPOSE_PROJECT_NAME=fahimni (the P0 cause)"
else
  pass "the edge never sets COMPOSE_PROJECT_NAME=fahimni"
fi
# shellcheck disable=SC2016  # literal $, we are grepping the installer source
if grep -q 'EDGE_PROJECT="${FAHIMNI_EDGE_PROJECT:-fahimni-edge}"' "$SCRIPT"; then pass "installer defaults the edge project to fahimni-edge"; else fail "installer has no fahimni-edge project variable"; fi
# shellcheck disable=SC2016
if grep -q -- '-p "$EDGE_PROJECT"' "$SCRIPT"; then pass "installer brings the edge up in its own project (-p)"; else fail "installer does not pass -p to the edge"; fi
if grep -q '^COMPOSE_PROJECT_NAME=fahimni$' "$REPO_DIR/deploy/env/production.env.example"; then pass "app stack is the separate project fahimni"; else fail "app project name changed (isolation assumption broken)"; fi

# ── T7c: DNS is verified before TLS is trusted ──────────────────────────────
printf '\n-- T7c DNS verification is present\n'
if grep -q 'resolve_a' "$SCRIPT" && grep -q 'does not resolve' "$SCRIPT"; then pass "installer resolves and checks apex/api/www DNS"; else fail "installer does not verify DNS"; fi

# ── T8: init-env still generates every required secret ───────────────────────
printf '\n-- T8 secret generation (init-env.sh)\n'
if command -v openssl >/dev/null 2>&1; then
  envout="$work/production.env"
  if bash "$REPO_DIR/deploy/scripts/init-env.sh" --env production --out "$envout" >/dev/null 2>&1; then
    missing=""
    for k in APP_KEY DB_PASSWORD REDIS_PASSWORD VIDEO_SIGNING_KEY REFUND_WEBHOOK_SECRET SUPER_ADMIN_PASSWORD BACKUP_ENCRYPTION_KEY; do
      if ! grep -q "^${k}=.\\+" "$envout"; then missing="$missing $k"; fi
    done
    if [ -z "$missing" ]; then pass "all required secrets generated"; else fail "missing generated secrets:$missing"; fi
    if grep -q '^DB_PASSWORD=CHANGE_ME' "$envout"; then fail "DB_PASSWORD stayed a placeholder"; else pass "DB_PASSWORD is real"; fi
  else
    fail "init-env.sh failed"
  fi
else
  printf '  skip  openssl not available\n'
fi

# ── T9: hermetic idempotent end-to-end run with stubbed Docker/git ───────────
printf '\n-- T9 hermetic run is idempotent\n'

if ! command -v sudo >/dev/null 2>&1 && [ "${EUID:-$(id -u)}" -ne 0 ]; then
  printf '  skip  no sudo and not root\n'
else
  bin="$work/bin"
  mkdir -p "$bin"
  FAKE_SHA="1111111111111111111111111111111111111111"
  FAKE_BRANCH="fix/launch-blockers-rc"

  # git stub: ld-remote reports the fake tip; clone materialises a copy of the
  # real repo (so the release contains the real tooling); rev-parse echoes the
  # fake SHA so a second run detects the release as already installed.
  cat > "$bin/git" <<'GITSTUB'
#!/usr/bin/env bash
case "${1:-}" in
  ls-remote)
    printf '%s\trefs/heads/%s\n' "${FAKE_SHA:-deadbeef}" "${FAKE_BRANCH:-main}"
    exit 0 ;;
  clone)
    dest=""
    for a in "$@"; do dest="$a"; done
    # Materialise a MINIMAL release fixture (never copy node_modules/.next).
    mkdir -p "$dest/deploy" "$dest/frontend/public" "$dest/.git"
    cp -a "${FAKE_SRC}/docker-compose.yml" "$dest/" 2>/dev/null || :
    cp -a "${FAKE_SRC}/deploy/scripts" "$dest/deploy/" 2>/dev/null || :
    cp -a "${FAKE_SRC}/deploy/env" "$dest/deploy/" 2>/dev/null || :
    cp -a "${FAKE_SRC}/deploy/caddy" "$dest/deploy/" 2>/dev/null || :
    cp -a "${FAKE_SRC}/frontend/public/logo.png" "$dest/frontend/public/" 2>/dev/null || :
    exit 0 ;;
  -c)
    shift 2
    if [ "${1:-}" = "-C" ]; then shift 2; fi
    case "${1:-}" in
      rev-parse) printf '%s\n' "${FAKE_SHA:-deadbeef}" ;;
      *) exit 0 ;;
    esac
    exit 0 ;;
  *) exit 0 ;;
esac
GITSTUB

  # docker stub: answers `info`, networks, and every compose sub-command the
  # installer uses. Postgres/Redis report no published ports; migrate exits 0;
  # the tinker probe returns one super-admin.
  cat > "$bin/docker" <<'DOCKERSTUB'
#!/usr/bin/env bash
state="${DOCKER_STATE:-/tmp/fahimni-docker-state}"
mkdir -p "$state"
if [ "${1:-}" = "info" ]; then exit 0; fi
if [ "${1:-}" = "--version" ]; then echo "Docker version 27.0.0, build test"; exit 0; fi
if [ "${1:-}" = "network" ]; then
  case "${2:-}" in
    inspect) [ -f "$state/net-${3:-x}" ] && exit 0 || exit 1 ;;
    create)  touch "$state/net-${3:-x}"; exit 0 ;;
    *) exit 0 ;;
  esac
fi
if [ "${1:-}" = "inspect" ]; then
  fmt="$3"; id="$4"
  case "$id" in
    *migrate*)
      case "$fmt" in
        *ExitCode*) echo 0 ;;
        *) echo exited ;;
      esac ;;
    *) echo running ;;
  esac
  exit 0
fi
if [ "${1:-}" = "compose" ]; then
  # find the sub-command (skip -f FILE / --env-file FILE / -q etc.)
  shift
  while [ $# -gt 0 ]; do
    case "$1" in
      -f|--env-file|-p|--project-name) shift 2 ;;
      -q|--quiet|--remove-orphans|-d|--detach|-T) shift ;;
      config|build|up) exit 0 ;;
      version) exit 0 ;;
      ps)
        case "$*" in
          *"-q"*)
            case "$*" in
              *migrate*) echo "cid-migrate" ;;
              *) echo "cid-app" ;;
            esac ;;
          *) printf 'app \nfrontend \nnginx \npostgres \nredis \nscheduler \nhorizon \n' ;;
        esac
        exit 0 ;;
      exec)
        case "$*" in
          *tinker*) echo "1" ;;
          *migrate:status*) echo "Ran .... 2026_01_01_000001_create_users_table" ;;
          *) : ;;
        esac
        exit 0 ;;
      *) exit 0 ;;
    esac
    shift
  done
  exit 0
fi
exit 0
DOCKERSTUB

  # docker-compose-plugin shim for `docker compose version` (handled above).

  # curl stub: http_code -> 200 (or 401 for the login probe), header calls ->
  # HSTS, the settings endpoint -> the canonical brand JSON, and -o writes the
  # real logo so the served-logo hash check can pass.
  cat > "$bin/curl" <<'CURLSTUB'
#!/usr/bin/env bash
out="" url="" wcode=0 headers=0 has_d=0
while [ $# -gt 0 ]; do
  case "$1" in
    -o) out="$2"; shift 2 ;;
    -w) wcode=1; shift 2 ;;
    %\{http_code\}) wcode=1; shift ;;
    -I|-sI|-sSI|--head) headers=1; shift ;;
    -d) has_d=1; shift 2 ;;
    -H|--max-time|--config) shift 2 ;;
    http*) url="$1"; shift ;;
    *) shift ;;
  esac
done
if [ "$wcode" = 1 ]; then
  if [ "$has_d" = 1 ]; then printf '401'; else printf '200'; fi
  exit 0
fi
if [ -n "$out" ] && [ "$out" != /dev/null ]; then
  cp "${FAKE_LOGO:-/dev/null}" "$out" 2>/dev/null || :
  exit 0
fi
if [ "$headers" = 1 ]; then
  printf 'HTTP/2 200\r\nstrict-transport-security: max-age=31536000; includeSubDomains\r\n\r\n'
  exit 0
fi
case "$url" in
  *platform-settings*) printf '{"settings":{"platform_name":"منصة فهمني"}}'; exit 0 ;;
esac
exit 0
CURLSTUB

  # openssl stub: delegate everything real, but accept the TLS probe.
  cat > "$bin/openssl" <<'SSLSTUB'
#!/usr/bin/env bash
if [ "${1:-}" = "s_client" ]; then exit 0; fi
exec /usr/bin/openssl "$@"
SSLSTUB

  # user/group stubs (a real fahimni user cannot be created hermetically).
  for c in useradd usermod getent; do printf '#!/usr/bin/env bash\nexit 0\n' > "$bin/$c"; done
  chmod +x "$bin"/*

  PW="Str0ng-Passw0rd-9f3a"
  run_installer() {
    local logfile="$1"; shift
    local cmd=(env "PATH=$bin:$PATH" \
      "FAKE_SRC=$REPO_DIR" "FAKE_SHA=$FAKE_SHA" "FAKE_BRANCH=$FAKE_BRANCH" \
      "FAKE_LOGO=$REPO_DIR/frontend/public/logo.png" \
      "DOCKER_STATE=$work/dstate" \
      "FAHIMNI_ADMIN_CREDS_FILE=$work/creds.txt" \
      "SUPER_ADMIN_PASSWORD=$PW" \
      bash "$SCRIPT" \
        --skip-host-bootstrap --skip-firewall --skip-swap --no-caddy \
        --release-sha "$FAKE_SHA" --branch "$FAKE_BRANCH" \
        --stack-dir "$work/stack" --env-file "$work/t9/production.env" \
        --domain fahimni.test --api-domain api.fahimni.test "$@")
    if [ "${EUID:-$(id -u)}" -eq 0 ]; then
      "${cmd[@]}" > "$logfile" 2>&1
    else
      sudo -E "${cmd[@]}" 2>&1 | tee "$logfile" >/dev/null
    fi
  }

  if bash -c 'true' >/dev/null 2>&1 && { [ "${EUID:-$(id -u)}" -eq 0 ] || sudo -n true >/dev/null 2>&1; }; then
    if run_installer "$work/run1.log" --admin-email admin@fahimni.test; then
      pass "first run succeeded"
      if grep -q 'installation complete' "$work/run1.log"; then pass "reports completion"; else fail "no completion summary"; fi
      if grep -qE 'CHECK\(S\) FAILED' "$work/run1.log"; then fail "verification reported failures"; else pass "verification passed"; fi
      if grep -q 'generating a new env file' "$work/run1.log"; then pass "first run generated fresh secrets"; else fail "first run did not generate secrets"; fi
    else
      fail "first run failed"
      sed 's/^/    | /' "$work/run1.log" | tail -40 >&2
    fi

    db1="$(read_root "$work/t9/production.env" | sed -n 's/^DB_PASSWORD=//p' | head -1 || true)"
    if [ -n "$db1" ]; then pass "generated a DB password"; else fail "no DB password generated"; fi

    if run_installer "$work/run2.log" --admin-email admin@fahimni.test; then
      pass "second run succeeded"
    else
      fail "second run (idempotent) failed"
      sed 's/^/    | /' "$work/run2.log" | tail -40 >&2
    fi
    if grep -q 'reusing existing env file' "$work/run2.log"; then pass "env file reused (secrets not rotated)"; else fail "env file was regenerated"; fi
    if grep -q 'already present — reusing' "$work/run2.log"; then pass "release reused"; else fail "release was re-cloned"; fi
    db2="$(read_root "$work/t9/production.env" | sed -n 's/^DB_PASSWORD=//p' | head -1 || true)"
    if [ -n "$db1" ] && [ "$db1" = "$db2" ]; then pass "DB password unchanged across runs"; else fail "DB password changed on re-run"; fi
    if [ -f "$work/creds.txt" ]; then pass "admin credentials file written"; else fail "admin credentials file missing"; fi

    # Re-run WITHOUT --admin-email: the configured admin login must be kept.
    if run_installer "$work/run3.log"; then
      pass "third run (no --admin-email) succeeded"
    else
      fail "third run failed"
      sed 's/^/    | /' "$work/run3.log" | tail -40 >&2
    fi
    if grep -q 'keeping the existing admin email' "$work/run3.log"; then pass "existing admin email is kept on re-run"; else fail "admin email was not preserved"; fi
    admin_email_now="$(read_root "$work/t9/production.env" | sed -n 's/^SUPER_ADMIN_EMAIL=//p' | head -1 || true)"
    if [ "$admin_email_now" = "admin@fahimni.test" ]; then pass "admin email unchanged without an explicit request"; else fail "admin email changed to '$admin_email_now'"; fi
  else
    printf '  skip  cannot obtain root for the hermetic run\n'
  fi
fi

printf '\n'
if [ "$failures" -eq 0 ]; then
  echo "✅ all fresh-install tests passed"
else
  echo "❌ $failures fresh-install test(s) failed" >&2
fi
exit "$(( failures > 0 ? 1 : 0 ))"
