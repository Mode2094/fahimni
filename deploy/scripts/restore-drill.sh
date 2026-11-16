#!/usr/bin/env bash
# Restore drill: restore the newest off-site backup into a disposable,
# network-isolated PostgreSQL container and run sanity queries.
#
# It never connects to the production database, never publishes a port, and
# never touches the running stack.
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

usage() {
  cat <<'EOF'
Usage: restore-drill.sh --env production|staging [options]

  --env NAME        target stack whose backup is restored (required)
  --env-file PATH   secret env file (default: <release>/.env)
  --file PATH       local backup file (.dump, .age, .enc)
  --from-remote     download the newest db-* archive from rclone
  --allow-unencrypted  accept a plain (unencrypted) .dump for an explicit
                       --file ONLY. It is a per-invocation acknowledgement and
                       is never read from --env-file, so a value there cannot
                       veto it. Refused for --from-remote.
  --max-age-hours N fail if the chosen backup is older than N hours (0 = off)
  --keep            keep the disposable container for inspection
  --yes             skip the confirmation prompt

Decryption uses BACKUP_AGE_IDENTITY (for .age) or
BACKUP_ENCRYPTION_PASSPHRASE_FILE (for .enc). A plain .dump is only accepted
with the explicit --allow-unencrypted flag (or BACKUP_ALLOW_UNENCRYPTED=1 in
the environment).
EOF
}

env_name=""
env_file=""
file=""
from_remote=0
max_age_hours=""
keep=0
yes=0
# A per-invocation acknowledgement for a plain .dump. Deliberately its own
# variable: it is set only by the CLI flag and is NOT re-read from the env
# file, so `BACKUP_ALLOW_UNENCRYPTED=0` in production.env can never veto it.
allow_unencrypted=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --env)           env_name="${2:-}"; shift 2 ;;
    --env-file)      env_file="${2:-}"; shift 2 ;;
    --file)          file="${2:-}"; shift 2 ;;
    --from-remote)   from_remote=1; shift ;;
    --allow-unencrypted) allow_unencrypted=1; shift ;;
    --max-age-hours) max_age_hours="${2:-}"; shift 2 ;;
    --keep)          keep=1; shift ;;
    --yes)           yes=1; shift ;;
    -h|--help)       usage; exit 0 ;;
    *) die "unknown argument: $1" ;;
  esac
done

[[ -n "$env_name" ]] || { usage; exit 1; }
validate_env_name "$env_name"
# Scope the acknowledgement to an explicit local file: it must never be used to
# bless an off-site (remote) archive.
if [[ "$allow_unencrypted" -eq 1 && -z "$file" ]]; then
  die "--allow-unencrypted is only valid with --file (never for the off-site remote)"
fi
require_cmd docker

env_file="${env_file:-$REPO_DIR/.env}"
load_env_file "$env_file"
[[ "${APP_ENV:-}" == "$env_name" ]] || die "APP_ENV mismatch in $env_file"

tmp="$(mktemp -d "${TMPDIR:-/tmp}/fahimni-restore-drill.XXXXXX")"
container="fahimni-restore-drill-$$"

cleanup() {
  if [[ "$keep" -eq 0 ]]; then
    docker rm -f "$container" >/dev/null 2>&1 || true
  else
    warn "keeping disposable container: $container"
  fi
  rm -rf "$tmp"
}
on_exit() {
  local status=$?
  trap - EXIT
  release_lock
  cleanup
  exit "$status"
}
trap on_exit EXIT

if [[ -n "$file" ]]; then
  [[ -f "$file" ]] || die "backup file not found: $file"
  cp -p "$file" "$tmp/"
  file="$tmp/$(basename "$file")"
elif [[ "$from_remote" -eq 1 ]]; then
  require_cmd rclone
  require_real_value BACKUP_RCLONE_REMOTE
  require_real_value BACKUP_RCLONE_PATH
  remote="${BACKUP_RCLONE_REMOTE}:${BACKUP_RCLONE_PATH%/}/${env_name}"
  latest="$(rclone lsf --files-only "$remote" 2>/dev/null \
    | grep -E '^db-.*\.(dump|age|enc)$' | sort | tail -1 || true)"
  [[ -n "$latest" ]] || die "no db-* backup found at rclone remote '$remote'"
  log "downloading $latest"
  rclone copyto "$remote/$latest" "$tmp/$latest" || die "rclone download failed"
  file="$tmp/$latest"
else
  die "pass --file PATH or --from-remote"
fi

# Fail closed before decrypting/restoring when the chosen archive is not recent.
# This is the evidence gate behind FAHIMNI_DB_BACKUP_VERIFIED=1: a drill that
# restores a stale archive proves the *procedure*, not that a fresh backup of
# today's production exists.
assert_backup_fresh "$file" "${max_age_hours:-}"

