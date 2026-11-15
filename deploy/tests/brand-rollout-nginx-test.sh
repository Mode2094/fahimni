#!/usr/bin/env bash
# =============================================================================
#  Regression tests for the Fahimni brand-rollout nginx handling.
#
#  The production rollout used to recreate app/frontend and leave the stack's
#  nginx pointing at the OLD container addresses — the site answered 502 until a
#  human ran `nginx -s reload`. This suite pins the fix and the four scenarios
#  the rollout must survive:
#
#    1. successful deploy  — nginx is reloaded so it re-resolves the new IPs
#    2. stale nginx        — before the reload the site is 502, after it 200
#    3. healthcheck failure — diagnostics explain WHY, then rollback runs
#    4. safe rollback      — the old tag is restored AND nginx is reloaded
#
#  It never touches Docker: `docker`, `curl`, `ss`, `getent` and `install` are
#  stubbed on PATH, and the nginx helpers are extracted verbatim from the real
#  script (between the FAHIMNI_LIB_* markers) and sourced. Structural assertions
#  read the script itself, so the reload can never be moved after the health
#  gate or dropped from rollback without failing here.
#
#  Run: deploy/tests/brand-rollout-nginx-test.sh
# =============================================================================

# The context variables below (REL, ENVF, SERVICES, ...) are consumed by the
# helper FUNCTIONS sourced from the rollout script, which ShellCheck cannot see
# (the source is assembled at run time), so it reports them as unused.
# shellcheck disable=SC2034
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd -P)"
SCRIPT="$REPO_DIR/deploy/scripts/fahimni-brand-rollout.sh"
[ -f "$SCRIPT" ] || { echo "missing: $SCRIPT" >&2; exit 1; }

work="$(mktemp -d "${TMPDIR:-/tmp}/fahimni-brand-test.XXXXXX")"
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/state"

export STUB_STATE="$work/state"
export STUB_LOG="$work/state/log"
: > "$STUB_LOG"

# ── stub `docker` ───────────────────────────────────────────────────────────
cat > "$work/bin/docker" <<'STUB'
#!/usr/bin/env bash
printf '[%s] docker %s\n' "${IMAGE_TAG:-}" "$*" >> "${STUB_LOG:?}"
state="${STUB_STATE:?}"
args="$*"
case "$args" in
  *"ps -q nginx"*)
    [ "${STUB_NGINX_RUNNING:-1}" = "1" ] && echo "cidnginx"
    exit 0 ;;
  *"exec -T nginx nginx -t"*)        exit "${STUB_T_RC:-0}" ;;
  *"exec -T nginx nginx -s reload"*) touch "$state/reloaded"; exit "${STUB_RELOAD_RC:-0}" ;;
  *"up -d --no-deps nginx"*)         touch "$state/nginx_started"; exit 0 ;;
  *"restart nginx"*)                 touch "$state/nginx_restarted"; exit 0 ;;
  *"exec -T app php"*)               echo "pong"; exit 0 ;;
  *"exec -T frontend wget"*)         echo "pong"; exit 0 ;;
  *" logs "*)                        echo "  stub | last log line"; exit 0 ;;
esac
case "$1" in
  inspect)
    fmt="$3"; cid="$4"; svc="${cid#cid}"
    case "$fmt" in
      *State.Running*) echo "$([ "${STUB_NGINX_RUNNING:-1}" = "1" ] && echo true || echo false)"; exit 0 ;;
      *State.Health*)  echo "${STUB_HEALTH:-healthy}"; exit 0 ;;
      *State.Status*)  echo "running"; exit 0 ;;
      *Config.Image*)
        eval "img=\${STUB_IMG_$svc:-}"
        [ -n "$img" ] || img="fahimni-$svc:${STUB_NEW_TAG:?}"
        echo "$img"; exit 0 ;;
    esac
    echo "unknown"; exit 0 ;;
  ps)
    if [ "${2:-}" = "-q" ]; then
      [ "${STUB_MISSING_ALL:-0}" = "1" ] && exit 0
      svc="$(printf '%s' "$args" | sed -n 's/.*name=\^fahimni-\([a-z]*\)-1\$.*/\1/p')"
      [ -n "$svc" ] || exit 0
      eval "miss=\${STUB_MISSING_$svc:-0}"
      [ "$miss" = "1" ] && exit 0
      echo "cid$svc"; exit 0
    fi
    echo "NAME  IMAGE  STATUS"; exit 0 ;;
