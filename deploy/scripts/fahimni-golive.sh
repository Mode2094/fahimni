#!/usr/bin/env bash
# =============================================================================
#  Fahimni — ONE on-host GO-LIVE runner (root only).
#
#  Chains every step of the launch with a SAFE STOP at each gate and NO
#  automatic retry:
#
#    1. pre-flight  root/docker/git checks, host-script SHA256 gate, pinned-SHA
#                   reachability, and a read-only snapshot of everything that
#                   must NOT change (current release, containers, LooksLoop,
#                   disk, memory, loopback API health). The environment file is
#                   copied aside for a possible rollback.
#    2. backup      ensure a RECENT, COMPLETE PostgreSQL backup exists. With
#                   --backup-file PATH the operator-supplied LOCAL archive is
#                   used (no off-site/Google-Drive access needed); otherwise the
#                   newest off-site archive is restored first, and only when that
#                   is missing/stale/unreadable is a fresh one created with the
#                   approved tooling (backup-offsite.sh) — pg_dump only, traffic
#                   keeps running. A local file is only READ/COPIED, never
#                   deleted, and it does NOT protect against losing the server.
#    3. restore     restore the backup into an ISOLATED PostgreSQL
#                   (restore-drill.sh --network none) and require the freshness
#                   + `restore drill: OK` + row-count evidence BEFORE anything
#                   is allowed to write to the database.
#    4. deploy      run the approved host script ONCE, pinned to the exact SHA,
#                   with the DB-write and permission-write gates. The gate
#                   FAHIMNI_DB_BACKUP_VERIFIED=1 is passed ONLY after step 3
#                   proved a restore. The rollout itself re-checks the pending
#                   migrations against the allowlist and fails closed BEFORE the
#                   cutover, then reloads nginx and rolls back on any health or
#                   brand failure.
#    5. verify      containers on the new tag, loopback + public health, the new
#                   identity, the served logo hash, the post-migration pending
#                   set, the cards.export diff, launch-smoke-test.sh and a
#                   LooksLoop before/after comparison. If the service is
#                   UNSTABLE (core loopback health not 200) the approved safe
#                   rollback runs immediately, including an nginx reload.
#
#  It NEVER: deletes user data, runs migrate:fresh / migrate:rollback / down -v,
#  changes DNS / Caddy / Firewall / Cloudflare, touches LooksLoop or Staging,
#  enables Freemius or changes prices, or bypasses the backup gate.
#
#  Usage:
#    sudo bash /home/fahimni/fahimni-golive.sh               # full go-live
#    sudo bash /home/fahimni/fahimni-golive.sh --dry-run     # gates only, no deploy
#    sudo bash /home/fahimni/fahimni-golive.sh \
#         --backup-file /opt/fahimni/production/backups/db-<ts>.dump
#
#  Everything is logged to <stack>/backups/golive-<UTC>.log and summarised in
#  the matching .result file.
# =============================================================================
set -Eeuo pipefail

# ── pinned, approved release (never substitute a moving branch tip) ─────────
# dbe6965 is the current approved Release Candidate on
# release/launch-2026-11-rc: PR #63/#64/#65 integrated, plus the Super-Admin
# trial extension, per-teacher custom domains, and one deploy-script fix that
# keeps production.env shell-sourceable (APP_NAME is now quoted like
# MAIL_FROM_NAME). It changes NO migration relative to the previously deployed
# 68e150b, and it carries the approved a00a3eab… deploy/scripts/fahimni-brand-rollout.sh
# pinned below.
RELEASE_SHA="${FAHIMNI_RELEASE_SHA:-dbe6965ddb80e2eaed3f58ac601ca598abfba462}"
RELEASE_BRANCH="${FAHIMNI_RELEASE_BRANCH:-release/launch-2026-11-rc}"
APPROVED_SCRIPT_SHA256="${FAHIMNI_APPROVED_SCRIPT_SHA256:-a00a3eabaa11bef99b61c03958f9c49e3bdf1a8b1ddca7a4454ba8ebe66db869}"
APPROVED_MIGRATION="${FAHIMNI_APPROVED_MIGRATIONS:-2026_11_16_000001_add_lesson_id_to_assignments.php}"

