#!/usr/bin/env bash
# Generate a secret-free-template -> real env file for one stack.
#
# It merges deploy/env/common.env.example with the stack template, generates
# APP_KEY, DB/Redis passwords, media signing keys, the first-admin password and
# the backup encryption key locally, and writes the result with mode 600.
# Secret values are never printed.
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

usage() {
  cat <<'EOF'
Usage: init-env.sh --env production|staging --out /etc/fahimni/<env>.env [--force]

  --force  overwrite an existing file. DANGEROUS: rotates DB/Redis passwords;
           only use before the first start or when you also update the running
           database/Redis credentials.
EOF
}

env_name=""
out=""
force=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --env)   env_name="${2:-}"; shift 2 ;;
    --out)   out="${2:-}"; shift 2 ;;
    --force) force=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) die "unknown argument: $1" ;;
  esac
done

[[ -n "$env_name" && -n "$out" ]] || { usage; exit 1; }
validate_env_name "$env_name"
require_cmd openssl

common="$DEPLOY_DIR/env/common.env.example"
specific="$DEPLOY_DIR/env/${env_name}.env.example"
[[ -f "$common" ]]   || die "missing template: $common"
[[ -f "$specific" ]] || die "missing template: $specific"

if [[ -e "$out" && "$force" -ne 1 ]]; then
  die "refusing to overwrite existing $out (pass --force only if you understand the password rotation)"
fi

umask 077
tmp="$(mktemp "${TMPDIR:-/tmp}/fahimni-env.XXXXXX")"
tmp2="${tmp}.new"
cleanup() { rm -f "$tmp" "$tmp2"; }
trap cleanup EXIT

cat "$common" "$specific" > "$tmp"

duplicates="$(awk -F= '/^[A-Z0-9_]+=/{print $1}' "$tmp" | sort | uniq -d)"
[[ -z "$duplicates" ]] || die "duplicate keys in templates: $duplicates"

replace_value() {
  local key="$1" value="$2"
  awk -v k="$key" -v v="$value" '
    BEGIN { FS = OFS = "=" }
    $1 == k { print k "=" v; next }
    { print }
  ' "$tmp" > "$tmp2"
  mv "$tmp2" "$tmp"
}

random_base64() {
  printf 'base64:%s' "$(openssl rand -base64 32 | tr -d '\n')"
}

random_hex() {
  openssl rand -hex "${1:-24}" | tr -d '\n'
}

replace_value APP_KEY "$(random_base64)"
replace_value DB_PASSWORD "$(random_hex 32)"
replace_value REDIS_PASSWORD "$(random_hex 32)"
replace_value VIDEO_SIGNING_KEY "$(random_base64)"
replace_value REFUND_WEBHOOK_SECRET "$(random_base64)"
replace_value SUPER_ADMIN_PASSWORD "$(random_hex 24)"
replace_value BACKUP_ENCRYPTION_KEY "$(random_base64)"

# Sanity check: every generated key must now be present and non-placeholder.
for key in APP_KEY DB_PASSWORD REDIS_PASSWORD VIDEO_SIGNING_KEY \
           REFUND_WEBHOOK_SECRET SUPER_ADMIN_PASSWORD BACKUP_ENCRYPTION_KEY; do
  grep -q "^${key}=" "$tmp" || die "template is missing $key"
done

mkdir -p "$(dirname "$out")"
install -m 600 "$tmp" "$out"

log "wrote $out (mode 600); secret values were generated locally and not printed"

unresolved="$(
  awk -F= '/^[A-Z0-9_]+=CHANGE_ME/ && $1 != "IMAGE_TAG" {
    printf "%s%s", sep, $1; sep = ", "
  }' "$out"
)"
if [[ -n "$unresolved" ]]; then
  warn "still needs real values before launch: $unresolved"
fi
