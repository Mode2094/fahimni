#!/usr/bin/env bash
# =============================================================================
#  End-to-end (hermetic) tests for the single on-host GO-LIVE runner.
#
#  deploy/scripts/fahimni-golive.sh chains pre-flight → backup → restore drill →
#  deploy → verify. This suite runs the REAL runner against stubbed
#  docker/curl/rclone and a REAL temporary git repository, and pins the safety
#  contract:
#
#    * the deploy only runs AFTER a successful isolated restore;
#    * FAHIMNI_DB_BACKUP_VERIFIED=1 is set ONLY on the deploy invocation, and
#      only when a still-running drill proved a recent backup;
#    * a missing/stale backup triggers a fresh backup, and if the drill still
#      fails the run SAFE STOPS without deploying;
#    * a host script that does not match the approved SHA256 is never executed;
#    * a fresh backup is only taken when the newest off-site archive could not be
#      restored (no needless dumps);
#    * an UNSTABLE service (core health not 200) triggers the approved safe
#      rollback including an nginx reload;
#    * no forbidden command is present anywhere in the runner.
#
#  Exit: 0 = all assertions passed.
# =============================================================================
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd -P)"
RUNNER="$REPO_DIR/deploy/scripts/fahimni-golive.sh"
[ -f "$RUNNER" ] || { echo "missing: $RUNNER" >&2; exit 1; }

work="$(mktemp -d "${TMPDIR:-/tmp}/fahimni-golive-test.XXXXXX")"
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/state" "$work/stack/releases/oldrel" "$work/host"
export STUB_STATE="$work/state"
export STUB_LOG="$work/state/log"
: > "$STUB_LOG"

failures=0
pass(){ printf '  ok    %s\n' "$*"; }
fail(){ printf '  FAIL  %s\n' "$*" >&2; failures=$((failures + 1)); }
ok_if(){ if [ "$2" = "$3" ]; then pass "$1 ($3)"; else fail "$1 (want $3, got $2)"; fi; }

# ── stub `id` so the runner's root check passes off-host ────────────────────
cat > "$work/bin/id" <<'STUB'
#!/usr/bin/env bash
if [ "${1:-}" = "-u" ]; then echo 0; else /usr/bin/id "$@"; fi
STUB

# ── stub `sleep` so the health windows do not slow the suite down ────────────
cat > "$work/bin/sleep" <<'STUB'
#!/usr/bin/env bash
exit 0
STUB

# ── stub `docker` ───────────────────────────────────────────────────────────
cat > "$work/bin/docker" <<'STUB'
#!/usr/bin/env bash
printf 'docker %s\n' "$*" >> "${STUB_LOG:?}"
state="${STUB_STATE:?}"
case "$1" in
  compose)
    # `docker compose … exec -T nginx nginx -s reload` marks a reload.
    case "$*" in *"nginx -s reload"*) touch "$state/reloaded" ;; esac
    exit "${STUB_COMPOSE_RC:-0}" ;;
  ps)
    case "$*" in
      *name=looksloop*) echo "llcid"; exit 0 ;;
      *"-q"*)
        svc="$(printf '%s' "$*" | sed -n 's/.*name=\^fahimni-\([a-z]*\)-1\$.*/\1/p')"
        [ -n "$svc" ] && echo "cid$svc"
        exit 0 ;;
    esac
    echo "NAME  IMAGE  STATUS"; exit 0 ;;
  inspect)
    fmt="${3:-}"; cid="${4:-}"
    case "$fmt" in
      *RestartCount*)  echo "LLID|llimage|looksloop|0|2026-01-01T00:00:00Z|none"; exit 0 ;;
      *State.Status*)  echo "running"; exit 0 ;;
      *State.Health*)  echo "healthy"; exit 0 ;;
      *Config.Image*)  echo "fahimni-${cid#cid}:${STUB_NEW_TAG:?}"; exit 0 ;;
    esac
    echo "?"; exit 0 ;;
esac
exit 0
STUB

# ── stub `curl` ─────────────────────────────────────────────────────────────
cat > "$work/bin/curl" <<'STUB'
#!/usr/bin/env bash
state="${STUB_STATE:?}"
case "$*" in
  *'%{http_code}'*)
    case "${STUB_HTTP_MODE:-ok}" in
      ok)    echo "200" ;;
      fail)  echo "502" ;;
      stale) [ -f "$state/reloaded" ] && echo "200" || echo "502" ;;
      *)     echo "200" ;;
    esac
    exit 0 ;;