HOST_SCRIPT="${FAHIMNI_HOST_SCRIPT:-/home/fahimni/fahimni-brand-rollout.sh}"
STACK="${FAHIMNI_STACK:-/opt/fahimni/production}"
ENVF="${FAHIMNI_ENV_FILE:-/etc/fahimni/production.env}"
REPO_URL="${FAHIMNI_REPO_URL:-https://github.com/Mode2094/fahimni.git}"
NEW_TAG="${FAHIMNI_NEW_TAG:-prod-brand1}"
MAX_AGE_HOURS="${FAHIMNI_BACKUP_MAX_AGE_HOURS:-26}"
# Optional LOCAL backup file. When set, the isolated restore drill uses THIS
# archive instead of the off-site rclone remote, so the temporary path does not
# require Google Drive. The file is only READ and COPIED — never deleted.
LOCAL_BACKUP="${FAHIMNI_BACKUP_FILE:-}"
HOSTHDR="${FAHIMNI_HOST_HEADER:-fahimni.me}"
HOME_URL="${FAHIMNI_BASE_URL:-https://fahimni.me}"
HTTP_TIMEOUT="${HTTP_TIMEOUT:-15}"
PORT="${APP_PORT:-8080}"
BASE_LOOP="http://127.0.0.1:$PORT"
SERVICES=(app frontend scheduler horizon)
NGINX_SVC=nginx
DRY_RUN=0

usage() {
  cat <<'EOF'
Usage: fahimni-golive.sh [options]

  --dry-run                 run pre-flight + backup + restore drill, then stop
                            without deploying
  --backup-file PATH        restore THIS local backup for the drill instead of
                            the off-site remote (no Google Drive needed; the file
                            is never deleted)
  --release-sha SHA         override the pinned release SHA
  --branch NAME             override the branch that must carry the SHA
  --max-age-hours N         backup freshness limit for the drill (default 26)
  --expect-script-sha256 H  override the approved host-script SHA256
  -h, --help                this help

Must run as root. Every gate fails closed; there is no bypass flag.
EOF
}

while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run)                DRY_RUN=1; shift ;;
    --backup-file)            LOCAL_BACKUP="${2:?}"; shift 2 ;;
    --release-sha)            RELEASE_SHA="${2:?}"; shift 2 ;;
    --branch)                 RELEASE_BRANCH="${2:?}"; shift 2 ;;
    --max-age-hours)          MAX_AGE_HOURS="${2:?}"; shift 2 ;;
    --expect-script-sha256)   APPROVED_SCRIPT_SHA256="${2:?}"; shift 2 ;;
    -h|--help)                usage; exit 0 ;;
    *)                        printf 'unknown argument: %s\n' "$1" >&2; exit 2 ;;
  esac
done

TOOLS="$STACK/.golive-tools/$RELEASE_SHA"
REL="$STACK/releases/$RELEASE_SHA"
BAKDIR="$STACK/backups"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
LOG="$BAKDIR/golive-$TS.log"
RESULT="$BAKDIR/golive-$TS.result"
ENV_SNAPSHOT="$BAKDIR/golive-env-$TS.bak"

mkdir -p "$BAKDIR"
: > "$LOG"
chmod 600 "$LOG"

# state filled in as the run progresses
CURRENT_REL=""
OLD_TAG=""
LL_BEFORE=""
LL_ABSENT=0
DRILL_OK=0
DRILL_EVIDENCE=""
BACKUP_ACTION="none"
BACKUP_NOTE=""
DEPLOYED=0
SMOKE_RESULT="not-run"

log(){     printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*" | tee -a "$LOG"; }
section(){ printf '\n===== %s =====\n' "$*" | tee -a "$LOG"; }
warn(){    printf '[%s] WARN: %s\n' "$(date -u +%H:%M:%S)" "$*" | tee -a "$LOG" >&2; }
die(){
  printf '\n!! SAFE STOP: %s\n' "$*" | tee -a "$LOG" >&2
  printf '   No automatic retry. Production was not modified beyond what is stated above.\n' | tee -a "$LOG" >&2
  printf '   Full log: %s\n' "$LOG" | tee -a "$LOG" >&2
  exit 1
}

require_cmd(){ command -v "$1" >/dev/null 2>&1 || die "required command not found: $1"; }
sha256_file(){ if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi; }
sha256_stdin(){ if command -v sha256sum >/dev/null 2>&1; then sha256sum | cut -d' ' -f1; else shasum -a 256 | cut -d' ' -f1; fi; }
# loopback/public HTTP code; the Host header pins the virtual host.
code(){ curl -sS -o /dev/null -w '%{http_code}' --max-time "$HTTP_TIMEOUT" -H "Host: $HOSTHDR" "$@" 2>/dev/null || printf '000'; }
# platform-settings returns {"settings":{"platform_name":"…"}} and the value is
# very often JSON-escaped (\u0645\u0646\u0635\u0629…). Grepping the raw body for
# the Arabic text therefore reports a FALSE failure and triggers a bogus
# rollback, so decode with a real JSON parser (jq → python3 → php) and compare
# the DECODED value. Prints ONE verdict token and returns its status:
#   ok         (rc 0)  the decoded name is exactly «منصة فهمني»
#   noparser   (rc 2)  no JSON parser is installed
#   invalid    (rc 1)  the body is not valid JSON, or has no string field
#   empty      (rc 1)  the decoded name is empty
#   legacy     (rc 1)  the decoded name is an old brand value
#   wrong:<n>  (rc 1)  any other value
platform_name_status(){  # $1 = JSON body
  local body="$1" name="" rc=0
  if command -v jq >/dev/null 2>&1; then
    name="$(jq -er '.settings.platform_name | strings' <<<"$body" 2>/dev/null)" || rc=1
  elif command -v python3 >/dev/null 2>&1; then
    name="$(python3 -c '
import json, sys
try:
    doc = json.load(sys.stdin)
except Exception:
    sys.exit(1)
node = doc.get("settings") if isinstance(doc, dict) else None
value = node.get("platform_name") if isinstance(node, dict) else None
if not isinstance(value, str):
    sys.exit(1)
sys.stdout.write(value)
' <<<"$body" 2>/dev/null)" || rc=1
  elif command -v php >/dev/null 2>&1; then
    # shellcheck disable=SC2016
    name="$(php -r '$d=json_decode(stream_get_contents(STDIN),true); $v=(is_array($d)&&isset($d["settings"])&&is_array($d["settings"]))?($d["settings"]["platform_name"]??null):null; if(!is_string($v))exit(1); echo $v;' <<<"$body" 2>/dev/null)" || rc=1
  else
    printf 'noparser\n'; return 2
  fi
  [ "$rc" = 0 ] || { printf 'invalid\n'; return 1; }
  [ -n "$name" ] || { printf 'empty\n'; return 1; }
  if [ "$name" = 'منصة فهمني' ]; then printf 'ok\n'; return 0; fi
  case "$name" in
    *Tadreesly*|*tadreesly*|*تدريسلي*|*تدريسلى*) printf 'legacy\n'; return 1 ;;
  esac
  printf 'wrong:%s\n' "$name"
  return 1
}

