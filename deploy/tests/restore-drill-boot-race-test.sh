#!/usr/bin/env bash
# =============================================================================
#  Regression test for the restore-drill PostgreSQL boot race.
#
#  The official postgres image starts a TEMPORARY server during initdb, logs
#  "database system is ready to accept connections", stops it, and only then
#  starts the real server. `pg_isready` therefore succeeds during the temp
#  window, and a drill that trusts it alone runs pg_restore too early and dies:
#
#      pg_restore: error: could not connect ...
#      FATAL:  the database system is shutting down
#
#  This suite runs the REAL deploy/scripts/restore-drill.sh against a stubbed
#  docker and pins the contract:
#    * readiness is NOT `pg_isready` alone — the drill must wait for evidence
#      that initdb has finished (the entrypoint marker / the SECOND ready line)
#      AND confirm restore_drill answers a query before restoring;
#    * pg_restore is invoked only AFTER that evidence (never in the temp window);
#    * the archive is really piped into pg_restore and the post-restore sanity
#      queries run (a real restore, not just a connect);
#    * a container that exits during init, or one that never finishes init,
#      fails closed and never restores — no hang, no false pass.
#
#  Hermetic: `docker` and `sleep` are stubbed; nothing is pulled and production
#  is never touched.
# =============================================================================
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd -P)"
SCRIPT="$REPO_DIR/deploy/scripts/restore-drill.sh"
[ -f "$SCRIPT" ] || { echo "missing: $SCRIPT" >&2; exit 1; }

work="$(mktemp -d "${TMPDIR:-/tmp}/fahimni-drill-race.XXXXXX")"
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/state"
export STUB_STATE="$work/state"
export STUB_LOG="$work/state/log"
# The real PATH (without the stubs) is used by the optional real-Docker E2E.
ORIG_PATH="$PATH"

fails=0
ok()   { printf '  ok    %s\n' "$1"; }
bad()  { printf '  FAIL  %s\n' "$1" >&2; fails=$((fails + 1)); }
order(){ grep -nF -- "$1" "$STUB_LOG" 2>/dev/null | head -1 | cut -d: -f1; }

# ── stub `sleep`: each call advances the simulated clock ─────────────────────
cat > "$work/bin/sleep" <<'STUB'
#!/usr/bin/env bash
state="${STUB_STATE:?}"
n=0; [ -f "$state/tick" ] && n="$(cat "$state/tick")"
printf '%s' "$((n + 1))" > "$state/tick"
exit 0
STUB

# ── stub `docker`: models the temp-server / final-server boot sequence ───────
cat > "$work/bin/docker" <<'STUB'
#!/usr/bin/env bash
state="${STUB_STATE:?}"; log="${STUB_LOG:?}"
mode="${DRILL_STUB_MODE:-race}"
tick=0; [ -f "$state/tick" ] && tick="$(cat "$state/tick")"

case "$1" in
  run)
    printf '0' > "$state/tick"
    printf 'cid-drill\n'
    exit 0 ;;
  logs)
    # During the temp window only ONE ready line exists and there is no
    # init-complete marker. Once the final server is up both appear. In "never"
    # mode the final server never comes up.
    if [ "$mode" = "race" ] && [ "$tick" -ge 1 ]; then
      printf 'database system is ready to accept connections\n'
      printf 'PostgreSQL init process complete; ready for start up.\n'
      printf 'database system is ready to accept connections\n'
    else
      printf 'database system is ready to accept connections\n'
    fi
    exit 0 ;;
  inspect)
    if [ "$mode" = "exited" ]; then echo "false"; else echo "true"; fi
    exit 0 ;;
  exec)
    shift  # drop `exec`
    if [ "${1:-}" = "-i" ]; then shift; fi  # drop `-i` for pg_restore
    shift   # drop the container id
    case "$*" in
      *pg_isready*)
        # The trap: pg_isready is "ready" in BOTH windows.
        printf 'pg_isready @tick=%s\n' "$tick" >> "$log"
        exit 0 ;;
      *pg_restore*)
        body="$(cat)"
        magic="$(printf '%s' "$body" | head -c 5)"
        printf 'pg_restore @tick=%s magic=%s bytes=%s\n' "$tick" "$magic" "${#body}" >> "$log"
        exit 0 ;;
      *psql*)
        q="${*: -1}"
        printf 'psql %s @tick=%s\n' "$q" "$tick" >> "$log"
        case "$q" in
          *"FROM migrations"*) echo "120" ;;
          *"FROM users"*)      echo "7" ;;
          *"FROM tenants"*)    echo "3" ;;
          *pg_database_size*)  echo "42 MB" ;;
          *)                   echo "1" ;;
        esac
        exit 0 ;;
    esac
    exit 0 ;;
  rm) exit 0 ;;
