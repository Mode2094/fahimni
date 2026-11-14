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
  --keep            keep the disposable container for inspection
  --yes             skip the confirmation prompt

Decryption uses BACKUP_AGE_IDENTITY (for .age) or
BACKUP_ENCRYPTION_PASSPHRASE_FILE (for .enc). Plain .dump files require
BACKUP_ALLOW_UNENCRYPTED=1.
EOF
}

env_name=""
env_file=""
file=""
from_remote=0
keep=0
yes=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --env)         env_name="${2:-}"; shift 2 ;;
    --env-file)    env_file="${2:-}"; shift 2 ;;
    --file)        file="${2:-}"; shift 2 ;;
    --from-remote) from_remote=1; shift ;;
    --keep)        keep=1; shift ;;
    --yes)         yes=1; shift ;;
    -h|--help)     usage; exit 0 ;;
    *) die "unknown argument: $1" ;;
  esac
done

[[ -n "$env_name" ]] || { usage; exit 1; }
validate_env_name "$env_name"
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
    [[ "${BACKUP_ALLOW_UNENCRYPTED:-0}" == "1" ]] \
      || die "refusing a plain .dump without BACKUP_ALLOW_UNENCRYPTED=1"
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

ready=0
for _ in $(seq 1 60); do
  if docker exec "$container" pg_isready -U postgres -d restore_drill >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 1
done
[[ "$ready" -eq 1 ]] || die "disposable PostgreSQL did not become ready"

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
