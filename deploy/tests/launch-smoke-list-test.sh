#!/usr/bin/env bash
# =============================================================================
#  Tests for deploy/scripts/launch-smoke-test.sh.
#
#  The launch gate must cover exactly the critical journeys from the release
#  checklist, and it must never quietly drop a journey that needs a real account
#  (those must stay reported as BLOCKED). This is checked structurally and via
#  the script's own --list mode; it never contacts production.
#
#  Run: deploy/tests/launch-smoke-list-test.sh
# =============================================================================
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd -P)"
SCRIPT="$REPO_DIR/deploy/scripts/launch-smoke-test.sh"
[ -f "$SCRIPT" ] || { echo "missing: $SCRIPT" >&2; exit 1; }

failures=0
pass(){ printf '  ok    %s\n' "$*"; }
fail(){ printf '  FAIL  %s\n' "$*" >&2; failures=$((failures + 1)); }
has(){ if grep -qF -- "$2" "$1"; then pass "$3"; else fail "$3"; fi; }

printf '\n== launch-smoke-test list/coverage tests ==\n'

# ── T0: parses ──────────────────────────────────────────────────────────────
printf '\n-- T0 the smoke test parses\n'
if bash -n "$SCRIPT"; then pass "bash -n"; else fail "bash -n"; fi

# ── T1: --list prints every required journey exactly once ───────────────────
printf '\n-- T1 --list covers every critical journey\n'
listing="$(bash "$SCRIPT" --list)"
required=(
  google-register-teacher-dashboard-refresh
  email-password-login
  admin-and-student-login
  create-course-lesson-worksheet
  card-generate-export-redeem
  video-live-access-by-permission
  branding-fahimni-logo
  csp-pwa-core-links
)
for id in "${required[@]}"; do
  if printf '%s\n' "$listing" | grep -qF "$id"; then pass "journey listed: $id"; else fail "journey missing: $id"; fi
done
count="$(printf '%s\n' "$listing" | grep -c . || true)"
if [ "$count" = "${#required[@]}" ]; then pass "exactly ${#required[@]} journeys are listed"; else fail "expected ${#required[@]} journeys, got $count"; fi

# ── T2: journeys needing an account are marked PARTIAL (=> BLOCKED) ─────────
printf '\n-- T2 account/provider journeys are PARTIAL, not silently scripted\n'
for id in google-register-teacher-dashboard-refresh email-password-login \
          admin-and-student-login create-course-lesson-worksheet \
          card-generate-export-redeem video-live-access-by-permission; do
  kind="$(printf '%s\n' "$listing" | awk -F'\t' -v id="$id" '$1==id{print $2}')"
  if [ "$kind" = "partial" ]; then pass "$id is partial (reported BLOCKED)"; else fail "$id kind is '$kind' (want partial)"; fi
done

# ── T3: fully-scripted journeys are SCRIPTED ────────────────────────────────
printf '\n-- T3 the public journeys are fully scripted\n'
for id in branding-fahimni-logo csp-pwa-core-links; do
  kind="$(printf '%s\n' "$listing" | awk -F'\t' -v id="$id" '$1==id{print $2}')"
  if [ "$kind" = "scripted" ]; then pass "$id is scripted"; else fail "$id kind is '$kind' (want scripted)"; fi
done

# ── T4: the checks reference the real surfaces ───────────────────────────────
printf '\n-- T4 the script checks the real surfaces\n'
has "$SCRIPT" "platform-settings"      "checks the platform-settings API (branding)"
has "$SCRIPT" "manifest.webmanifest"   "checks the PWA manifest"
has "$SCRIPT" "nonce"                  "checks the CSP nonce policy"
has "$SCRIPT" "auth/google/redirect"   "checks the Google redirect endpoint"
has "$SCRIPT" "cards/export"           "checks the cards.export surface"
has "$SCRIPT" "frontend/public/logo.png" "verifies the served logo hash against the release"
has "$SCRIPT" "BLOCKED"                "prints an explicit BLOCKED marker"

# ── T5: it is read-only by default (no credential probe unless opted in) ────
printf '\n-- T5 safe by default\n'
has "$SCRIPT" "ALLOW_LOGIN_PROBE"      "gates the login probe behind an explicit flag"
has "$SCRIPT" "example.invalid"        "the login probe uses only clearly-invalid credentials"
if grep -qE " (-X|--request) (POST|PUT|DELETE|PATCH)" "$SCRIPT"; then
  # POST is only acceptable on the opt-in login probe.
  if grep -nE " (-X|--request) (POST|PUT|DELETE|PATCH)" "$SCRIPT" | grep -qv 'login'; then
    fail "the smoke test issues a non-login write request"
  else
    pass "the only write-shaped request is the opt-in login probe"
  fi
else
  pass "read-only: no explicit write requests"
fi

# ── T6: a dead target fails (exit non-zero) — proves it is a real gate ──────
printf '\n-- T6 a dead target fails the gate\n'
if out="$(bash "$SCRIPT" --base http://127.0.0.1:9 --api http://127.0.0.1:9/api/v1 2>&1)"; then
  fail "the smoke test passed against a dead target"
else
  pass "the smoke test fails against a dead target"
fi
case "$out" in *"RESULT: FAIL"*) pass "the failure is reported in the summary" ;; *) fail "no FAIL summary for a dead target" ;; esac

# ── T7: branding identity is decoded with a real JSON parser, not grepped ───
printf '\n-- T7 platform-settings identity is decoded, not grepped literally\n'
has "$SCRIPT" "platform_name_status" "uses the shared platform_name_status helper"
has "$SCRIPT" "command -v jq"        "can decode the settings body with jq"
has "$SCRIPT" "command -v python3"   "can decode the settings body with python3"
has "$SCRIPT" "command -v php"       "can decode the settings body with php"
has "$SCRIPT" "wrong:"               "separates a wrong name from the old brand"
if grep -qF "*'منصة فهمني'*" "$SCRIPT"; then
  fail "the branding check still greps the raw body for the literal Arabic name"
else
  pass "no literal-Arabic raw-body match remains in the branding check"
fi

printf '\n'
if [ "$failures" -eq 0 ]; then
  echo "✅ all launch-smoke test passed"
else
  echo "❌ $failures launch-smoke test(s) failed" >&2
fi
exit "$(( failures > 0 ? 1 : 0 ))"