esac
case "$*" in
  *platform-settings*) printf '{"platform_name":"منصة فهمني"}' ;;
esac
exit 0
STUB

# ── stub `rclone` (only required to exist; the drill is stubbed too) ─────────
cat > "$work/bin/rclone" <<'STUB'
#!/usr/bin/env bash
exit 0
STUB

# ── stub `install` (the real one cannot chown to a non-existent user here) ───
cat > "$work/bin/install" <<'STUB'
#!/usr/bin/env bash
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

# ── a REAL git origin holding a pinned tree + the stubbed tooling ────────────
seed="$work/seed"; bare="$work/origin.git"
git init -q "$bare" --bare
git init -q "$seed"
git -C "$seed" config user.email test@example.test
git -C "$seed" config user.name test
git -C "$seed" checkout -q -b test-branch
mkdir -p "$seed/deploy/scripts"

# The host deploy script content (stub) — committed AND staged as the host copy.
cat > "$seed/deploy/scripts/fahimni-brand-rollout.sh" <<'STUB'
#!/usr/bin/env bash
printf 'brand-rollout allow_db=%s backup_verified=%s allow_perm=%s sha=%s branch=%s migrations=%s\n' \
  "${FAHIMNI_ALLOW_DB_WRITE:-}" "${FAHIMNI_DB_BACKUP_VERIFIED:-}" "${FAHIMNI_ALLOW_PERMISSION_WRITE:-}" \
  "${FAHIMNI_RELEASE_SHA:-}" "${FAHIMNI_RELEASE_BRANCH:-}" "${FAHIMNI_APPROVED_MIGRATIONS:-}" >> "${STUB_LOG:?}"
exit "${STUB_DEPLOY_RC:-0}"
STUB

cat > "$seed/deploy/scripts/backup-offsite.sh" <<'STUB'
#!/usr/bin/env bash
printf 'backup-offsite %s\n' "$*" >> "${STUB_LOG:?}"
exit "${STUB_BACKUP_RC:-0}"
STUB

cat > "$seed/deploy/scripts/restore-drill.sh" <<'STUB'
#!/usr/bin/env bash
printf 'restore-drill %s\n' "$*" >> "${STUB_LOG:?}"
[ -n "${BACKUP_ALLOW_UNENCRYPTED:-}" ] && printf 'drill-env BACKUP_ALLOW_UNENCRYPTED=%s\n' "$BACKUP_ALLOW_UNENCRYPTED" >> "${STUB_LOG:?}"
n=0; [ -f "${STUB_STATE:?}/drill_n" ] && n="$(cat "$STUB_STATE/drill_n")"
n=$((n + 1)); printf '%s' "$n" > "$STUB_STATE/drill_n"
if [ "$n" -le "${STUB_DRILL_FAIL_FIRST:-0}" ]; then
  echo "no db-* backup found at rclone remote 'stub:stub/production'"
  exit 1
fi
echo "backup freshness ok: 2h old (limit 26h)"
echo "restore drill: OK"
echo "migrations=120 users=7 tenants=3 size=42 MB"
exit 0
STUB

cat > "$seed/deploy/scripts/launch-smoke-test.sh" <<'STUB'
#!/usr/bin/env bash
printf 'launch-smoke %s\n' "$*" >> "${STUB_LOG:?}"
exit "${STUB_SMOKE_RC:-0}"
STUB

printf 'name: fahimni\nservices: {}\n' > "$seed/docker-compose.yml"
chmod +x "$seed/deploy/scripts/"*
git -C "$seed" add -A
git -C "$seed" commit -qm "pin"
RELEASE_SHA="$(git -C "$seed" rev-parse HEAD)"
STUB_SCRIPT_HASH="$(if command -v sha256sum >/dev/null 2>&1; then sha256sum "$seed/deploy/scripts/fahimni-brand-rollout.sh" | cut -d' ' -f1; else shasum -a 256 "$seed/deploy/scripts/fahimni-brand-rollout.sh" | cut -d' ' -f1; fi)"
git -C "$seed" remote add origin "$bare"
git -C "$seed" push -q origin test-branch
cp -p "$seed/deploy/scripts/fahimni-brand-rollout.sh" "$work/host/rollout.sh"