# The LooksLoop signature the production tooling uses: only fields that move on
# a REAL event (no volatile uptime text).
ll_snapshot(){
  docker ps -a --filter name=looksloop --format '{{.ID}}' 2>/dev/null \
    | while read -r c; do
        docker inspect -f '{{.Id}}|{{.Image}}|{{.Name}}|{{.RestartCount}}|{{.State.StartedAt}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$c" 2>/dev/null
      done | sort
}

write_result(){
  local status="$1"
  {
    printf 'timestamp=%s\n' "$TS"
    printf 'status=%s\n' "$status"
    printf 'release_sha=%s\n' "$RELEASE_SHA"
    printf 'release_branch=%s\n' "$RELEASE_BRANCH"
    printf 'host_script=%s\n' "$HOST_SCRIPT"
    printf 'host_script_sha256=%s\n' "$APPROVED_SCRIPT_SHA256"
    printf 'previous_release=%s\n' "${CURRENT_REL:-none}"
    printf 'previous_tag=%s\n' "${OLD_TAG:-none}"
    printf 'backup_action=%s\n' "$BACKUP_ACTION"
    printf 'backup_file=%s\n' "${LOCAL_BACKUP:-<off-site remote>}"
    printf 'backup_note=%s\n' "${BACKUP_NOTE:-off-site archive}"
    printf 'restore_drill=%s\n' "${DRILL_EVIDENCE:-not-run}"
    printf 'deployed_sha=%s\n' "$([ "$DEPLOYED" = 1 ] && printf '%s' "$RELEASE_SHA" || printf 'none')"
    printf 'smoke=%s\n' "$SMOKE_RESULT"
    printf 'log=%s\n' "$LOG"
  } > "$RESULT"
  chmod 600 "$RESULT"
  chown fahimni:fahimni "$RESULT" "$LOG" 2>/dev/null || true
}

# ── 1. pre-flight ───────────────────────────────────────────────────────────
preflight_host(){
  [ "$(id -u)" -eq 0 ] || die "this runner must run as root (use sudo)"
  require_cmd docker
  docker compose version >/dev/null 2>&1 || die "docker compose plugin not found"
  require_cmd curl
  require_cmd git
  if [ -z "$LOCAL_BACKUP" ]; then
    require_cmd rclone
  else
    log "runner: LOCAL backup mode — off-site rclone is NOT required"
  fi
  [ -f "$ENVF" ] || die "missing environment file: $ENVF"
  log "runner: pinned SHA=$RELEASE_SHA branch=$RELEASE_BRANCH"
  log "runner: host script=$HOST_SCRIPT"
}

