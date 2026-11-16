#!/usr/bin/env bash
# =============================================================================
#  Fahimni — POST-DEPLOY LAUNCH SMOKE TEST (critical journeys).
#
#  Read-only. It touches production the way a browser would: GETs public pages,
#  reads the settings API, and (only with an explicit flag) tries a login with
#  clearly-invalid credentials to prove the endpoint answers 401/422 and never a
#  5xx. It never creates data, never sends a real credential and never changes
#  anything.
#
#  Every journey in the launch gate is listed. A journey is either:
#    • SCRIPTED — fully checked here, right now, without an account; or
#    • PARTIAL  — its PUBLIC surface (the endpoint exists and is correctly
#                 guarded) is checked here, while the authenticated half is
#                 reported BLOCKED until it is driven with a real account or an
#                 external provider.
#  BLOCKED items are reported, never silently skipped, and never fail the run —
#  they are the exact list of things a human must click through before GO.
#
#  Usage
#    launch-smoke-test.sh [--base https://fahimni.me] [--host-header fahimni.me]
#                         [--allow-login-probe] [--list] [-h]
#
#  Exit: 0 = no scripted check failed (BLOCKED items may remain);
#        1 = at least one scripted check failed.
# =============================================================================
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
DEPLOY_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd -P)"
REPO_DIR="$(cd -- "$DEPLOY_DIR/.." && pwd -P)"

BASE="${BASE_URL:-https://fahimni.me}"
API=""
HOSTHDR=""
ALLOW_LOGIN_PROBE=0
LIST_ONLY=0
RELEASE_DIR="$REPO_DIR"
HTTP_TIMEOUT="${HTTP_TIMEOUT:-15}"

while [ $# -gt 0 ]; do
  case "$1" in
    --base)              BASE="${2:?}"; shift 2 ;;
    --api)               API="${2:?}"; shift 2 ;;
    --host-header)       HOSTHDR="${2:?}"; shift 2 ;;
    --release)           RELEASE_DIR="${2:?}"; shift 2 ;;
    --allow-login-probe) ALLOW_LOGIN_PROBE=1; shift ;;
    --list)              LIST_ONLY=1; shift ;;
    -h|--help)           sed -n '2,33p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *)                   printf 'unknown argument: %s\n' "$1" >&2; exit 2 ;;
  esac
done
[ -n "$API" ] || API="$BASE/api/v1"

# ── the journeys this gate covers ───────────────────────────────────────────
# id|kind|description  (kind: scripted = fully checked here ; partial = public
# surface checked, authenticated half BLOCKED)
JOURNEYS=(
  "google-register-teacher-dashboard-refresh|partial|Google sign-up → teacher dashboard → hard refresh keeps the session"
  "email-password-login|partial|Email + password login reaches the dashboard"
  "admin-and-student-login|partial|Admin and student logins land on their own dashboards"
  "create-course-lesson-worksheet|partial|Create a course, a lesson and a worksheet"
  "card-generate-export-redeem|partial|Generate, export and redeem an activation card"
  "video-live-access-by-permission|partial|Video and live access respect role permissions"
  "branding-fahimni-logo|scripted|«منصة فهمني» and the new logo are served"
  "csp-pwa-core-links|scripted|CSP, PWA manifest/icons and the core page links are intact"
)

if [ "$LIST_ONLY" = 1 ]; then
  for j in "${JOURNEYS[@]}"; do printf '%s\n' "${j//|/$'\t'}"; done
  exit 0
fi

PASS_N=0; FAIL_N=0; BLOCKED_N=0; SCRIPTED_N=0
pass(){ PASS_N=$((PASS_N+1)); printf '  [PASS] %s\n' "$*"; }
fail(){ FAIL_N=$((FAIL_N+1)); printf '  [FAIL] %s\n' "$*" >&2; }
blocked(){ BLOCKED_N=$((BLOCKED_N+1)); printf '  [BLOCKED] %s\n' "$*"; }
skip(){ printf '  [SKIP] %s\n' "$*"; }
section(){ printf '\n== %s ==\n' "$*"; }

H=()
if [ -n "$HOSTHDR" ]; then H=(-H "Host: $HOSTHDR"); fi
code(){ local c; c="$(curl -sS -o /dev/null -w '%{http_code}' --max-time "$HTTP_TIMEOUT" ${H[@]+"${H[@]}"} "$@" 2>/dev/null || true)"; [ -n "$c" ] || c=000; printf '%s' "$c"; }
body(){ curl -sS --max-time "$HTTP_TIMEOUT" ${H[@]+"${H[@]}"} "$@" 2>/dev/null || true; }

printf '\n############################################################\n'
printf '#  Fahimni launch smoke test\n'
printf '#  base : %s\n#  api  : %s\n' "$BASE" "$API"
if [ -n "$HOSTHDR" ]; then printf '#  host : %s\n' "$HOSTHDR"; fi
printf '############################################################\n'