# ── the fake production stack the runner will inspect ───────────────────────
mkdir -p "$work/stack/backups" "$work/stack/releases/$RELEASE_SHA"
ln -sfn "$work/stack/releases/oldrel" "$work/stack/current"
ENVF="$work/etc/production.env"
mkdir -p "$work/etc"
printf 'IMAGE_TAG=prod-hotfix1\nAPP_ENV=production\n' > "$ENVF"

export FAHIMNI_STACK="$work/stack"
export FAHIMNI_ENV_FILE="$ENVF"
export FAHIMNI_HOST_SCRIPT="$work/host/rollout.sh"
export FAHIMNI_REPO_URL="$bare"
export FAHIMNI_NEW_TAG="prod-brand1"
export FAHIMNI_HOST_HEADER="fahimni.me"
export FAHIMNI_BASE_URL="http://127.0.0.1:8080"
export STUB_NEW_TAG="prod-brand1"

reset_state(){
  rm -rf "$STUB_STATE"; mkdir -p "$STUB_STATE"; : > "$STUB_LOG"
  unset STUB_COMPOSE_RC STUB_DEPLOY_RC STUB_BACKUP_RC STUB_DRILL_FAIL_FIRST STUB_SMOKE_RC \
        STUB_HTTP_MODE 2>/dev/null || true
  # a fresh env file + symlink for each scenario
  printf 'IMAGE_TAG=prod-hotfix1\nAPP_ENV=production\n' > "$ENVF"
  ln -sfn "$work/stack/releases/oldrel" "$work/stack/current"
  rm -rf "$work/stack/.golive-tools"
}

OUT=""; RC=0
run_runner(){
  if OUT="$(bash "$RUNNER" --release-sha "$RELEASE_SHA" --branch test-branch \
              --max-age-hours 26 --expect-script-sha256 "$STUB_SCRIPT_HASH" "$@" 2>&1)"; then
    RC=0
  else
    RC=$?
  fi
}
order(){ grep -n "$1" "$STUB_LOG" | head -1 | cut -d: -f1; }
count(){ grep -c "$1" "$STUB_LOG" 2>/dev/null || true; }
fhash(){ if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi; }
result_file(){ find "$work/stack/backups" -maxdepth 1 -name 'golive-*.result' -print 2>/dev/null | sort | tail -1; }

printf '\n== fahimni-golive runner tests ==\n'

# ── T0: syntax + structural invariants ──────────────────────────────────────
printf '\n-- T0 syntax and structural invariants\n'
if bash -n "$RUNNER"; then pass "the runner parses (bash -n)"; else fail "bash -n"; fi
if grep -q 'must run as root' "$RUNNER"; then pass "the runner requires root"; else fail "the runner does not require root"; fi
if grep -v '^[[:space:]]*#' "$RUNNER" | grep -qE 'migrate:fresh|migrate:rollback|migrate:reset|db:wipe|schema:drop|down -v'; then
  fail "the runner contains a destructive schema/volume command"
else
  pass "no migrate:fresh / rollback / db:wipe / down -v in executable lines"
fi
if grep -q '80dc3b51a4d4b03c1eb074feb5aa3b0afd8c9454' "$RUNNER" \
   && grep -q 'fix/launch-blockers-rc' "$RUNNER" \
   && grep -q '72ece1cb2fe9a021f120fc0e10a0f296452aad92ea3b5bc586b13404913fd6ba' "$RUNNER"; then
  pass "the pinned SHA, branch and approved script hash are the approved values"
else
  fail "the runner does not carry the approved pin/branch/script-hash defaults"
fi
if grep -qE '\-\-skip-backup|FAHIMNI_SKIP_BACKUP|NO_BACKUP' "$RUNNER"; then
  fail "the runner exposes a backup-bypass flag"
else
  pass "there is no backup-bypass flag"
fi
if grep -q 'FAHIMNI_DB_BACKUP_VERIFIED=1' "$RUNNER" \
   && grep -q "\[ \"\$DRILL_OK\" = 1 \] || die \"internal guard: refusing to deploy without a proven restore\"" "$RUNNER"; then
  pass "the deploy is guarded by DRILL_OK before FAHIMNI_DB_BACKUP_VERIFIED=1 is used"
else
  fail "the deploy is not guarded by a proven restore"
fi
if awk '/^verify_host_script\(\)/,/^}/' "$RUNNER" | grep -q 'must never be bypassed'; then
  pass "the host-script SHA256 gate refuses to run a different script"
else
  fail "the host-script SHA256 gate is missing"
