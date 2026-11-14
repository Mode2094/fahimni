#!/usr/bin/env bash
# Prove docker/migrate.sh never hides a failure. Every step — migrate,
# plans:sync, the user-count probe and the seed — must abort the script with a
# non-zero exit, and the seed must run ONLY on a genuinely empty database.
#
# A stub `php` on PATH records each artisan call and returns whatever exit code
# (or probe output) the test selects, so no Laravel app or database is needed.
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
DEPLOY_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd -P)"
REPO_DIR="$(cd -- "$DEPLOY_DIR/.." && pwd -P)"
migrate="$REPO_DIR/docker/migrate.sh"
[[ -f "$migrate" ]] || { echo "missing: $migrate" >&2; exit 1; }

work="$(mktemp -d "${TMPDIR:-/tmp}/fahimni-migrate-test.XXXXXX")"
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin"

cat > "$work/bin/php" <<'STUB'
#!/bin/sh
# Args: artisan <command> [rest...]. Log the full call, then emulate the
# command's exit code (or the probe output) from the test's environment.
cmd="${2:-}"
printf '%s\n' "php $*" >> "${MIGRATE_LOG:?MIGRATE_LOG is unset}"
case "$cmd" in
  migrate)    exit "${MIGRATE_RC:-0}" ;;
  plans:sync) exit "${PLANS_RC:-0}" ;;
  tinker)     printf '%s' "${TINKER_OUT:-0}"; exit "${TINKER_RC:-0}" ;;
  db:seed)    exit "${SEED_RC:-0}" ;;
  *)          exit 0 ;;
esac
STUB
chmod +x "$work/bin/php"

fails=0
ran() { grep -Fq "php artisan $1" "$work/log"; }

ok()   { printf 'ok   %s\n' "$1"; }
bad()  { printf 'FAIL %s\n' "$1" >&2; fails=$((fails + 1)); }
expect_ran()     { if ran "$1"; then ok "$2"; else bad "$2"; fi; }
expect_not_ran() { if ran "$1"; then bad "$2"; else ok "$2"; fi; }

run_migrate() { # <expect: ok|fail> <label>
  local expect="$1" label="$2" rc=0
  : > "$work/log"
  MIGRATE_LOG="$work/log" PATH="$work/bin:$PATH" sh "$migrate" >/dev/null 2>&1 || rc=$?
  if [[ "$expect" == ok && "$rc" -ne 0 ]]; then
    bad "$label: exited $rc, expected success"; return
  fi
  if [[ "$expect" == fail && "$rc" -eq 0 ]]; then
    bad "$label: exited 0, expected failure"; return
  fi
  ok "$label (exit $rc)"
}

echo "== fresh database: migrate + plans:sync + seed all run =="
TINKER_OUT=0 run_migrate ok "fresh db completes"
expect_ran migrate        "migrate ran"
expect_ran 'plans:sync'   "plans:sync ran"
expect_ran tinker         "count probe ran"
expect_ran 'db:seed'      "seed ran"

echo "== existing database: seed is skipped =="
TINKER_OUT=5 run_migrate ok "existing db completes"
expect_ran migrate        "migrate ran"
expect_ran 'plans:sync'   "plans:sync ran"
expect_not_ran 'db:seed'  "seed was skipped"

echo "== failure propagation (no '|| true' swallow) =="
MIGRATE_RC=1 run_migrate fail "failing migrate aborts"
expect_not_ran 'plans:sync' "plans:sync did NOT run after a failed migrate"
expect_not_ran 'db:seed'    "seed did NOT run after a failed migrate"

PLANS_RC=1 run_migrate fail "failing plans:sync aborts"
expect_not_ran tinker       "probe did NOT run after a failed plans:sync"
expect_not_ran 'db:seed'    "seed did NOT run after a failed plans:sync"

TINKER_RC=1 TINKER_OUT=0 run_migrate fail "failing count probe aborts"
expect_not_ran 'db:seed'    "seed did NOT run after a failed probe"

TINKER_OUT=ERROR run_migrate fail "non-numeric count probe aborts"
expect_not_ran 'db:seed'    "seed did NOT run when the probe was not a number"

SEED_RC=1 TINKER_OUT=0 run_migrate fail "failing seed aborts the deploy"

echo
if [[ "$fails" -ne 0 ]]; then
  echo "migrate script: $fails check(s) FAILED" >&2
  exit 1
fi
echo "migrate script: OK"