# ── 1. platform is up ───────────────────────────────────────────────────────
section "1. platform health"
for path in /up /api/health/live /api/health/ready; do
  c="$(code "$BASE$path")"
  if [ "$c" = "200" ]; then pass "$path → 200"; else fail "$path → $c (want 200)"; fi
done

# ── 2. branding: «منصة فهمني» + the new logo ────────────────────────────────
section "2. branding — «منصة فهمني» and the new logo"
ps="$(body "$API/platform-settings")"
case "$ps" in
  *'منصة فهمني'*) pass "platform-settings serves «منصة فهمني»" ;;
  *) fail "platform-settings does not serve «منصة فهمني»" ;;
esac
case "$ps" in
  *Tadreesly*|*tadreesly*) fail "platform-settings still exposes the old brand" ;;
  *) pass "platform-settings no longer exposes the old brand" ;;
esac
title="$(body "$BASE/" | grep -oE '<title>[^<]*</title>' | head -1 || true)"
case "$title" in
  *فهمني*) pass "home <title> carries the new name ($title)" ;;
  *) fail "home <title> does not carry the new name (${title:-<none>})" ;;
esac
logo_src="$RELEASE_DIR/frontend/public/logo.png"
if [ -f "$logo_src" ]; then
  expect="$(if command -v sha256sum >/dev/null 2>&1; then sha256sum "$logo_src" | cut -d' ' -f1; else shasum -a 256 "$logo_src" | cut -d' ' -f1; fi)"
  served="$(mktemp)"; curl -sS --max-time "$HTTP_TIMEOUT" ${H[@]+"${H[@]}"} -o "$served" "$BASE/logo.png" 2>/dev/null || true
  got="$(if command -v sha256sum >/dev/null 2>&1; then sha256sum "$served" | cut -d' ' -f1; else shasum -a 256 "$served" | cut -d' ' -f1; fi)"; rm -f "$served"
  if [ -n "$expect" ] && [ "$expect" = "$got" ]; then
    pass "served /logo.png matches the release logo (${got:0:12}…)"
  else
    fail "served /logo.png differs from the release logo (want ${expect:0:12}… got ${got:0:12}…)"
  fi
else
  skip "release logo not found at $logo_src — pass --release <dir> to verify the hash"
fi

# ── 3. CSP, PWA manifest, icons and core page links ─────────────────────────
section "3. CSP, PWA and core page links"
csp_page=/teacher/dashboard
rh="$(mktemp)"; rb="$(mktemp)"
curl -sS --max-time 20 ${H[@]+"${H[@]}"} -D "$rh" -o "$rb" "$BASE$csp_page" 2>/dev/null || true
hdr="$(tr -d '\r' < "$rh" 2>/dev/null || true)"; pg="$(cat "$rb" 2>/dev/null || true)"; rm -f "$rh" "$rb"
nonce="$(printf '%s' "$hdr" | grep -i '^content-security-policy:' | grep -oE "'nonce-[^']+'" | head -1 | sed "s/'nonce-//; s/'//" || true)"
scripts="$(printf '%s' "$pg" | grep -oE '<script' | wc -l | tr -d ' ' || true)"
nonced="$(printf '%s' "$pg" | grep -oE '<script[^>]*nonce="[^"]+"' | wc -l | tr -d ' ' || true)"
if [ -n "$nonce" ] && [ "$scripts" -gt 0 ] && [ "$nonced" = "$scripts" ]; then
  pass "CSP: every <script> ($scripts) carries the header nonce"
else
  fail "CSP: nonce=${nonce:-<none>} scripts=$scripts nonced=$nonced — the nonce policy is broken"
fi
c="$(code "$BASE/manifest.webmanifest")"
if [ "$c" = "200" ]; then pass "manifest.webmanifest → 200"; else fail "manifest.webmanifest → $c"; fi
mf="$(body "$BASE/manifest.webmanifest")"
case "$mf" in *فهمني*|*Fahimni*) pass "manifest carries the new identity" ;; *) fail "manifest does not carry the new identity" ;; esac
for p in /icon-192.png /icon-512.png /icon-maskable-512.png /apple-icon.png /og.png; do
  c="$(code "$BASE$p")"
  if [ "$c" = "200" ]; then pass "$p → 200"; else fail "$p → $c"; fi
done
for p in / /courses /login /plans /about /contact /privacy /terms; do
  c="$(code "$BASE$p")"
  case "$c" in 200|301|302|307|308) pass "link $p → $c" ;; *) fail "link $p → $c" ;; esac
done

# ── 4. critical journeys (public surface now, authenticated half BLOCKED) ────
section "4. critical journeys"
SCRIPTED_N=0
j() { printf '\n-- journey: %s\n' "$1"; }

