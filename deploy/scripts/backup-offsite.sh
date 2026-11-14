#!/usr/bin/env bash
# Off-site backup for one Fahimni stack: PostgreSQL dump + uploaded media,
# encrypted before leaving the host, uploaded with rclone and verified.
#
# Run daily from cron/systemd-timer as a user that can run docker and rclone.
# The script refuses to upload unencrypted data unless BACKUP_ALLOW_UNENCRYPTED=1
# is set explicitly (only acceptable for a local drill, never for production).
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

usage() {
  cat <<'EOF'
Usage: backup-offsite.sh --env production|staging [options]

  --env NAME          target stack (required)
  --env-file PATH     secret env file (default: <release>/.env)
  --output-dir DIR    keep encrypted archives in DIR (also with --no-upload)
  --keep-days N       off-site retention (default: 14)
  --no-upload         do not call rclone; keep archives locally
  --db-only           skip the uploaded-media archive

Encryption (one is required):
  BACKUP_AGE_RECIPIENT=age1...            age public key
  BACKUP_ENCRYPTION_PASSPHRASE_FILE=/...  root-only passphrase file
  BACKUP_ALLOW_UNENCRYPTED=1              explicit drill-only override
EOF
}

env_name=""
env_file=""
output_dir=""
keep_days=14
do_upload=1
db_only=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --env)        env_name="${2:-}"; shift 2 ;;
    --env-file)   env_file="${2:-}"; shift 2 ;;
    --output-dir) output_dir="${2:-}"; shift 2 ;;
    --keep-days)  keep_days="${2:-}"; shift 2 ;;
    --no-upload)  do_upload=0; shift ;;
    --db-only)    db_only=1; shift ;;
    -h|--help)    usage; exit 0 ;;
    *) die "unknown argument: $1" ;;
  esac
done

[[ -n "$env_name" ]] || { usage; exit 1; }
validate_env_name "$env_name"
require_cmd docker
docker compose version >/dev/null 2>&1 || die "docker compose plugin is not installed"

env_file="${env_file:-$REPO_DIR/.env}"
load_env_file "$env_file"
[[ "${APP_ENV:-}" == "$env_name" ]] || die "APP_ENV mismatch in $env_file"
require_real_value DB_DATABASE
require_real_value DB_USERNAME

if [[ "$do_upload" -eq 1 ]]; then
  require_cmd rclone
  require_real_value BACKUP_RCLONE_REMOTE
  require_real_value BACKUP_RCLONE_PATH
fi

need_encryption=1
if [[ -n "${BACKUP_AGE_RECIPIENT:-}" ]]; then
  require_cmd age
  need_encryption=0
elif [[ -n "${BACKUP_ENCRYPTION_PASSPHRASE_FILE:-}" ]]; then
  [[ -r "$BACKUP_ENCRYPTION_PASSPHRASE_FILE" ]] \
    || die "cannot read BACKUP_ENCRYPTION_PASSPHRASE_FILE"
  need_encryption=0
elif [[ "${BACKUP_ALLOW_UNENCRYPTED:-0}" == "1" ]]; then
  warn "BACKUP_ALLOW_UNENCRYPTED=1: archives will be uploaded without encryption"
  need_encryption=0
fi
if [[ "$need_encryption" -eq 1 ]]; then
  die "no backup encryption configured (set BACKUP_AGE_RECIPIENT or BACKUP_ENCRYPTION_PASSPHRASE_FILE)"
fi

lock="/tmp/fahimni-backup-${env_name}.lock"
acquire_lock "$lock"
on_exit() {
  local status=$?
  trap - EXIT
  release_lock
  rm -rf "${tmp:-}"
  exit "$status"
}
trap on_exit EXIT

tmp="$(mktemp -d "${TMPDIR:-/tmp}/fahimni-backup-${env_name}.XXXXXX")"
ts="$(date -u +%Y%m%dT%H%M%SZ)"

