#!/usr/bin/env bash
# Install, validate and reload the additive Fahimni Caddy vhosts without
# touching the existing LooksLoop site or replacing the main Caddyfile.
#
# --check only validates a rendered copy. --apply backs up the Caddyfile,
# installs the vhosts, validates again, reloads Caddy, and automatically
# restores the backup if validation or reload fails.
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

usage() {
  cat <<'EOF'
Usage: caddy-activate.sh --check|--apply [options]

  --check             render + validate only; change nothing
  --apply             install and reload Caddy (requires root)
  --source PATH       repo Caddy file (default: deploy/caddy/fahimni.caddy)
  --caddyfile PATH    existing Caddyfile (default: /etc/caddy/Caddyfile)
  --dest-dir DIR      vhost install dir (default: /etc/caddy/fahimni)

Required environment for both modes:
  FAHIMNI_STAGING_BASIC_HASH  bcrypt hash from `caddy hash-password`

Generate the hash without exposing the password in shell history:
  caddy hash-password
  export FAHIMNI_STAGING_BASIC_HASH='$2a$...'
EOF
}

mode=""
source_file="$DEPLOY_DIR/caddy/fahimni.caddy"
caddyfile="/etc/caddy/Caddyfile"
dest_dir="/etc/caddy/fahimni"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --check)     mode="check"; shift ;;
    --apply)     mode="apply"; shift ;;
    --source)    source_file="${2:-}"; shift 2 ;;
    --caddyfile) caddyfile="${2:-}"; shift 2 ;;
    --dest-dir)  dest_dir="${2:-}"; shift 2 ;;
    -h|--help)   usage; exit 0 ;;
    *) die "unknown argument: $1" ;;
  esac
done

[[ "$mode" == "check" || "$mode" == "apply" ]] || { usage; exit 1; }
require_cmd caddy
[[ -f "$source_file" ]] || die "source Caddy file not found: $source_file"

hash="${FAHIMNI_STAGING_BASIC_HASH:-}"
[[ -n "$hash" ]] || die "FAHIMNI_STAGING_BASIC_HASH is not set (generate it with: caddy hash-password)"
[[ "$hash" == \$2* && "${#hash}" -ge 59 ]] \
  || die "FAHIMNI_STAGING_BASIC_HASH is not a bcrypt hash"

if [[ "$mode" == "apply" ]]; then
  require_root
  [[ -f "$caddyfile" ]] || die "Caddyfile not found: $caddyfile"
fi

work="$(mktemp -d "${TMPDIR:-/tmp}/fahimni-caddy.XXXXXX")"
trap 'rm -rf "$work"' EXIT

rendered="$work/fahimni.caddy"
# The literal replaces the repo placeholder in exactly two staging blocks.
awk -v h="$hash" '{ gsub(/__STAGING_BCRYPT_HASH__/, h); print }' \
  "$source_file" > "$rendered"
chmod 600 "$rendered"

validate_file() {
  local file="$1"
  caddy validate --config "$file" --adapter caddyfile >/dev/null
}

log "validating rendered Fahimni vhosts"
validate_file "$rendered" || die "Caddy validation failed; nothing was changed"

if [[ "$mode" == "check" ]]; then
  log "Caddy validation: OK (check mode, no files changed)"
  exit 0
fi

# Guardrail: refuse to apply if another block already defines these hosts,
# which would make Caddy reject duplicate site addresses.
if grep -Eq '^[^#]*(fahimni\.me|www\.fahimni\.me|api\.fahimni\.me|staging\.fahimni\.me|api-staging\.fahimni\.me)[[:space:],{]' "$caddyfile"; then
  die "the existing Caddyfile already defines a Fahimni host; resolve it manually"
fi

backup="$caddyfile.bak.$(date -u +%Y%m%dT%H%M%SZ)"
cp -p "$caddyfile" "$backup"

dest_file="$dest_dir/fahimni.caddy"
old_dest=""
if [[ -f "$dest_file" ]]; then
  old_dest="$work/fahimni.caddy.old"
  cp -p "$dest_file" "$old_dest"
fi

restore_all() {
  cp -p "$backup" "$caddyfile"
  if [[ -n "$old_dest" ]]; then
    cp -p "$old_dest" "$dest_file"
  else
    rm -f "$dest_file"
  fi
}

# The Caddy service must be able to read the installed file after a restart.
# The file contains a bcrypt hash, not the plaintext staging password.
if getent group caddy >/dev/null 2>&1; then
  install -d -m 750 -o root -g caddy "$dest_dir"
  install -m 640 -o root -g caddy "$rendered" "$dest_file"
else
  install -d -m 755 "$dest_dir"
  install -m 644 "$rendered" "$dest_file"
  warn "no 'caddy' group found; installed vhosts are world-readable (bcrypt hash only)"
fi

if ! grep -Fq "import $dest_dir/*.caddy" "$caddyfile"; then
  {
    printf '\n# Fahimni vhosts (additive; do not replace the LooksLoop config)\n'
    printf 'import %s/*.caddy\n' "$dest_dir"
  } >> "$caddyfile"
fi

if ! validate_file "$caddyfile"; then
  warn "full Caddyfile validation failed; restoring the previous configuration"
  restore_all
  die "nothing was reloaded"
fi

caddy_active=0
if systemctl is-active --quiet caddy 2>/dev/null; then
  caddy_active=1
  log "reloading Caddy (graceful; LooksLoop stays up)"
  if ! systemctl reload caddy; then
    warn "Caddy reload failed; restoring the previous configuration"
    restore_all
    systemctl reload caddy || warn "manual Caddy recovery required"
    die "reload failed; previous configuration restored"
  fi
else
  log "Caddy is not the active systemd service; run 'caddy reload' yourself after reviewing $caddyfile"
fi

if [[ "$caddy_active" -eq 1 ]] && ! systemctl is-active --quiet caddy 2>/dev/null; then
  warn "Caddy is not active after reload; restoring the previous configuration"
  restore_all
  systemctl reload caddy 2>/dev/null || true
  die "Caddy is not active; previous configuration restored"
fi

log "Fahimni vhosts installed at $dest_dir/fahimni.caddy"
log "backup: $backup"
log "verify LooksLoop and Fahimni HTTPS, then confirm certificates issued"