esac
exit 0
STUB
chmod +x "$work/bin/"*
export PATH="$work/bin:$PATH"

envf="$work/production.env"
printf 'APP_ENV=production\n' > "$envf"
export POSTGRES_IMAGE="stub-postgres:1"

# A minimal but real PostgreSQL custom-format header (magic = PGDMP).
dump="$work/db-20261117T000000Z.dump"
printf 'PGDMP\000\001\000stub-custom-archive-body' > "$dump"

reset_stub(){ rm -rf "$STUB_STATE"; mkdir -p "$STUB_STATE"; : > "$STUB_LOG"; }
run_drill(){
  if OUT="$(bash "$SCRIPT" --env production --env-file "$envf" --file "$dump" \
              --allow-unencrypted --yes 2>&1)"; then RC=0; else RC=$?; fi
}
OUT=""; RC=0

printf '\n== restore-drill PostgreSQL boot-race tests ==\n'

# ── T1: the real drill waits out the temp window, then restores and verifies ─
printf '\n-- T1 the drill waits out the temp-server window, then restores\n'
reset_stub
export DRILL_STUB_MODE=race
run_drill
if [ "$RC" = 0 ]; then ok "the drill exits 0"; else bad "the drill exited $RC: $(printf '%s' "$OUT" | tr '\n' '|')"; fi
case "$OUT" in *"restore drill: OK"*) ok "the drill reports a successful restore" ;; *) bad "no 'restore drill: OK' in the output" ;; esac
case "$OUT" in *"restore_drill is accepting connections"*) ok "the log records the final server becoming ready" ;; *) bad "the log does not record the readiness wait" ;; esac

prest="$(order 'pg_restore')"
tick="$(grep 'pg_restore @tick=' "$STUB_LOG" 2>/dev/null | head -1 | sed 's/.*@tick=\([0-9]*\).*/\1/')"
if [ -n "$prest" ] && [ "${tick:-0}" -ge 1 ]; then
  ok "pg_restore ran only after the final server was up (tick=$tick, not the temp window)"
else
  bad "pg_restore ran in the temp-server window or never ran (tick=${tick:-none})"
fi
if grep -q 'pg_restore @tick=[0-9]* magic=PGDMP bytes=[1-9]' "$STUB_LOG"; then
  ok "the archive was really piped into pg_restore (PGDMP magic, non-empty)"
else
  bad "pg_restore did not receive a PGDMP archive: $(grep 'pg_restore' "$STUB_LOG" | tr '\n' '|')"
fi

probe="$(order 'psql SELECT 1')"
if [ -n "$probe" ] && [ -n "$prest" ] && [ "$probe" -lt "$prest" ]; then
  ok "restore_drill answered a SELECT 1 readiness probe BEFORE the restore"
else
  bad "no SELECT 1 readiness probe before pg_restore (probe=$probe restore=$prest)"
fi
mig="$(order 'psql SELECT count(*) FROM migrations')"
usr="$(order 'psql SELECT count(*) FROM users')"
if [ -n "$mig" ] && [ -n "$usr" ] && [ "$mig" -gt "$prest" ] && [ "$usr" -gt "$prest" ]; then
  ok "post-restore sanity queries ran against the restored database"
else
  bad "the post-restore sanity queries did not run after the restore (mig=$mig usr=$usr restore=$prest)"
fi
if grep -q 'FATAL' "$STUB_LOG"; then bad "a FATAL appeared in the drill log"; else ok "no FATAL/shutting-down error occurred"; fi