log "dumping PostgreSQL ($DB_DATABASE)"
dump="$tmp/db-$ts.dump"
compose exec -T postgres pg_dump \
  -U "$DB_USERNAME" -d "$DB_DATABASE" \
  --format=custom --no-owner --no-privileges --no-password > "$dump"
[[ -s "$dump" ]] || die "pg_dump produced an empty file"
[[ "$(head -c 5 "$dump")" == "PGDMP" ]] || die "pg_dump output is not a custom-format archive"

if [[ "$db_only" -eq 0 ]]; then
  log "archiving uploaded media"
  files="$tmp/files-$ts.tar.gz"
  if ! compose exec -T app tar -czf - \
      -C /var/www/html/storage/app \
      --exclude=backups --exclude=logs . > "$files"; then
    die "media archive failed; rerun with --db-only only if media is intentionally empty"
  fi
  [[ -s "$files" ]] || die "media archive is empty"
  gzip -t "$files" || die "media archive failed gzip integrity check"
fi

encrypt_one() {
  local file="$1"
  if [[ -n "${BACKUP_AGE_RECIPIENT:-}" ]]; then
    age -r "$BACKUP_AGE_RECIPIENT" -o "${file}.age" "$file"
    rm -f "$file"
    printf '%s' "${file}.age"
  elif [[ -n "${BACKUP_ENCRYPTION_PASSPHRASE_FILE:-}" ]]; then
    openssl enc -aes-256-cbc -pbkdf2 -salt \
      -in "$file" -out "${file}.enc" \
      -pass "file:${BACKUP_ENCRYPTION_PASSPHRASE_FILE}"
    rm -f "$file"
    printf '%s' "${file}.enc"
  else
    printf '%s' "$file"
  fi
}

archives=()
while IFS= read -r -d '' file; do
  archives+=("$(encrypt_one "$file")")
done < <(find "$tmp" -maxdepth 1 -type f \( -name 'db-*.dump' -o -name 'files-*.tar.gz' \) -print0)

(( ${#archives[@]} > 0 )) || die "no archives produced"
for file in "${archives[@]}"; do
  chmod 600 "$file" 2>/dev/null || true
done

manifest="$tmp/manifest-$ts.txt"
{
  printf 'environment=%s\n' "$env_name"
  printf 'created_at=%s\n' "$ts"
  for file in "${archives[@]}"; do
    size="$(wc -c < "$file" | tr -d '[:space:]')"
    if command -v sha256sum >/dev/null 2>&1; then
      hash="$(sha256sum "$file" | awk '{print $1}')"
    else
      hash="$(shasum -a 256 "$file" | awk '{print $1}')"
    fi
    printf '%s size=%s sha256=%s\n' "$(basename "$file")" "$size" "$hash"
  done
} > "$manifest"
chmod 600 "$manifest" 2>/dev/null || true

if [[ -n "$output_dir" ]]; then
  mkdir -p "$output_dir"
  cp -p "$manifest" "${archives[@]}" "$output_dir/"
  log "local copies: $output_dir"
fi

if [[ "$do_upload" -eq 0 ]]; then
  if [[ -z "$output_dir" ]]; then
    output_dir="$(mktemp -d "${TMPDIR:-/tmp}/fahimni-backup-out.XXXXXX")"
    cp -p "$manifest" "${archives[@]}" "$output_dir/"
  fi
  log "upload skipped; archives kept in $output_dir"
  exit 0
fi

remote="${BACKUP_RCLONE_REMOTE}:${BACKUP_RCLONE_PATH%/}/${env_name}"
log "uploading to rclone remote '$remote'"
rclone copy "$tmp" "$remote" --immutable \
  || die "rclone upload failed"
rclone check "$tmp" "$remote" --one-way \
  || die "rclone verification failed (local/remote mismatch)"

log "pruning off-site archives older than ${keep_days} days"
rclone delete "$remote" --min-age "${keep_days}d" || warn "rclone retention delete failed"

log "backup complete: ${#archives[@]} archive(s), manifest $(basename "$manifest")"