fi
if grep -q -- '--backup-file' "$RUNNER" && grep -q 'run_drill_file' "$RUNNER" \
   && grep -q 'check_local_backup' "$RUNNER"; then
  pass "the runner supports the local --backup-file restore path"
else
  fail "the runner lacks the local --backup-file path"
fi
if grep -nE 'rm +-rf?' "$RUNNER" | grep -q 'LOCAL_BACKUP'; then
  fail "the runner may delete the operator's local backup"
else
  pass "the runner never deletes the operator's local backup"
fi

# ── T1: a recent, restorable backup → no needless dump; deploy runs gated ────
printf '\n-- T1 recent backup: drill first, no new dump, deploy gated on the drill\n'
reset_state
run_runner
ok_if "exit code" "$RC" "0"
d="$(order 'restore-drill')"; b="$(order 'brand-rollout')"
if [ -n "$d" ] && [ -n "$b" ] && [ "$d" -lt "$b" ]; then pass "restore-drill runs before the deploy"; else fail "the drill does not precede the deploy (d=$d b=$b)"; fi
ok_if "backup-offsite invocations" "$(count 'backup-offsite')" "0"
ok_if "restore-drill invocations" "$(count 'restore-drill')" "1"
if grep -q 'restore-drill --env production --from-remote --max-age-hours 26 --yes' "$STUB_LOG"; then
  pass "the drill is run with --from-remote --max-age-hours 26 --yes"
else
  fail "the drill flags are wrong"
fi
if grep -q 'drill-env BACKUP_ALLOW_UNENCRYPTED' "$STUB_LOG"; then
  fail "the OFF-SITE path relaxed the unencrypted guard"
else
  pass "the off-site path never relaxes the unencrypted guard"
fi
if grep -q 'allow_db=1 backup_verified=1 allow_perm=1' "$STUB_LOG"; then
  pass "the deploy ran with all three write gates"
else
  fail "the deploy did not run with the three gates"
fi
if grep -q "brand-rollout .*sha=$RELEASE_SHA branch=test-branch migrations=2026_11_16_000001_add_lesson_id_to_assignments.php" "$STUB_LOG"; then
  pass "the deploy was pinned to the exact SHA and the approved migration"
else
  fail "the deploy was not pinned to the SHA/migration"
fi
if grep -q '^status=LIVE$' "$(result_file)"; then pass "the result records LIVE"; else fail "the result does not record LIVE"; fi

# ── T2: a stale/missing backup → fresh dump then a successful drill → deploy ─
printf '\n-- T2 stale backup: a fresh dump is taken, then the drill must pass\n'
reset_state; export STUB_DRILL_FAIL_FIRST=1
run_runner
ok_if "exit code" "$RC" "0"
d1="$(grep -n 'restore-drill' "$STUB_LOG" | head -1 | cut -d: -f1)"
bk="$(order 'backup-offsite')"
d2="$(grep -n 'restore-drill' "$STUB_LOG" | sed -n '2p' | cut -d: -f1)"
b="$(order 'brand-rollout')"
if [ -n "$d1" ] && [ -n "$bk" ] && [ -n "$d2" ] && [ -n "$b" ] \
   && [ "$d1" -lt "$bk" ] && [ "$bk" -lt "$d2" ] && [ "$d2" -lt "$b" ]; then
  pass "order is drill → fresh backup → drill → deploy"
else
  fail "unexpected order (d1=$d1 bk=$bk d2=$d2 b=$b)"
fi
if grep -q 'backup_action=created a fresh backup' "$(result_file)"; then pass "the result records the fresh backup"; else fail "the result does not record the fresh backup"; fi

# ── T3: the fresh backup still fails to restore → SAFE STOP, no deploy ──────
printf '\n-- T3 backup that cannot be restored: SAFE STOP, the deploy never runs\n'
reset_state; export STUB_DRILL_FAIL_FIRST=99
run_runner
if [ "$RC" != 0 ]; then pass "the runner stops non-zero ($RC)"; else fail "the runner did not stop"; fi
ok_if "backup-offsite invocations" "$(count 'backup-offsite')" "1"
ok_if "deploy invocations" "$(count 'brand-rollout')" "0"
case "$OUT" in *"restore drill FAILED"*) pass "the stop message names the failed drill" ;; *) fail "the stop message does not name the failed drill" ;; esac