ensure_tools(){
  section "pre-flight: materialise the pinned release for tooling"
  local parent
  parent="$(dirname "$TOOLS")"
  mkdir -p "$parent"
  if [ -d "$TOOLS/.git" ] && [ "$(git -C "$TOOLS" rev-parse HEAD 2>/dev/null || true)" = "$RELEASE_SHA" ]; then
    log "tooling checkout already at $RELEASE_SHA"
  else
    rm -rf "$TOOLS"
    git clone --branch "$RELEASE_BRANCH" --single-branch --quiet "$REPO_URL" "$TOOLS" \
      || die "git clone of $RELEASE_BRANCH failed"
  fi
  # git must trust the tree regardless of its owner (it is chowned to fahimni).
  export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0='*'
  git -C "$TOOLS" fetch --quiet origin "+refs/heads/$RELEASE_BRANCH:refs/remotes/origin/$RELEASE_BRANCH" 2>/dev/null \
    || die "git fetch of $RELEASE_BRANCH failed"
  git -C "$TOOLS" merge-base --is-ancestor "$RELEASE_SHA" "refs/remotes/origin/$RELEASE_BRANCH" \
    || die "$RELEASE_SHA is not reachable from branch $RELEASE_BRANCH — refusing to deploy a different tree"
  git -C "$TOOLS" checkout --detach --quiet "$RELEASE_SHA" || die "could not check out $RELEASE_SHA"
  [ "$(git -C "$TOOLS" rev-parse HEAD)" = "$RELEASE_SHA" ] || die "tooling HEAD is not $RELEASE_SHA"
  [ -f "$TOOLS/docker-compose.yml" ] || die "the pinned tree has no docker-compose.yml"
  ln -sfn "$ENVF" "$TOOLS/.env"
  chown -R fahimni:fahimni "$TOOLS" 2>/dev/null || true
  chown fahimni:fahimni "$parent" 2>/dev/null || true
  log "tooling ready: $TOOLS"
}

verify_host_script(){
  section "pre-flight: the host deploy script must byte-match the approved one"
  [ -f "$HOST_SCRIPT" ] || die "missing host deploy script: $HOST_SCRIPT"
  local got gitblob
  got="$(sha256_file "$HOST_SCRIPT")"
  gitblob="$(git -C "$TOOLS" show "$RELEASE_SHA:deploy/scripts/fahimni-brand-rollout.sh" | sha256_stdin)"
  log "host copy      : $got ($(stat -c '%s bytes mode %a' "$HOST_SCRIPT" 2>/dev/null || printf '?') )"
  log "approved hash  : $APPROVED_SCRIPT_SHA256"
  log "pinned-in-git  : $gitblob"
  [ "$got" = "$APPROVED_SCRIPT_SHA256" ] \
    || die "the host script does NOT match the approved SHA256 — refusing to run it (this check must never be bypassed)"
  [ "$gitblob" = "$APPROVED_SCRIPT_SHA256" ] \
    || die "the pinned commit's deploy script differs from the approved hash — refusing"
  if cmp -s "$HOST_SCRIPT" <(git -C "$TOOLS" show "$RELEASE_SHA:deploy/scripts/fahimni-brand-rollout.sh"); then
    log "host script == the pinned-commit copy (byte-identical)"
  else
    die "the host script hashes match but its bytes differ from the pinned commit — refusing"
  fi
}

snapshot_state(){
  section "pre-flight: state BEFORE any change (read-only)"
  CURRENT_REL="$(readlink -f "$STACK/current" 2>/dev/null || true)"
  [ -n "$CURRENT_REL" ] && [ -d "$CURRENT_REL" ] || die "could not resolve the current release from $STACK/current"
  OLD_TAG="$(grep -E '^IMAGE_TAG=' "$ENVF" | head -1 | cut -d= -f2- | tr -d '[:space:]' || true)"
  [ -n "$OLD_TAG" ] || die "could not read IMAGE_TAG from $ENVF"
  DEPLOY_NEEDED=1
  [ "$OLD_TAG" != "$NEW_TAG" ] || DEPLOY_NEEDED=0
  log "current release : $CURRENT_REL"
  log "current tag     : $OLD_TAG   (new tag: $NEW_TAG)"
  log "deploy needed   : $DEPLOY_NEEDED"
  log "disk            : $(df -h / | awk 'NR==2{print $4" free ("$5" used)"}')"
  log "memory          : $(awk '/MemAvailable/{printf "%d MiB available of %d MiB", $2/1024, $1/1024}' /proc/meminfo)"
  log "fahimni containers:"
  docker ps --format '  {{.Names}}  {{.Image}}  {{.Status}}' --filter 'name=fahimni' 2>/dev/null | tee -a "$LOG" || true
  local p
  for p in /up /api/health/live /api/health/ready; do
    log "  health(loopback) $p -> $(code "$BASE_LOOP$p")"
  done
  LL_BEFORE="$(ll_snapshot)"
  if [ -n "$LL_BEFORE" ]; then
    log "LooksLoop baseline: $(printf '%s\n' "$LL_BEFORE" | grep -c .) container(s)"
  else
    # This host is the standalone Fahimni VPS: no LooksLoop containers exist,
    # so there is nothing to protect and the before/after comparison degrades
    # to a no-op. Absence is recorded (not assumed) so a REAL disappearance
    # after the deploy would still be visible as a diff.
    LL_ABSENT=1
    log "LooksLoop baseline: none on this host (standalone Fahimni — nothing to protect)"
  fi
  cp -p "$ENVF" "$ENV_SNAPSHOT"
  chmod 600 "$ENV_SNAPSHOT"; chown fahimni:fahimni "$ENV_SNAPSHOT" 2>/dev/null || true
  log "env snapshot    : $ENV_SNAPSHOT"
}

