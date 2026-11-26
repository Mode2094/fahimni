#!/usr/bin/env bash
#
# Rotate the production Cloudflare API token without ever echoing the secret.
#
# WHY THIS EXISTS
#   The production token is deliberately zone-scoped (Cloudflare for SaaS needs
#   Zone -> SSL and Certificates -> Edit, Zone -> DNS -> Edit, Zone -> Zone ->
#   Read on fahimni.me). It is NOT an account-admin token, so the app cannot
#   rotate or revoke itself: `/user/tokens` and `/accounts/...` return 403.
#   Rotating the token therefore needs one short, privileged action that only
#   the account owner can authorise. This script makes that action safe:
#
#     * the new token is read from the terminal with echo disabled — never from
#       argv, never from the environment, never written to shell history;
#     * the token is validated BEFORE it is applied, and an over-privileged
#       (account-scoped) token is refused outright;
#     * the previous value is kept in a 0600 timestamped backup for rollback;
#     * the change is applied atomically and the app is recreated to pick it up;
#     * the result is re-verified live with `fahimni:cloudflare-saas status`.
#
# USAGE (on the production host; run as root):
#   bash /opt/fahimni/production/current/deploy/scripts/rotate-cloudflare-token.sh
#
# Roll back by restoring the printed backup file over /etc/fahimni/production.env
# and re-running the recreate step shown at the end.
#
set -Eeuo pipefail

ROOT=${ROOT:-/opt/fahimni/production}
ENVF=${ENVF:-/etc/fahimni/production.env}
ZONE_NAME=${CLOUDFLARE_ZONE_NAME:-fahimni.me}
STAMP=$(date -u +%Y%m%dT%H%M%SZ)

log()  { printf '%s %s\n' "$(date -u +%H:%M:%SZ)" "$*"; }
die()  { log "ERROR: $*" >&2; exit 1; }

[ -f "$ENVF" ] || die "env file not found: $ENVF"
[ -r "$ENVF" ] || die "cannot read env file (run as root): $ENVF"

command -v curl >/dev/null || die "curl is required"

# ---------------------------------------------------------------- read secret
if [ -t 0 ]; then
  printf 'Paste the NEW zone-scoped CLOUDFLARE_API_TOKEN (input hidden), then Enter: ' >&2
  read -rs NEW_TOKEN
  printf '\n' >&2
else
  # Piped input is already not echoed to the terminal.
  read -r NEW_TOKEN
fi

[ -n "${NEW_TOKEN:-}" ] || die "empty token; nothing done"
case "$NEW_TOKEN" in
  *[!A-Za-z0-9_-]*) die "token contains unexpected characters; aborting" ;;
esac

# --------------------------------------------------------- read current zone
ZONE_ID=$(grep -E '^CLOUDFLARE_ZONE_ID=' "$ENVF" | head -1 | cut -d= -f2- | tr -d '"' || true)
[ -n "$ZONE_ID" ] || die "CLOUDFLARE_ZONE_ID missing from $ENVF"

api() { # api <method> <path>  -> prints "<http_code>\n<body>"
  local method=$1 path=$2
  curl -sS -X "$method" \
    -H "Authorization: Bearer $NEW_TOKEN" \
    -H "Content-Type: application/json" \
    -w $'\n%{http_code}' \
    "https://api.cloudflare.com/client/v4${path}"
}

# ------------------------------------------------------- validate the token
log "validating the new token against zone $ZONE_NAME ..."

# 1. Must NOT be an account-admin token (refuse over-broad credentials).
ACC_CODE=$(curl -sS -o /dev/null -w '%{http_code}' \
  -H "Authorization: Bearer $NEW_TOKEN" \
  "https://api.cloudflare.com/client/v4/accounts")
if [ "$ACC_CODE" = "200" ]; then
  die "refusing: this token can list accounts (over-privileged). Use a zone-scoped token."
fi
log "  - not account-scoped (accounts -> $ACC_CODE) OK"

# 2. Zone read must work, and it must be the expected zone.
RESP=$(api GET "/zones/${ZONE_ID}")
CODE=$(printf '%s' "$RESP" | tail -n1)
BODY=$(printf '%s' "$RESP" | sed '$d')
[ "$CODE" = "200" ] || die "zone read failed (HTTP $CODE): ${BODY:0:200}"
printf '%s' "$BODY" | grep -q "\"name\":\"${ZONE_NAME}\"" || die "token cannot read zone $ZONE_NAME"
log "  - zone read OK ($ZONE_NAME)"

# 3. The Cloudflare-for-SaaS surface the app relies on must be reachable.
RESP=$(api GET "/zones/${ZONE_ID}/custom_hostnames?per_page=1")
CODE=$(printf '%s' "$RESP" | tail -n1)
[ "$CODE" = "200" ] || die "custom_hostnames read failed (HTTP $CODE) — token lacks SSL/DNS scopes?"
log "  - custom hostnames read OK"

# -------------------------------------------------------------- apply safely
BACKUP="${ENVF}.bak.${STAMP}.tokenrotate"
install -m 600 "$ENVF" "$BACKUP"
log "backed up current env to $BACKUP (mode 600)"

TMP_ENVF=$(mktemp "${ENVF}.tmp.XXXXXX")
chmod 600 "$TMP_ENVF"
if grep -qE '^CLOUDFLARE_API_TOKEN=' "$ENVF"; then
  awk -v t="$NEW_TOKEN" '
    /^CLOUDFLARE_API_TOKEN=/ { print "CLOUDFLARE_API_TOKEN=" t; next }
    { print }
  ' "$ENVF" > "$TMP_ENVF"
else
  cat "$ENVF" > "$TMP_ENVF"
  printf 'CLOUDFLARE_API_TOKEN=%s\n' "$NEW_TOKEN" >> "$TMP_ENVF"
fi
chown --reference="$ENVF" "$TMP_ENVF" 2>/dev/null || true
chmod 600 "$TMP_ENVF"
mv -f "$TMP_ENVF" "$ENVF"
unset NEW_TOKEN
log "env updated ($(grep -cE '^CLOUDFLARE_API_TOKEN=.' "$ENVF" || true) token line present)"

# ------------------------------------------------------- recreate consumers
if [ -d "$ROOT/current" ]; then
  log "recreating app/scheduler/horizon to load the new token ..."
  ( cd "$ROOT/current" && docker compose up -d --no-deps app scheduler horizon >/dev/null )
  log "waiting for app health ..."
  for _ in $(seq 1 60); do
    st=$(docker inspect --format '{{.State.Health.Status}}' fahimni-app-1 2>/dev/null || echo starting)
    [ "$st" = "healthy" ] && break
    sleep 3
  done
fi

# ------------------------------------------------------------- verify live
if docker ps --format '{{.Names}}' | grep -q '^fahimni-app-1$'; then
  log "live verification:"
  docker exec fahimni-app-1 php artisan fahimni:cloudflare-saas status || true
fi

cat <<EOF

$(log "rotation applied")
Next, delete the OLD token in the Cloudflare dashboard so it can no longer be used:
  My Profile -> API Tokens -> find the previous fahimni token -> Delete
If anything is wrong, roll back with:
  install -m 600 "$BACKUP" "$ENVF"
  ( cd "$ROOT/current" && docker compose up -d --no-deps app scheduler horizon )
EOF