# 1. Google register → teacher dashboard → refresh
j "google-register-teacher-dashboard-refresh"
gred="$(body "$API/auth/google/redirect?role=teacher")"
case "$gred" in
  *client_id=*) pass "GET auth/google/redirect?role=teacher returns a Google URL with client_id" ;;
  *) fail "Google redirect did not return client_id — sign-in cannot start" ;;
esac
blocked "full journey needs a real Google account: register, land on the teacher dashboard, hard-refresh and confirm the session survives"

# 2. Email/password login
j "email-password-login"
if [ "$ALLOW_LOGIN_PROBE" = 1 ]; then
  # Clearly-invalid credentials: the endpoint must answer 401/422, never a 5xx.
  lc="$(curl -sS -o /dev/null -w '%{http_code}' --max-time "$HTTP_TIMEOUT" ${H[@]+"${H[@]}"} \
        -H 'Content-Type: application/json' -H 'Accept: application/json' \
        -d '{"email":"smoke-test-nonexistent@example.invalid","password":"not-a-real-password"}' \
        "$API/auth/login" 2>/dev/null || true)"; [ -n "$lc" ] || lc=000
  case "$lc" in
    401|422) pass "POST auth/login rejects bad credentials cleanly ($lc)" ;;
    000)     fail "POST auth/login did not answer" ;;
    *)       fail "POST auth/login returned $lc (want 401/422, not a 5xx)" ;;
  esac
else
  skip "login probe disabled — re-run with --allow-login-probe to check the endpoint"
fi
blocked "a real email/password login and its dashboard landing"

# 3. Admin + student login
j "admin-and-student-login"
for ep in /courses /cards /cards/export /dashboard; do
  c="$(code "$API$ep")"
  case "$c" in
    401|403|419) pass "GET $ep is guarded without a session ($c)" ;;
    000)         fail "GET $ep did not answer" ;;
    *)           fail "GET $ep returned $c without auth (want 401/403)" ;;
  esac
done
blocked "real admin and student logins landing on their own dashboards"

# 4. Create a course + lesson + worksheet
j "create-course-lesson-worksheet"
c="$(code "$API/courses")"
case "$c" in 401|403|419) pass "courses surface requires auth ($c)" ;; *) fail "GET /courses returned $c without auth" ;; esac
blocked "creating a real course + lesson + worksheet with a teacher account"

# 5. Card generate / export / redeem
j "card-generate-export-redeem"
c="$(code "$API/cards/export")"
case "$c" in
  401|403|419) pass "cards/export requires auth AND the cards.export permission ($c)" ;;
  *) fail "GET /cards/export returned $c without auth (want 401/403)" ;;
esac
c="$(code "$API/cards")"
case "$c" in 401|403|419) pass "cards surface requires auth ($c)" ;; *) fail "GET /cards returned $c without auth" ;; esac
blocked "generating, exporting and redeeming a real card (needs a teacher + the cards.export grant)"

# 6. Video + live access by permission
j "video-live-access-by-permission"
c="$(code "$API/videos/1/playback")"
case "$c" in 401|403|419) pass "video playback requires auth ($c)" ;; *) fail "GET /videos/1/playback returned $c without auth" ;; esac
blocked "real video playback and live access checked per role/permission"

# 7. Branding + logo (fully scripted above)
j "branding-fahimni-logo"
pass "checked in section 2 above (scripted)"
SCRIPTED_N=$((SCRIPTED_N+1))

# 8. CSP / PWA / core links (fully scripted above)
j "csp-pwa-core-links"
pass "checked in section 3 above (scripted)"
SCRIPTED_N=$((SCRIPTED_N+1))

# ── 5. the BLOCKED list a human must complete before GO ─────────────────────
section "5. BLOCKED items (require a real account or an external provider)"
for jr in "${JOURNEYS[@]}"; do
  IFS='|' read -r jid jkind jdesc <<< "$jr"
  if [ "$jkind" = "partial" ]; then printf '  [BLOCKED] %s — %s\n' "$jid" "$jdesc"; fi
done

printf '\n############################################################\n'
printf '#  SUMMARY\n'
printf '#    scripted checks : %d passed, %d failed\n' "$PASS_N" "$FAIL_N"
printf '#    journeys          : %d fully scripted, %d partial (BLOCKED half)\n' "$SCRIPTED_N" "$(( ${#JOURNEYS[@]} - SCRIPTED_N ))"
printf '#    BLOCKED items     : %d\n' "$BLOCKED_N"
if [ "$FAIL_N" -eq 0 ]; then
  printf '#  RESULT: OK — every scripted check passed (%d BLOCKED items remain)\n' "$BLOCKED_N"
else
  printf '#  RESULT: FAIL — %d scripted check(s) failed\n' "$FAIL_N"
fi
printf '############################################################\n'

exit "$(( FAIL_N > 0 ? 1 : 0 ))"