# ── 2 + 3. backup and isolated restore drill ─────────────────────────────────
make_backup(){
  section "creating a fresh off-site backup (backup-offsite.sh --env production)"
  local rc=0
  if "$TOOLS/deploy/scripts/backup-offsite.sh" --env production 2>&1 | tee -a "$LOG"; then rc=0; else rc=$?; fi
  return "$rc"
}

run_drill(){
  local out rc=0
  if out="$("$TOOLS/deploy/scripts/restore-drill.sh" --env production --from-remote \
              --max-age-hours "$MAX_AGE_HOURS" --yes 2>&1)"; then rc=0; else rc=$?; fi
  printf '%s\n' "$out" | tee -a "$LOG"
  [ "$rc" = 0 ] || return 1
  drill_evidence "$out"
}

# The evidence gate behind FAHIMNI_DB_BACKUP_VERIFIED=1: a backup is never
# blessed without a REAL restore. Sets DRILL_EVIDENCE.
drill_evidence(){  # $1 = restore-drill output ; 0 = proven
  local out="$1"
  case "$out" in *"backup freshness ok"*) : ;; *) warn "the drill gave no freshness evidence"; return 1 ;; esac
  case "$out" in *"restore drill: OK"*) : ;; *) warn "the drill gave no 'restore drill: OK' evidence"; return 1 ;; esac
  case "$out" in *"migrations="*) : ;; *) warn "the drill gave no row-count evidence"; return 1 ;; esac
  DRILL_EVIDENCE="$(printf '%s\n' "$out" | grep -E 'backup freshness ok|restore drill: OK|migrations=' | tr '\n' ';' | sed 's/;*$//')"
  return 0
}

# Integrity pre-check for an operator-supplied LOCAL archive: it must exist, be
# readable and non-empty, and look like a PostgreSQL custom archive (or a gzip
# of one). The real proof is still the isolated restore below.
check_local_backup(){  # $1 = path ; 0 = plausible
  local f="$1" magic gz=0 size
  [ -f "$f" ] || { warn "local backup file not found: $f"; return 1; }
  [ -r "$f" ] || { warn "local backup file is not readable: $f"; return 1; }
  [ -s "$f" ] || { warn "local backup file is empty: $f"; return 1; }
  size="$(du -h "$f" 2>/dev/null | cut -f1)"
  magic="$(head -c 5 "$f" 2>/dev/null || true)"
  [ "$(head -c 2 "$f" 2>/dev/null || true)" = $'\x1f\x8b' ] && gz=1
  if [ "$gz" = 1 ]; then
    log "local backup: $(basename "$f") (${size:-?}, gzip) — the drill decompresses it"
  elif [ "$magic" = "PGDMP" ]; then
    log "local backup: $(basename "$f") (${size:-?}, PostgreSQL custom archive)"
  else
    warn "local backup is neither a PostgreSQL custom archive (PGDMP) nor gzip: $f"
    return 1
  fi
  return 0
}

# Restore a specific LOCAL archive into the same isolated PostgreSQL. A plain
# .dump is unencrypted, so the acknowledgement is passed as an explicit CLI flag
# (--allow-unencrypted) scoped to THIS --file invocation. It is deliberately NOT
# exported as BACKUP_ALLOW_UNENCRYPTED: restore-drill.sh sources the env file
# after arg parsing, and production.env carries BACKUP_ALLOW_UNENCRYPTED=0,
# which would clobber an exported value and make the drill refuse the file.
run_drill_file(){  # $1 = local backup file
  local f="$1" out rc=0 args=()
  case "$f" in *.dump) args+=(--allow-unencrypted) ;; esac
  if out="$("$TOOLS/deploy/scripts/restore-drill.sh" --env production \
              --file "$f" --max-age-hours "$MAX_AGE_HOURS" \
              ${args[@]+"${args[@]}"} --yes 2>&1)"; then rc=0; else rc=$?; fi
  printf '%s\n' "$out" | tee -a "$LOG"
  [ "$rc" = 0 ] || return 1
  drill_evidence "$out"
}