esac
exit 0
STUB

# ── stub `curl` (only http_code-style calls matter here) ────────────────────
cat > "$work/bin/curl" <<'STUB'
#!/usr/bin/env bash
state="${STUB_STATE:?}"
case "$*" in
  *http_code*)
    case "${STUB_HTTP_MODE:-ok}" in
      ok)    echo "200" ;;
      fail)  echo "502" ;;
      stale) [ -f "$state/reloaded" ] && echo "200" || echo "502" ;;
    esac
    exit 0 ;;
esac
exit 0
STUB

# ── stub `ss` / `getent` (absent on macOS) ──────────────────────────────────
cat > "$work/bin/ss" <<'STUB'
#!/usr/bin/env bash
printf 'LISTEN 0 511 127.0.0.1:%s 0.0.0.0:*\n' "${STUB_PORT:-8080}"
STUB
cat > "$work/bin/getent" <<'STUB'
#!/usr/bin/env bash
printf '%s STREAM stub\n' "${STUB_DNS_IP:-203.0.113.10}"
STUB

# ── stub `install` (the real one cannot chown to the fahimni user off-host) ──
cat > "$work/bin/install" <<'STUB'
#!/usr/bin/env bash
# Handle exactly `install -m 600 -o U -g G SRC DST`: drop option/values, copy.
args=(); skip=0
for x in "$@"; do
  if [ "$skip" = 1 ]; then skip=0; continue; fi
  case "$x" in
    -m|-o|-g|-b|-S) skip=1 ;;
    -*) ;;
    *) args+=("$x") ;;
  esac
done
cp -p "${args[0]}" "${args[1]}"
STUB

chmod +x "$work/bin/"*
export PATH="$work/bin:$PATH"

# ── extract + source the nginx helpers from the real script ─────────────────
lib="$work/lib.sh"
{
  sed -n '/FAHIMNI_LIB_BEGIN/,/FAHIMNI_LIB_END/p' "$SCRIPT"
  sed -n '/FAHIMNI_ROLLBACK_BEGIN/,/FAHIMNI_ROLLBACK_END/p' "$SCRIPT"
} > "$lib"
if ! grep -q 'reload_nginx()' "$lib" || ! grep -q 'rollback()' "$lib"; then
  echo "could not extract the FAHIMNI_LIB/ROLLBACK blocks from $SCRIPT" >&2
  exit 1
fi
# shellcheck source=/dev/null
. "$lib"

# ── context the helpers read (a fake, writable release layout) ──────────────
REL="$work/rel-new"; OLD_REL="$work/rel-old"
mkdir -p "$REL" "$OLD_REL"
: > "$REL/docker-compose.yml"; : > "$OLD_REL/docker-compose.yml"
ENVF="$work/production.env"; ENV_BAK="$work/production.env.bak"
printf 'IMAGE_TAG=prod-hotfix1\nAPP_NAME=Tadreesly\n' > "$ENV_BAK"
cp -p "$ENV_BAK" "$ENVF"
SERVICES=(app frontend scheduler horizon)
NGINX_SVC=nginx
NEW_TAG=prod-brand1
OLD_TAG=prod-hotfix1
PORT=8080
BASE="http://127.0.0.1:8080"
HOSTHDR=fahimni.me
export STUB_NEW_TAG="$NEW_TAG" STUB_PORT="$PORT"

failures=0
pass(){ printf '  ok    %s\n' "$*"; }
fail(){ printf '  FAIL  %s\n' "$*" >&2; failures=$((failures + 1)); }
assert_file(){ if [ -f "$1" ]; then pass "$2"; else fail "$2"; fi; }
assert_no_file(){ if [ ! -f "$1" ]; then pass "$2"; else fail "$2"; fi; }
assert_contains(){ case "$1" in *"$2"*) pass "$3" ;; *) fail "$3" ;; esac; }

reset_state(){ rm -rf "$STUB_STATE"; mkdir -p "$STUB_STATE"; : > "$STUB_LOG"
  unset STUB_NGINX_RUNNING STUB_T_RC STUB_RELOAD_RC STUB_HTTP_MODE STUB_IMG_app \
        STUB_IMG_frontend STUB_IMG_scheduler STUB_IMG_horizon STUB_MISSING_ALL \
        STUB_MISSING_app STUB_MISSING_frontend STUB_MISSING_scheduler STUB_MISSING_horizon 2>/dev/null || true; }