# ── T4: a host script that is not the approved one is never executed ────────
printf '\n-- T4 host script SHA256 mismatch: refused before anything runs\n'
reset_state
if OUT="$(bash "$RUNNER" --release-sha "$RELEASE_SHA" --branch test-branch \
            --expect-script-sha256 "0000000000000000000000000000000000000000000000000000000000000000" 2>&1)"; then RC=0; else RC=$?; fi
if [ "$RC" != 0 ]; then pass "the runner refuses the mismatched script ($RC)"; else fail "the runner ran a mismatched script"; fi
ok_if "deploy invocations" "$(count 'brand-rollout')" "0"
ok_if "backup-offsite invocations" "$(count 'backup-offsite')" "0"
case "$OUT" in *"does NOT match the approved SHA256"*) pass "the refusal names the hash gate" ;; *) fail "the refusal does not name the hash gate" ;; esac

# ── T5: already deployed → verify only, no backup, no deploy ────────────────
printf '\n-- T5 already on the new tag: no dump and no deploy, verification only\n'
reset_state
printf 'IMAGE_TAG=prod-brand1\nAPP_ENV=production\n' > "$ENVF"
run_runner
ok_if "exit code" "$RC" "0"
ok_if "restore-drill invocations" "$(count 'restore-drill')" "0"
ok_if "backup-offsite invocations" "$(count 'backup-offsite')" "0"
ok_if "deploy invocations" "$(count 'brand-rollout')" "0"
if grep -q '^status=LIVE$' "$(result_file)"; then pass "the result records LIVE"; else fail "the result does not record LIVE"; fi

# ── T6: --dry-run → gates only, never deploys ───────────────────────────────
printf '\n-- T6 --dry-run: pre-flight + backup + drill, then stop without deploying\n'
reset_state
run_runner --dry-run
ok_if "exit code" "$RC" "0"
ok_if "deploy invocations" "$(count 'brand-rollout')" "0"
ok_if "restore-drill invocations" "$(count 'restore-drill')" "1"
if grep -q '^status=DRY-RUN-OK$' "$(result_file)"; then pass "the result records DRY-RUN-OK"; else fail "the result does not record DRY-RUN-OK"; fi

# ── T7: an unstable service triggers the approved safe rollback ─────────────
printf '\n-- T7 unstable after the deploy: safe rollback incl. an nginx reload\n'
reset_state; export STUB_HTTP_MODE=stale
run_runner
ok_if "exit code" "$RC" "2"
if grep -qE 'docker compose --env-file .* up -d --no-deps app frontend scheduler horizon' "$STUB_LOG"; then
  pass "the previous release was brought back up"
else
  fail "the previous release was not brought back up"
fi
if grep -q 'exec -T nginx nginx -s reload' "$STUB_LOG"; then pass "nginx was reloaded during the rollback"; else fail "nginx was not reloaded during the rollback"; fi
if grep -q '^status=ROLLED-BACK$' "$(result_file)"; then pass "the result records ROLLED-BACK"; else fail "the result does not record ROLLED-BACK"; fi

# ── T8: a non-critical anomaly (failing smoke) does not roll back ───────────
printf '\n-- T8 healthy service but a failing smoke test: LIVE, attention needed\n'
reset_state; export STUB_SMOKE_RC=1
run_runner
ok_if "exit code" "$RC" "3"
if grep -q '^status=LIVE-WITH-ANOMALIES$' "$(result_file)"; then pass "the result records LIVE-WITH-ANOMALIES"; else fail "the result does not record LIVE-WITH-ANOMALIES"; fi
ok_if "deploy invocations" "$(count 'brand-rollout')" "1"

# ── T9: a failed deploy is never retried, and the runner rolls nothing back ──
printf '\n-- T9 a failing deploy: SAFE STOP, never retried automatically\n'
reset_state; export STUB_DEPLOY_RC=1
run_runner
if [ "$RC" != 0 ]; then pass "the runner stops non-zero ($RC)"; else fail "the runner did not stop"; fi
ok_if "deploy invocations" "$(count 'brand-rollout')" "1"
if grep -qE 'docker compose --env-file .* up -d --no-deps app frontend scheduler horizon' "$STUB_LOG"; then
  fail "the runner rolled back on its own after a deploy failure (the rollout already does)"
else
  pass "the runner does not double-roll-back after a deploy failure"
fi