ensure_backup_and_drill(){
  section "backup + restore drill (MANDATORY before any database write)"
  if [ -n "$LOCAL_BACKUP" ]; then
    log "LOCAL backup mode: $LOCAL_BACKUP"
    log "no off-site (Google Drive) access is required for this temporary path"
    log "the archive is READ and COPIED into a disposable container — it is NEVER deleted or modified"
    check_local_backup "$LOCAL_BACKUP" \
      || die "the local backup failed its integrity pre-check — refusing to continue"
    run_drill_file "$LOCAL_BACKUP" \
      || die "the restore drill FAILED on the local backup — refusing to set FAHIMNI_DB_BACKUP_VERIFIED=1 (no bypass exists)"
    BACKUP_ACTION="restored a verified LOCAL archive: $(basename "$LOCAL_BACKUP")"
    BACKUP_NOTE="local file on THIS server — it does NOT protect against losing the server; keep an off-site copy too"
    warn "the local backup lives on the same server; it does NOT protect against losing the server"
    DRILL_OK=1
    return 0
  fi
  log "attempting to restore the newest off-site backup into an ISOLATED PostgreSQL"
  if run_drill; then
    BACKUP_ACTION="reused existing recent archive"
    BACKUP_NOTE="off-site archive"
    log "an existing recent backup restored cleanly — no new backup needed"
    DRILL_OK=1
    return 0
  fi
  warn "no recent/complete off-site backup could be restored — creating a fresh one now"
  make_backup || die "creating a PostgreSQL backup failed — refusing to continue without a backup (this gate cannot be bypassed)"
  BACKUP_ACTION="created a fresh backup"
  BACKUP_NOTE="off-site archive (freshly created)"
  run_drill || die "the restore drill FAILED on the freshly created backup — refusing to set FAHIMNI_DB_BACKUP_VERIFIED=1"
  DRILL_OK=1
}

# ── 4. deploy ───────────────────────────────────────────────────────────────
run_deploy(){
  section "deploy (ONCE): pinning $RELEASE_SHA on $RELEASE_BRANCH"
  [ "$DRILL_OK" = 1 ] || die "internal guard: refusing to deploy without a proven restore"
  log "host script : $HOST_SCRIPT  (approved sha256 verified)"
  log "gates       : FAHIMNI_ALLOW_DB_WRITE=1 FAHIMNI_DB_BACKUP_VERIFIED=1 FAHIMNI_ALLOW_PERMISSION_WRITE=1"
  log "migration   : $APPROVED_MIGRATION"
  local rc=0
  if env COMPOSE_PROJECT_NAME=fahimni \
        FAHIMNI_RELEASE_BRANCH="$RELEASE_BRANCH" \
        FAHIMNI_RELEASE_SHA="$RELEASE_SHA" \
        FAHIMNI_ALLOW_DB_WRITE=1 \
        FAHIMNI_DB_BACKUP_VERIFIED=1 \
        FAHIMNI_ALLOW_PERMISSION_WRITE=1 \
        FAHIMNI_APPROVED_MIGRATIONS="$APPROVED_MIGRATION" \
        bash "$HOST_SCRIPT" 2>&1 | tee -a "$LOG"; then rc=0; else rc=$?; fi
  if [ "$rc" != 0 ]; then
    die "the deploy exited $rc. It is NOT retried automatically. The rollout rolls itself back on health/brand failures — inspect the log above."
  fi
  DEPLOYED=1
  log "the rollout reported success"
}

# ── 5. verification ─────────────────────────────────────────────────────────
verify_containers(){
  local s cid state health img rc=0
  for s in "${SERVICES[@]}" "$NGINX_SVC"; do
    cid="$(docker ps -q --filter "name=^fahimni-$s-1\$" 2>/dev/null | head -1)"
    if [ -z "$cid" ]; then warn "fahimni-$s-1 is NOT running"; rc=1; continue; fi
    state="$(docker inspect -f '{{.State.Status}}' "$cid" 2>/dev/null || printf '?')"
    health="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}-{{end}}' "$cid" 2>/dev/null || printf '-')"
    img="$(docker inspect -f '{{.Config.Image}}' "$cid" 2>/dev/null || printf '?')"
    log "  fahimni-$s-1  state=$state health=$health image=$img"
    if [ "$state" != "running" ]; then warn "fahimni-$s-1 is '$state'"; rc=1; fi
    if [ "$health" = "unhealthy" ]; then warn "fahimni-$s-1 is unhealthy"; rc=1; fi
    if [ "$s" != "$NGINX_SVC" ] && [ "$img" != "fahimni-$s:$NEW_TAG" ]; then
      warn "fahimni-$s-1 runs '$img' (expected fahimni-$s:$NEW_TAG)"; rc=1
    fi
  done
  return "$rc"
}