# ── T2: init never completes → fail closed, never restore, never hang ────────
printf '\n-- T2 init never completes: the drill fails closed without restoring\n'
reset_stub
export DRILL_STUB_MODE=never
run_drill
if [ "$RC" != 0 ]; then ok "the drill stops non-zero ($RC) instead of restoring too early"; else bad "the drill 'succeeded' without init ever completing"; fi
if [ -z "$(order 'pg_restore')" ]; then ok "pg_restore was never called while only the temp server was up"; else bad "pg_restore ran against a server that never finished init"; fi
case "$OUT" in *"did not finish initialising"*) ok "the failure message names the init wait" ;; *) bad "unexpected failure message: $(printf '%s' "$OUT" | tr '\n' '|')" ;; esac

# ── T3: the container dies during init → fail closed, no hang, no restore ────
printf '\n-- T3 the container exits during init: a clear stop, no restore\n'
reset_stub
export DRILL_STUB_MODE=exited
run_drill
if [ "$RC" != 0 ]; then ok "the drill stops non-zero ($RC)"; else bad "the drill did not stop on a dead container"; fi
if [ -z "$(order 'pg_restore')" ]; then ok "pg_restore was never called"; else bad "pg_restore ran against a dead container"; fi
case "$OUT" in *"exited before it finished initialising"*) ok "the failure names the early exit" ;; *) bad "no early-exit diagnosis: $(printf '%s' "$OUT" | tr '\n' '|')" ;; esac

# ── T4: structural — readiness is not pg_isready alone, and isolation holds ──
printf '\n-- T4 structural: init evidence is required; the restore stays isolated\n'
unset DRILL_STUB_MODE
ready_block="$(sed -n '/# Wait for the DISPOSABLE server/,/log "restoring archive"/p' "$SCRIPT")"
if [ -n "$ready_block" ]; then ok "the readiness wait is present"; else bad "the readiness wait block is missing"; fi
if printf '%s' "$ready_block" | grep -q 'PostgreSQL init process complete'; then
  ok "readiness requires the entrypoint's init-complete marker"
else
  bad "readiness does not key on the init-complete marker"
fi
if printf '%s' "$ready_block" | grep -q 'ready to accept connections'; then
  ok "readiness also accepts the second 'ready to accept connections' line"
else
  bad "readiness does not check for the final server's ready line"
fi
if printf '%s' "$ready_block" | grep -q "psql -U postgres -d restore_drill -tAc 'SELECT 1'"; then
  ok "readiness confirms restore_drill answers a query"
else
  bad "readiness does not probe restore_drill before restoring"
fi
if printf '%s' "$ready_block" | grep -q 'pg_isready'; then
  ok "pg_isready is still used (as one of several signals)"
else
  bad "pg_isready disappeared from the readiness wait"
fi
if grep -q 'did not become ready"' "$SCRIPT"; then
  bad "the old pg_isready-only readiness message is still present"
else
  ok "the pg_isready-only readiness gate is gone"
fi
if grep -q -- '--network none' "$SCRIPT"; then ok "the disposable container stays network-isolated (--network none)"; else bad "the drill no longer runs with --network none"; fi
if grep -q 'POSTGRES_HOST_AUTH_METHOD=trust' "$SCRIPT"; then ok "the disposable DB keeps trust auth (no production secrets)"; else bad "the drill lost the trust-auth setup"; fi
if grep -qiE 'docker (compose|exec).*fahimni-(app|db|postgres)' "$SCRIPT"; then
  bad "the drill appears to touch the production stack"
else
  ok "the drill never addresses the production containers"
fi

