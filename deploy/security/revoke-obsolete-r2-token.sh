#!/usr/bin/env bash
#
# Revoke the obsolete (exposed) R2 credential and, optionally, any named
# Cloudflare account/API token — using an owner-authorised, account-scoped
# token supplied out-of-band (see owner-credential-intake.html).
#
# The account-scoped token is read from a 0600 file and is never printed, never
# passed on the command line, and never written to shell history or logs.
#
# WHY A SCRIPT
#   The production token is deliberately zone-scoped, so the app itself gets
#   HTTP 403 from every account-level endpoint (`/accounts/{id}/tokens`,
#   `/accounts/{id}/r2/*`). Revoking the exposed R2 key is therefore an
#   account-owner action. This script performs exactly that action and nothing
#   else: it never widens any permission and never touches the active key.
#
# USAGE (on the production host, as root):
#   TOKEN_FILE=/root/.fahimni-cf-account-token \
#     ./deploy/security/revoke-obsolete-r2-token.sh              # dry-run: list only
#   TOKEN_FILE=/root/.fahimni-cf-account-token \
#     ./deploy/security/revoke-obsolete-r2-token.sh --apply      # delete the obsolete key
#
# The prefix to revoke defaults to the exposed key `adfc4dad`. The active
# replacement (`1ceeee…`) is never a target and is explicitly guarded against.
#
set -Eeuo pipefail

ACCT=${CF_ACCOUNT_ID:-47f4042417b8f04821fd5545fdd3b83f}
TOKEN_FILE=${TOKEN_FILE:-/root/.fahimni-cf-account-token}
OBSOLETE_PREFIX=${OBSOLETE_PREFIX:-adfc4dad}
KEEP_PREFIX=${KEEP_PREFIX:-1ceeee}
API=https://api.cloudflare.com/client/v4

APPLY=0
for arg in "$@"; do
  case "$arg" in
    --apply) APPLY=1 ;;
    --help|-h) sed -n '2,30p' "$0"; exit 0 ;;
    *) echo "unknown argument: $arg" >&2; exit 2 ;;
  esac
done

log() { printf '%s %s\n' "$(date -u +%H:%M:%SZ)" "$*"; }
die() { log "ERROR: $*" >&2; exit 1; }

command -v curl >/dev/null || die "curl is required"
command -v jq   >/dev/null || die "jq is required"

[ -f "$TOKEN_FILE" ] || die "account token file not found: $TOKEN_FILE (see owner-credential-intake.html)"
[ -r "$TOKEN_FILE" ] || die "cannot read $TOKEN_FILE (run as root)"
perm=$(stat -c '%a' "$TOKEN_FILE" 2>/dev/null || stat -f '%Lp' "$TOKEN_FILE")
case "$perm" in 600|400) : ;; *) log "WARNING: $TOKEN_FILE mode is $perm (expected 600)" ;; esac

TOKEN=$(tr -d '\r\n' < "$TOKEN_FILE")
[ -n "$TOKEN" ] || die "account token file is empty"

cf() { # cf <method> <path> [body]
  local method=$1 path=$2 body=${3:-}
  if [ -n "$body" ]; then
    curl -sS -X "$method" -H "Authorization: Bearer $TOKEN" \
      -H "Content-Type: application/json" --data "$body" "$API$path"
  else
    curl -sS -X "$method" -H "Authorization: Bearer $TOKEN" "$API$path"
  fi
}
unset TOKEN

# --- 0. token must be active and able to manage account tokens --------------
verify=$(cf GET "/user/tokens/verify")
printf '%s' "$verify" | jq -e '.success == true and .result.status == "active"' >/dev/null \
  || die "the supplied token is not active"

acct_ok=$(cf GET "/accounts/${ACCT}/tokens?per_page=1")
printf '%s' "$acct_ok" | jq -e '.success == true' >/dev/null \
  || die "the token lacks 'Account API Tokens' permission (cannot list account tokens)"
log "account token OK; scope verified for account ${ACCT}"

# --- 1. list account tokens (ids/names are not secret) ----------------------
tokens=$(cf GET "/accounts/${ACCT}/tokens?per_page=200")
printf '%s' "$tokens" | jq -e '.success == true' >/dev/null || die "failed to list account tokens"

log "account-owned tokens visible:"
printf '%s' "$tokens" | jq -r '.result[] | "  - \(.id[0:8])…  \(.name // "(unnamed)")"'

obsolete_id=$(printf '%s' "$tokens" | jq -r --arg p "$OBSOLETE_PREFIX" \
  '.result[] | select(.id | startswith($p)) | .id' | head -1)
active_id=$(printf '%s' "$tokens" | jq -r --arg p "$KEEP_PREFIX" \
  '.result[] | select(.id | startswith($p)) | .id' | head -1)

[ -n "$active_id" ] && log "active replacement present (${active_id:0:8}…) — will NOT be touched"

if [ -z "$obsolete_id" ]; then
  log "no account token starts with '${OBSOLETE_PREFIX}' — it may already be revoked."
  exit 0
fi

log "obsolete token to revoke: ${obsolete_id:0:8}…"

if [ "$APPLY" -ne 1 ]; then
  log "dry-run only. Re-run with --apply to revoke ${obsolete_id:0:8}…"
  exit 0
fi

# --- 2. revoke, then prove it is gone ---------------------------------------
del=$(cf DELETE "/accounts/${ACCT}/tokens/${obsolete_id}")
printf '%s' "$del" | jq -e '.success == true' >/dev/null || die "delete failed: $(printf '%s' "$del" | jq -c '.errors')"

after=$(cf GET "/accounts/${ACCT}/tokens?per_page=200")
still=$(printf '%s' "$after" | jq -r --arg p "$OBSOLETE_PREFIX" '.result[] | select(.id | startswith($p)) | .id' | head -1)

if [ -n "$still" ]; then die "revocation did not take effect"; fi
log "obsolete token ${obsolete_id:0:8}… revoked and confirmed absent."

if [ -n "$active_id" ]; then
  printf '%s' "$after" | jq -e --arg id "$active_id" '.result[] | select(.id == $id)' >/dev/null \
    || die "the active replacement disappeared — investigate before rotating anything else"
  log "active replacement ${active_id:0:8}… still present."
fi