wait_core_health(){
  local _
  for _ in $(seq 1 6); do
    if [ "$(code "$BASE_LOOP/up")" = 200 ] && [ "$(code "$BASE_LOOP/api/health/live")" = 200 ] \
       && [ "$(code "$BASE_LOOP/api/health/ready")" = 200 ] && [ "$(code "$BASE_LOOP/")" = 200 ]; then
      return 0
    fi
    sleep 5
  done
  return 1
}

safe_rollback(){
  section "SAFE ROLLBACK: reverting to the previous release (approved sequence)"
  if [ -f "$ENV_SNAPSHOT" ]; then
    install -m 600 -o fahimni -g fahimni "$ENV_SNAPSHOT" "$ENVF" || warn "could not restore $ENVF"
  fi
  if [ -n "$CURRENT_REL" ] && [ -d "$CURRENT_REL" ] && [ -n "$OLD_TAG" ]; then
    ( cd "$CURRENT_REL" && IMAGE_TAG="$OLD_TAG" docker compose --env-file "$ENVF" \
        up -d --no-deps "${SERVICES[@]}" ) || warn "rollback 'up' failed"
    if ( cd "$CURRENT_REL" && docker compose --env-file "$ENVF" exec -T "$NGINX_SVC" nginx -t ) >/dev/null 2>&1 \
       && ( cd "$CURRENT_REL" && docker compose --env-file "$ENVF" exec -T "$NGINX_SVC" nginx -s reload ) >/dev/null 2>&1; then
      log "nginx reloaded for the restored release"
    else
      warn "could not reload nginx during the rollback — the site may answer 502"
    fi
    ln -sfn "$CURRENT_REL" "$STACK/current"
    printf '%s\n' "$OLD_TAG" > "$STACK/.deployed-tag"
    chown -h fahimni:fahimni "$STACK/current" "$STACK/.deployed-tag" 2>/dev/null || true
  else
    warn "no previous release recorded — cannot auto-rollback"
    return 1
  fi
  local _
  for _ in $(seq 1 12); do
    if [ "$(code "$BASE_LOOP/api/health/ready")" = 200 ] && [ "$(code "$BASE_LOOP/")" = 200 ]; then
      log "rollback health: OK"
      return 0
    fi
    sleep 5
  done
  warn "rollback health still failing — escalate with the revert commands printed by the rollout"
  return 1
}