# ── T10: the runner never touches LooksLoop, DNS, Caddy or DNS providers ────
printf '\n-- T10 no Caddy/DNS/Firewall/LooksLoop writes anywhere\n'
if grep -vE '^[[:space:]]*#' "$RUNNER" | grep -qiE 'caddy|cloudflare|ufw|iptables'; then
  fail "the runner mentions a Caddy/DNS/Firewall change"
else
  pass "no Caddy/DNS/Firewall writes in the runner"
fi
if grep -vE '^[[:space:]]*#' "$RUNNER" | grep -qE 'docker[^|]*[[:space:]](restart|stop|rm|kill)([[:space:]]|$)'; then
  fail "the runner uses a mutating docker verb (restart/stop/rm/kill)"
else
  pass "the runner uses no docker restart/stop/rm/kill"
fi
if grep -q 'LooksLoop baseline' "$RUNNER" && grep -q 'll_snapshot' "$RUNNER"; then
  pass "LooksLoop is snapshotted before and compared after"
else
  fail "LooksLoop is not protected by a before/after snapshot"
fi

# ── T11: --backup-file uses a LOCAL archive; no rclone, no fresh dump ────────
printf '\n-- T11 local --backup-file: isolated restore, no rclone, no new dump\n'
reset_state
localdb="$work/stack/backups/db-local.dump"
printf 'PGDMP-stub-custom-archive-body' > "$localdb"
before_hash="$(fhash "$localdb")"
run_runner --backup-file "$localdb"
ok_if "exit code" "$RC" "0"
ok_if "restore-drill invocations" "$(count 'restore-drill')" "1"
ok_if "backup-offsite invocations" "$(count 'backup-offsite')" "0"
if grep -q "restore-drill --env production --file $localdb --max-age-hours 26 --yes" "$STUB_LOG"; then
  pass "the drill ran against the LOCAL file (no --from-remote)"
else
  fail "the drill did not use --file (log: $(grep 'restore-drill' "$STUB_LOG" | tr '\n' '|'))"
fi
if grep -q 'drill-env BACKUP_ALLOW_UNENCRYPTED=1' "$STUB_LOG"; then
  pass "the plain .dump is acknowledged with BACKUP_ALLOW_UNENCRYPTED=1"
else
  fail "the local .dump was not acknowledged (the drill would refuse it)"
fi
if [ -f "$localdb" ]; then pass "the local backup file still exists (never deleted)"; else fail "the local backup file was DELETED"; fi
ok_if "local backup unchanged" "$(fhash "$localdb")" "$before_hash"
if grep -q 'backup_action=restored a verified LOCAL archive' "$(result_file)"; then pass "the result records the LOCAL archive"; else fail "the result does not record the LOCAL archive"; fi
if grep -q 'does NOT protect against losing the server' "$(result_file)"; then pass "the result notes the local backup is not off-site"; else fail "the result omits the off-site limitation note"; fi
ok_if "deploy invocations" "$(count 'brand-rollout')" "1"

# ── T12: a missing local backup file → SAFE STOP, no drill, no deploy ────────
printf '\n-- T12 local backup missing: SAFE STOP before anything runs\n'
reset_state
run_runner --backup-file "$work/stack/backups/does-not-exist.dump"
if [ "$RC" != 0 ]; then pass "the runner stops non-zero ($RC)"; else fail "the runner did not stop"; fi
ok_if "restore-drill invocations" "$(count 'restore-drill')" "0"
ok_if "deploy invocations" "$(count 'brand-rollout')" "0"

# ── T13: a local backup that fails to restore → SAFE STOP, no deploy ─────────
printf '\n-- T13 local backup drill fails: SAFE STOP, no fresh dump, no deploy\n'
reset_state; export STUB_DRILL_FAIL_FIRST=99
run_runner --backup-file "$localdb"
if [ "$RC" != 0 ]; then pass "the runner stops non-zero ($RC)"; else fail "the runner did not stop"; fi
ok_if "backup-offsite invocations" "$(count 'backup-offsite')" "0"
ok_if "deploy invocations" "$(count 'brand-rollout')" "0"
case "$OUT" in *"restore drill FAILED on the local backup"*) pass "the stop message names the failed local drill" ;; *) fail "the stop message does not name the local drill" ;; esac

printf '\n'
if [ "$failures" -eq 0 ]; then
  echo "✅ all fahimni-golive runner tests passed"
else
  echo "❌ $failures fahimni-golive runner test(s) failed" >&2
fi
exit "$(( failures > 0 ? 1 : 0 ))"