log_order(){ grep -n "$1" "$STUB_LOG" | head -1 | cut -d: -f1; }

printf '\n== brand-rollout nginx regression tests ==\n'

# ── T0: the script is syntactically valid ───────────────────────────────────
if bash -n "$SCRIPT"; then pass "the rollout script parses (bash -n)"; else fail "bash -n"; fi

# ── T1: nginx healthy -> validate then reload, never restart ────────────────
printf '\n-- T1 successful deploy: nginx reloaded after the containers are replaced\n'
reset_state; export STUB_NGINX_RUNNING=1 STUB_T_RC=0 STUB_RELOAD_RC=0
if reload_nginx "$REL" >/dev/null 2>&1; then pass "reload_nginx returns 0"; else fail "reload_nginx returned non-zero"; fi
assert_file "$STUB_STATE/reloaded" "nginx -s reload was issued"
assert_no_file "$STUB_STATE/nginx_restarted" "nginx was NOT restarted (reload was enough)"
t="$(log_order 'nginx -t')"; r="$(log_order 'nginx -s reload')"
if [ -n "$t" ] && [ -n "$r" ] && [ "$t" -lt "$r" ]; then pass "config is validated (nginx -t) before the reload"; else fail "nginx -t does not precede nginx -s reload"; fi

# ── T2: broken config -> restart fallback (no reload) ───────────────────────
printf '\n-- T2 a broken nginx config falls back to a restart\n'
reset_state; export STUB_NGINX_RUNNING=1 STUB_T_RC=1 STUB_RELOAD_RC=0
if reload_nginx "$REL" >/dev/null 2>&1; then pass "reload_nginx still returns 0"; else fail "reload_nginx returned non-zero"; fi
assert_file "$STUB_STATE/nginx_restarted" "nginx was restarted when nginx -t failed"
assert_no_file "$STUB_STATE/reloaded" "no reload was attempted on a broken config"

# ── T3: nginx not running -> start it, then reload ──────────────────────────
printf '\n-- T3 a stopped nginx is started, then reloaded\n'
reset_state; export STUB_NGINX_RUNNING=0 STUB_T_RC=0 STUB_RELOAD_RC=0
reload_nginx "$REL" >/dev/null 2>&1 || true
assert_file "$STUB_STATE/nginx_started" "nginx was started (up -d --no-deps nginx)"
assert_file "$STUB_STATE/reloaded" "nginx was reloaded after being started"

# ── T4: reload command fails -> restart fallback ────────────────────────────
printf '\n-- T4 a failing reload command falls back to a restart\n'
reset_state; export STUB_NGINX_RUNNING=1 STUB_T_RC=0 STUB_RELOAD_RC=1
reload_nginx "$REL" >/dev/null 2>&1 || true
assert_file "$STUB_STATE/nginx_restarted" "nginx was restarted when nginx -s reload failed"

# ── T5: the 502 scenario — stale upstreams recover on reload ────────────────
printf '\n-- T5 stale nginx: 502 before the reload, 200 after (the 502 regression)\n'
reset_state; export STUB_HTTP_MODE=stale
before="$(http_code /up)"
if [ "$before" = "502" ]; then pass "before the reload the site is 502 (bug reproduced)"; else fail "expected 502 before reload, got $before"; fi
reload_nginx "$REL" >/dev/null 2>&1 || true
after="$(http_code /up)"
if [ "$after" = "200" ]; then pass "after the reload the site is 200 (fixed)"; else fail "expected 200 after reload, got $after"; fi

# ── T6: the reload must run BEFORE the health gate, and inside rollback ─────
printf '\n-- T6 structural: reload precedes the health gate; rollback reloads too\n'
# grep the literal call sites in the rollout source.
reload_new="reload_nginx \"\$REL\""
reload_old="reload_nginx \"\$OLD_REL\""
rl="$(grep -nF "$reload_new" "$SCRIPT" | head -1 | cut -d: -f1)"
hl="$(grep -n 'waiting for core health' "$SCRIPT" | head -1 | cut -d: -f1)"
if [ -n "$rl" ] && [ -n "$hl" ] && [ "$rl" -lt "$hl" ]; then
  pass "reload_nginx \"\$REL\" (line $rl) runs before the health gate (line $hl)"
else
  fail "the nginx reload does not precede the health gate (rl=$rl hl=$hl)"
