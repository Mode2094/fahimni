#!/usr/bin/env bash
# Prove the backup-freshness gate used by the restore drill: a drill that
# restores a STALE archive must fail closed, because that would not be evidence
# that a recent production backup exists (and must not justify
# FAHIMNI_DB_BACKUP_VERIFIED=1).
#
# Sources deploy/scripts/lib.sh and exercises backup_age_hours /
# assert_backup_fresh. assert_backup_fresh calls `die` (which exits), so it is
# only ever called inside a subshell.
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
DEPLOY_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd -P)"
REPO_DIR="$(cd -- "$DEPLOY_DIR/.." && pwd -P)"
SCRIPT="$DEPLOY_DIR/scripts/restore-drill.sh"
# shellcheck source=../scripts/lib.sh
source "$DEPLOY_DIR/scripts/lib.sh"

fails=0
ok()  { printf 'ok   %s\n' "$1"; }
bad() { printf 'FAIL %s\n' "$1" >&2; fails=$((fails + 1)); }

stamp() { date -u -v-"$1"H +%Y%m%dT%H%M%SZ 2>/dev/null || date -u -d "-$1 hours" +%Y%m%dT%H%M%SZ; }

printf '\n-- backup_age_hours parses the archive timestamp\n'
for h in 1 3 30 100; do
  name="db-$(stamp "$h").age"
  if got="$(backup_age_hours "$name")"; then
    # allow a one-hour boundary wobble between `date` calls
    if [[ "$got" =~ ^[0-9]+$ ]] && (( got >= h - 1 && got <= h + 1 )); then
      ok "backup_age_hours('$name') = ${got}h (expected ~${h}h)"
    else
      bad "backup_age_hours('$name') = ${got}h (expected ~${h}h)"
    fi
  else
    bad "backup_age_hours('$name') returned non-zero"
  fi
done

printf '\n-- a name without a timestamp fails closed (never guessed)\n'
for name in 'db-latest.dump' 'backup.dump' 'db-2026-11-16.dump' 'db-20261116T1513Z.dump'; do
  if backup_age_hours "$name" >/dev/null 2>&1; then
    bad "backup_age_hours('$name') accepted an unparseable name"
  else
    ok "backup_age_hours('$name') refused (fail closed)"
  fi
done

printf '\n-- a future-dated stamp is clamped to 0, not negative\n'
future="db-$(date -u -v+5H +%Y%m%dT%H%M%SZ 2>/dev/null || date -u -d '+5 hours' +%Y%m%dT%H%M%SZ).dump"
if got="$(backup_age_hours "$future")"; then
  if [[ "$got" == "0" ]]; then ok "future stamp clamped to 0h"; else bad "future stamp = ${got}h (expected 0)"; fi
else
  bad "future stamp was rejected instead of clamped"
fi

printf '\n-- assert_backup_fresh accepts a recent archive, refuses a stale one\n'
recent="db-$(stamp 2).dump"; stale="db-$(stamp 48).dump"
if ( assert_backup_fresh "$recent" 24 ) >/dev/null 2>&1; then
  ok "a 2h-old archive passes --max-age-hours 24"
else
  bad "a 2h-old archive was refused"
fi
if ( assert_backup_fresh "$stale" 24 ) >/dev/null 2>&1; then
  bad "a 48h-old archive passed --max-age-hours 24"
else
  ok "a 48h-old archive is refused (fail closed)"
fi
if ( assert_backup_fresh "$stale" 0 ) >/dev/null 2>&1; then
  ok "--max-age-hours 0 disables the check (default behaviour preserved)"
else
  bad "--max-age-hours 0 should disable the check"
fi
if ( assert_backup_fresh 'db-latest.dump' 24 ) >/dev/null 2>&1; then
  bad "an unparseable name passed a real --max-age-hours"
else
  ok "an unparseable name is refused when a limit is set"
fi
if ( assert_backup_fresh "$recent" '24h' ) >/dev/null 2>&1; then
  bad "a non-numeric --max-age-hours was accepted"
else
  ok "a non-numeric --max-age-hours is refused"
fi

printf '\n-- the drill wires the gate in BEFORE decrypting/restoring\n'
if grep -q -- '--max-age-hours' "$SCRIPT"; then
  ok "restore-drill.sh exposes --max-age-hours"
else
  bad "restore-drill.sh has no --max-age-hours option"
fi
if grep -q "assert_backup_fresh \"\$file\"" "$SCRIPT"; then
  ok "restore-drill.sh calls assert_backup_fresh on the chosen archive"
else
  bad "restore-drill.sh does not call assert_backup_fresh"
fi
gate="$(grep -n "assert_backup_fresh \"\$file\"" "$SCRIPT" | head -1 | cut -d: -f1)"
restore="$(grep -n 'pg_restore' "$SCRIPT" | head -1 | cut -d: -f1)"
if [[ -n "$gate" && -n "$restore" ]] && (( gate < restore )); then
  ok "the freshness gate (line $gate) runs before pg_restore (line $restore)"
else
  bad "the freshness gate does not precede pg_restore (gate=$gate restore=$restore)"
fi

printf '\n'
if (( fails == 0 )); then
  echo "✅ all backup-freshness tests passed"
else
  echo "❌ $fails backup-freshness test(s) failed" >&2
fi
exit "$(( fails > 0 ? 1 : 0 ))"