dump="$tmp/restore.dump"
case "$file" in
  *.age)
    require_cmd age
    [[ -n "${BACKUP_AGE_IDENTITY:-}" ]] || die "BACKUP_AGE_IDENTITY is not set"
    [[ -r "$BACKUP_AGE_IDENTITY" ]] || die "cannot read BACKUP_AGE_IDENTITY"
    age -d -i "$BACKUP_AGE_IDENTITY" -o "$dump" "$file" || die "age decryption failed"
    ;;
  *.enc)
    [[ -n "${BACKUP_ENCRYPTION_PASSPHRASE_FILE:-}" ]] || die "BACKUP_ENCRYPTION_PASSPHRASE_FILE is not set"
    [[ -r "$BACKUP_ENCRYPTION_PASSPHRASE_FILE" ]] || die "cannot read BACKUP_ENCRYPTION_PASSPHRASE_FILE"
    openssl enc -d -aes-256-cbc -pbkdf2 \
      -in "$file" -out "$dump" \
      -pass "file:${BACKUP_ENCRYPTION_PASSPHRASE_FILE}" \
      || die "OpenSSL decryption failed"
    ;;
  *.dump)
    # Accept a plain archive only when THIS invocation explicitly asked for it
    # (--allow-unencrypted) or the environment was explicitly set to 1. The
    # CLI flag is checked first and survived load_env_file, so a 0 in the env
    # file cannot cancel it.
    if [[ "$allow_unencrypted" -eq 1 || "${BACKUP_ALLOW_UNENCRYPTED:-0}" == "1" ]]; then
      :
    else
      die "refusing a plain .dump without --allow-unencrypted (or BACKUP_ALLOW_UNENCRYPTED=1)"
    fi
    cp -p "$file" "$dump"
    ;;
  *)
    die "unsupported backup extension: $file"
    ;;
esac

if [[ "$(head -c 2 "$dump" 2>/dev/null || true)" == $'\x1f\x8b' ]]; then
  gzip -dc "$dump" > "$tmp/restore.uncompressed.dump"
  dump="$tmp/restore.uncompressed.dump"
fi
[[ "$(head -c 5 "$dump")" == "PGDMP" ]] || die "decrypted file is not a PostgreSQL custom archive"

if [[ "$yes" -ne 1 ]]; then
  printf 'Restore %s into a disposable container (production is untouched)? [y/N] ' "$(basename "$file")"
  answer=""
  read -r answer || true
  [[ "$answer" =~ ^[yY]$ ]] || die "cancelled"
fi

lock="/tmp/fahimni-restore-drill.lock"
acquire_lock "$lock"

image="${POSTGRES_IMAGE:-postgres@sha256:cf78e76683b9ca8c5733cbbdce6c9262b45b6767934dd0a95e671f9a0fc20685}"
log "starting disposable PostgreSQL ($image)"
docker run -d --rm --name "$container" --network none \
  -e POSTGRES_HOST_AUTH_METHOD=trust \
  -e POSTGRES_DB=restore_drill \
  "$image" >/dev/null

# Wait for the DISPOSABLE server, not the temporary one the entrypoint starts
# during initdb. The official image brings up a TEMP server (which also logs
# "database system is ready to accept connections"), runs init, stops it, and
# only then starts the real server. `pg_isready` can therefore succeed during
# that temp window, and pg_restore then dies with
#   FATAL:  the database system is shutting down
# so `pg_isready` ALONE is not a readiness signal. Gate on evidence that init
# has finished — the entrypoint's "PostgreSQL init process complete" marker, or
# a SECOND "ready to accept connections" line — AND require restore_drill to
# answer a real query before we touch it.
ready=0
for _ in $(seq 1 120); do
  if ! docker inspect -f '{{.State.Running}}' "$container" 2>/dev/null | grep -q true; then
    docker logs --tail 50 "$container" >&2 2>/dev/null || true
    die "the disposable PostgreSQL container exited before it finished initialising"
  fi
  logs="$(docker logs "$container" 2>/dev/null || true)"
  starts="$(printf '%s\n' "$logs" | grep -c 'database system is ready to accept connections' || true)"
  init_done=0
  case "$logs" in *'PostgreSQL init process complete'*) init_done=1 ;; esac
  if [ "${starts:-0}" -ge 2 ]; then init_done=1; fi
  if [ "$init_done" -eq 1 ] \
     && docker exec "$container" pg_isready -U postgres -d restore_drill >/dev/null 2>&1 \
     && docker exec "$container" psql -U postgres -d restore_drill -tAc 'SELECT 1' >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 1
done
[[ "$ready" -eq 1 ]] || die "the disposable PostgreSQL did not finish initialising and become ready"
log "disposable PostgreSQL initialised; restore_drill is accepting connections"

log "restoring archive"
docker exec -i "$container" pg_restore \
  -U postgres -d restore_drill \
  --clean --if-exists --no-owner --no-privileges --exit-on-error < "$dump" \
  || die "pg_restore failed"

query() {
  docker exec "$container" psql -U postgres -d restore_drill -tAc "$1" 2>/dev/null | tr -d '[:space:]'
}

migrations="$(query 'SELECT count(*) FROM migrations' || true)"
users="$(query 'SELECT count(*) FROM users' || true)"
tenants="$(query 'SELECT count(*) FROM tenants' || true)"
size="$(query "SELECT pg_size_pretty(pg_database_size('restore_drill'))" || true)"

[[ "$migrations" =~ ^[0-9]+$ && "$migrations" -gt 0 ]] \
  || die "restored database has no migrations rows"
[[ "$users" =~ ^[0-9]+$ && "$users" -gt 0 ]] \
  || die "restored database has no users rows"

log "restore drill: OK"
log "migrations=$migrations users=$users tenants=${tenants:-n/a} size=${size:-unknown}"
log "the production stack was not used or modified"