fi
if awk '/^rollback\(\)/,/^}/' "$SCRIPT" | grep -qF "$reload_old"; then
  pass "rollback() reloads nginx for the restored release"
else
  fail "rollback() does not reload nginx"
fi
if awk '/if \[ "\$healthy" != 1 \]/,/^fi/' "$SCRIPT" | grep -q 'dump_diagnostics' \
   && awk '/if \[ "\$healthy" != 1 \]/,/^fi/' "$SCRIPT" | grep -q 'rollback'; then
  pass "a health failure dumps diagnostics and still rolls back automatically"
else
  fail "the health-failure branch does not both diagnose and roll back"
fi

# ── T6b: release-integrity guard (never deploy a tree without the Google fix) ─
printf '\n-- T6b structural: the release must carry the Google/session fix\n'
if grep -q 'FAHIMNI_RELEASE_SHA' "$SCRIPT"; then
  pass "the release commit is overridable (FAHIMNI_RELEASE_SHA)"
else
  fail "the release commit is hard-coded — cannot ship the unified RC"
fi
if grep -q 'request-origin.ts' "$SCRIPT" && grep -q 'refusing to deploy an older checkout' "$SCRIPT"; then
  pass "the rollout refuses a checkout without the Google/session BFF fix"
else
  fail "no guard against deploying a checkout without the Google/session fix"
fi

# ── T7: readiness gate passes on a fully-ready stack ────────────────────────
printf '\n-- T7 readiness gate accepts a healthy, on-tag, listening stack\n'
reset_state
if verify_readiness >/dev/null 2>&1; then pass "verify_readiness returns 0"; else fail "verify_readiness returned non-zero"; fi

# ── T8: readiness gate catches a container still on the old tag ─────────────
printf '\n-- T8 readiness gate rejects a container left on the old image\n'
reset_state; export STUB_IMG_frontend="fahimni-frontend:prod-hotfix1"
if out="$(verify_readiness 2>&1)"; then fail "verify_readiness should have failed"; else pass "verify_readiness returns non-zero"; fi
assert_contains "$out" "prod-hotfix1" "the wrong-tag warning names the stale image"

# ── T9: a missing container fails the gate ─────────────────────────────────
printf '\n-- T9 readiness gate rejects a missing container\n'
reset_state; export STUB_MISSING_scheduler=1
if verify_readiness >/dev/null 2>&1; then fail "verify_readiness should have failed on a missing container"; else pass "a missing container fails the gate"; fi

# ── T10: diagnostics name the cause before any rollback ────────────────────
printf '\n-- T10 healthcheck failure: diagnostics explain WHY before rollback\n'
reset_state; export STUB_HTTP_MODE=fail
diag="$(dump_diagnostics 2>&1 || true)"
assert_contains "$diag" "DIAGNOSTICS" "diagnostics header is printed"
assert_contains "$diag" "endpoint codes" "endpoint HTTP codes are printed"
assert_contains "$diag" "upstream probe" "the upstream probe (bypassing nginx) is printed"
assert_contains "$diag" "pong" "the probe reaches the app/frontend containers directly"
assert_contains "$diag" "log lines" "the container logs tail is printed"

# ── T11: safe rollback restores the tag, the env AND reloads nginx ─────────
printf '\n-- T11 safe rollback: old tag restored + env restored + nginx reloaded\n'
reset_state; export STUB_HTTP_MODE=stale
if rollback >/dev/null 2>&1; then pass "rollback returns 0 (health recovered)"; else fail "rollback returned non-zero"; fi
assert_file "$STUB_STATE/reloaded" "nginx was reloaded during the rollback"
if grep -q '\[prod-hotfix1\] docker compose --env-file .* up -d --no-deps app frontend scheduler horizon' "$STUB_LOG"; then
  pass "the previous tag prod-hotfix1 was brought back up"
else
  fail "the previous tag was not brought back up"
fi
if cmp -s "$ENV_BAK" "$ENVF"; then pass "the previous env file was restored"; else fail "the env file was not restored"; fi

# ── summary ────────────────────────────────────────────────────────────────
printf '\n'
if [ "$failures" -eq 0 ]; then
  echo "✅ all brand-rollout nginx tests passed"
else
  echo "❌ $failures brand-rollout nginx test(s) failed" >&2
fi
exit "$(( failures > 0 ? 1 : 0 ))"