# ── T5: real end-to-end against a real PostgreSQL container (Docker) ─────────
#     The strongest proof: build a genuine custom-format dump with pg_dump, run
#     the REAL drill against the REAL pinned postgres image, and require the
#     restore to succeed. Skipped (never failed) when Docker or the image is not
#     available, so the hermetic suite above still gates every push.
printf '\n-- T5 real end-to-end restore against a real PostgreSQL container\n'
real_img="${POSTGRES_IMAGE_REAL:-postgres@sha256:cf78e76683b9ca8c5733cbbdce6c9262b45b6767934dd0a95e671f9a0fc20685}"
REAL_DOCKER="$(PATH="$ORIG_PATH" command -v docker || true)"
if [ -n "$REAL_DOCKER" ] && PATH="$ORIG_PATH" "$REAL_DOCKER" info >/dev/null 2>&1; then
  if PATH="$ORIG_PATH" "$REAL_DOCKER" pull "$real_img" >/dev/null 2>&1; then
    src="fahimni-e2e-src-$$"
    trap 'PATH="$ORIG_PATH" "$REAL_DOCKER" rm -f "$src" >/dev/null 2>&1 || true; rm -rf "$work"' EXIT
    PATH="$ORIG_PATH" "$REAL_DOCKER" run -d --rm --name "$src" \
      -e POSTGRES_HOST_AUTH_METHOD=trust -e POSTGRES_DB=source_db "$real_img" >/dev/null
    src_ready=0
    for _ in $(seq 1 120); do
      src_logs="$(PATH="$ORIG_PATH" "$REAL_DOCKER" logs "$src" 2>&1 || true)"
      case "$src_logs" in
        *'PostgreSQL init process complete'*)
          if PATH="$ORIG_PATH" "$REAL_DOCKER" exec "$src" pg_isready -U postgres >/dev/null 2>&1; then src_ready=1; break; fi ;;
      esac
      sleep 1
    done
    if [ "$src_ready" != 1 ]; then
      bad "the real source container never became ready"
    else
      cat > "$work/seed.sql" <<'SQL'
CREATE TABLE migrations(id integer);
CREATE TABLE users(id integer);
CREATE TABLE tenants(id uuid);
INSERT INTO migrations VALUES (1),(2),(3);
INSERT INTO users VALUES (1),(2),(3),(4);
INSERT INTO tenants VALUES ('00000000-0000-0000-0000-000000000001');
SQL
      PATH="$ORIG_PATH" "$REAL_DOCKER" exec -i "$src" psql -U postgres -d source_db -v ON_ERROR_STOP=1 -q < "$work/seed.sql" >/dev/null
      PATH="$ORIG_PATH" "$REAL_DOCKER" exec "$src" pg_dump -U postgres -d source_db -Fc > "$work/e2e.dump"
      if [ "$(head -c 5 "$work/e2e.dump")" = "PGDMP" ]; then
        ok "pg_dump produced a real custom-format archive ($(wc -c < "$work/e2e.dump" | tr -d ' ') bytes)"
      else
        bad "pg_dump did not produce a PGDMP archive"
      fi
      real_out=""; real_rc=0
      if real_out="$(PATH="$ORIG_PATH" POSTGRES_IMAGE="$real_img" bash "$SCRIPT" \
                      --env production --env-file "$envf" --file "$work/e2e.dump" \
                      --allow-unencrypted --yes 2>&1)"; then real_rc=0; else real_rc=$?; fi
      if [ "$real_rc" = 0 ]; then ok "the REAL drill restored a REAL dump into a real container (exit 0)"; else bad "the real drill failed (rc=$real_rc): $(printf '%s' "$real_out" | tr '\n' '|')"; fi
      case "$real_out" in *"restore drill: OK"*) ok "the real drill reported success" ;; *) bad "the real drill did not report success" ;; esac
      case "$real_out" in *"migrations=3"*) ok "the real restore carried the seeded rows (migrations=3)" ;; *) bad "the real restore did not report migrations=3: $(printf '%s' "$real_out" | tr '\n' '|' | tail -c 300)" ;; esac
    fi
    PATH="$ORIG_PATH" "$REAL_DOCKER" rm -f "$src" >/dev/null 2>&1 || true
  else
    printf '  skip  could not pull %s (offline)\n' "$real_img"
  fi
else
  printf '  skip  docker daemon not reachable on this host\n'
fi

printf '\n'
if [ "$fails" -eq 0 ]; then
  echo "✅ all restore-drill boot-race tests passed"
else
  echo "❌ $fails restore-drill boot-race test(s) failed" >&2
fi
exit "$(( fails > 0 ? 1 : 0 ))"