verify_live(){
  section "post-deploy verification (independent of the rollout's own checks)"
  UNSTABLE=0
  NONCRIT_FAIL=0
  local p c

  if verify_containers; then log "containers: all five running, the four app services on $NEW_TAG"; else warn "container readiness failed"; NONCRIT_FAIL=$((NONCRIT_FAIL + 1)); fi

  if wait_core_health; then
    log "core loopback health: 200 on /up /api/health/live /api/health/ready /"
  else
    warn "core loopback health is NOT stable"
    for p in /up /api/health/live /api/health/ready /; do log "  loopback $p -> $(code "$BASE_LOOP$p")"; done
    UNSTABLE=1
  fi

  local ps ps_name
  ps="$(curl -sS --max-time "$HTTP_TIMEOUT" -H "Host: $HOSTHDR" "$BASE_LOOP/api/v1/platform-settings" 2>/dev/null || true)"
  ps_name="$(platform_name_status "$ps")" || true
  case "$ps_name" in
    ok)       log "identity: platform-settings serves «منصة فهمني» (decoded JSON)" ;;
    legacy)   warn "identity: platform-settings still exposes the old brand"; NONCRIT_FAIL=$((NONCRIT_FAIL + 1)) ;;
    invalid)  warn "identity: platform-settings body is not valid JSON or has no string name"; NONCRIT_FAIL=$((NONCRIT_FAIL + 1)) ;;
    empty)    warn "identity: platform-settings serves an empty name"; NONCRIT_FAIL=$((NONCRIT_FAIL + 1)) ;;
    noparser) warn "identity: no JSON parser (jq/python3/php) is available to verify the platform name"; NONCRIT_FAIL=$((NONCRIT_FAIL + 1)) ;;
    wrong:*)  warn "identity: platform-settings serves a wrong name: ${ps_name#wrong:}"; NONCRIT_FAIL=$((NONCRIT_FAIL + 1)) ;;
    *)        warn "identity: platform-settings verification did not produce a verdict"; NONCRIT_FAIL=$((NONCRIT_FAIL + 1)) ;;
  esac

  if [ -f "$REL/frontend/public/logo.png" ]; then
    local expect got served
    expect="$(sha256_file "$REL/frontend/public/logo.png")"
    served="$(mktemp)"
    curl -sS --max-time "$HTTP_TIMEOUT" -H "Host: $HOSTHDR" -o "$served" "$BASE_LOOP/logo.png" 2>/dev/null || true
    got="$(sha256_file "$served")"
    rm -f "$served"
    if [ -n "$expect" ] && [ "$expect" = "$got" ]; then
      log "identity: served /logo.png matches the release (${got:0:12}…)"
    else
      warn "identity: served /logo.png differs from the release (want ${expect:0:12}… got ${got:0:12}…)"
      NONCRIT_FAIL=$((NONCRIT_FAIL + 1))
    fi
  fi

  for p in / /api/health/ready; do
    c="$(code "$HOME_URL$p")"
    log "  public $HOME_URL$p -> $c"
    case "$c" in
      5*) warn "public $p returned $c"; NONCRIT_FAIL=$((NONCRIT_FAIL + 1)) ;;
    esac
  done

  # Migration + permission evidence (read-only, from the deployed image).
  section "post-migration evidence (read-only)"
  local pending_out perm_out
  pending_out="$( cd "$REL" && IMAGE_TAG="$NEW_TAG" docker compose --env-file "$ENVF" \
      run --rm --no-deps app php artisan migrations:pending --no-ansi 2>/dev/null || true )"
  log "migrations:pending after the deploy:"; printf '%s\n' "${pending_out:-<none>}" | sed 's/^/    /' | tee -a "$LOG"
  perm_out="$( cd "$REL" && IMAGE_TAG="$NEW_TAG" docker compose --env-file "$ENVF" \
      run --rm --no-deps app php artisan permissions:ensure-cards-export --no-ansi 2>/dev/null || true )"
  log "cards.export preview after the deploy (must show no removal):"; printf '%s\n' "${perm_out:-<no output>}" | sed 's/^/    /' | tee -a "$LOG"

  local ll_after
  ll_after="$(ll_snapshot)"
  if [ "$LL_BEFORE" = "$ll_after" ]; then
    if [ "$LL_ABSENT" = 1 ]; then
      log "LooksLoop: none before and none now (standalone host — nothing to protect)"
    else
      log "LooksLoop: unchanged (baseline == now)"
    fi
  else
    warn "LooksLoop state CHANGED — a human must investigate (this runner never touched it)"
    diff <(printf '%s\n' "$LL_BEFORE") <(printf '%s\n' "$ll_after") | tee -a "$LOG" || true
    NONCRIT_FAIL=$((NONCRIT_FAIL + 1))
  fi

  section "launch smoke test (real results)"
  local smoke_rc=0
  if "$TOOLS/deploy/scripts/launch-smoke-test.sh" --base "$BASE_LOOP" --host-header "$HOSTHDR" \
       --release "$REL" --allow-login-probe 2>&1 | tee -a "$LOG"; then smoke_rc=0; else smoke_rc=$?; fi
  if [ "$smoke_rc" = 0 ]; then SMOKE_RESULT="OK"; else SMOKE_RESULT="FAIL"; NONCRIT_FAIL=$((NONCRIT_FAIL + 1)); fi

  if [ "$UNSTABLE" = 1 ]; then return 1; fi
  [ "$NONCRIT_FAIL" = 0 ]
}

# ── main ────────────────────────────────────────────────────────────────────
section "Fahimni GO-LIVE runner — $TS"
log "log file : $LOG"
preflight_host
ensure_tools
verify_host_script
snapshot_state

if [ "$DEPLOY_NEEDED" = 0 ]; then
  log "$NEW_TAG is already the pinned tag — nothing to deploy; verifying only"
  DRILL_OK=1
  BACKUP_ACTION="not-needed (already deployed)"
else
  ensure_backup_and_drill
  if [ "$DRY_RUN" = 1 ]; then
    section "DRY RUN complete"
    log "pre-flight, backup and restore drill all passed; the deploy was NOT run"
    write_result "DRY-RUN-OK"
    exit 0
  fi
  run_deploy
fi

if verify_live; then
  section "RESULT: PRODUCTION LIVE"
  log "«منصة فهمني» is live on $RELEASE_SHA; backup restored, migration + permission applied, LooksLoop untouched"
  write_result "LIVE"
  exit 0
fi

if [ "$UNSTABLE" = 1 ]; then
  warn "the service is UNSTABLE after the deploy — invoking the approved safe rollback"
  if safe_rollback; then
    write_result "ROLLED-BACK"
    section "RESULT: ROLLED BACK"
    log "the previous release ($OLD_TAG) is serving again; the schema was NOT reverted"
    exit 2
  fi
  write_result "ROLLBACK-FAILED"
  die "the service is unstable AND the automatic rollback did not restore health — a human must act now (see the revert commands above)"
fi

write_result "LIVE-WITH-ANOMALIES"
section "RESULT: PRODUCTION LIVE — ATTENTION NEEDED"
log "the site is serving (core health is 200) but one or more post-deploy checks reported an anomaly — see the WARN lines and the smoke summary above"
exit 3
