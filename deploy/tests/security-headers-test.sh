#!/usr/bin/env bash
# Guard the security-header contract that silently broke production media.
#
# The bug this pins: when a response carries MORE THAN ONE Permissions-Policy
# header the browser intersects them, so the MOST restrictive value wins. The
# nginx edge (and the Caddy edge) published a stricter policy than the
# Next.js app — `camera=()`, `microphone=()`, `payment=()` — which disabled
# live-class video/microphone and Freemius checkout even though the app
# explicitly allowed them. The platform also leaked `X-Powered-By` on both the
# Next.js HTML and the PHP API responses.
#
# This test is static (reads the shipped files) so it runs in CI without Docker.
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd -P)"

fails=0
ok()  { printf 'ok   %s\n' "$1"; }
bad() { printf 'FAIL %s\n' "$1" >&2; fails=$((fails + 1)); }

NGINX_CONF="$ROOT_DIR/docker/nginx/default.conf"
NGINX_TLS_EXAMPLE="$ROOT_DIR/docker/nginx/optional/https.conf.example"
NEXT_CONF="$ROOT_DIR/frontend/next.config.ts"
PHP_INI="$ROOT_DIR/docker/php/php.ini"

# ── 1. The app policy is the single source of truth ────────────────────────
app_policy="$(sed -n "s/.*key: 'Permissions-Policy', value: '\(.*\)' },.*/\1/p" "$NEXT_CONF")"
if [[ -n "$app_policy" ]]; then
  ok "app Permissions-Policy found in next.config.ts"
else
  bad "could not read Permissions-Policy from frontend/next.config.ts"
fi

# ── 2. nginx publishes the SAME policy (no stricter intersection) ──────────
nginx_policy="$(sed -n "s/.*add_header Permissions-Policy '\(.*\)' always;.*/\1/p" "$NGINX_CONF")"
if [[ -n "$nginx_policy" && "$nginx_policy" == "$app_policy" ]]; then
  ok "nginx Permissions-Policy matches the app policy"
else
  bad "nginx Permissions-Policy differs from the app policy
      nginx: ${nginx_policy:-<none>}
      app:   ${app_policy:-<none>}"
fi

tls_policy="$(sed -n "s/.*add_header Permissions-Policy '\(.*\)' always;.*/\1/p" "$NGINX_TLS_EXAMPLE")"
if [[ -n "$tls_policy" && "$tls_policy" == "$app_policy" ]]; then
  ok "optional TLS example Permissions-Policy matches the app policy"
else
  bad "docker/nginx/optional/https.conf.example Permissions-Policy differs from the app policy
      example: ${tls_policy:-<none>}
      app:     ${app_policy:-<none>}"
fi

# ── 3. No shipped directive may re-disable camera/microphone/payment ───────
while IFS= read -r line; do
  [[ -z "$line" ]] && continue
  if [[ "$line" =~ camera=\(\) || "$line" =~ microphone=\(\) || "$line" =~ payment=\(\) ]]; then
    bad "a Permissions-Policy directive still disables media/payment: $line"
  fi
done < <(grep -RInE '^[[:space:]]*(add_header[[:space:]]+Permissions-Policy|Permissions-Policy[[:space:]])' \
  "$ROOT_DIR/docker" "$ROOT_DIR/deploy/caddy" 2>/dev/null || true)
ok "no directive disables camera/microphone/payment"

# The Caddy edge must not set Permissions-Policy at all (the app owns it).
if grep -RInE '^[[:space:]]*Permissions-Policy[[:space:]]' "$ROOT_DIR/deploy/caddy" >/dev/null 2>&1; then
  bad "a Caddy fragment still sets Permissions-Policy (would override the app)"
else
  ok "Caddy fragments leave Permissions-Policy to the app"
fi

# ── 4. X-Powered-By must never reach the browser ───────────────────────────
if grep -q 'poweredByHeader: false' "$NEXT_CONF"; then
  ok "Next.js does not advertise its version (poweredByHeader: false)"
else
  bad "frontend/next.config.ts is missing poweredByHeader: false"
fi

if grep -q 'proxy_hide_header X-Powered-By;' "$NGINX_CONF" \
  && grep -q 'fastcgi_hide_header X-Powered-By;' "$NGINX_CONF"; then
  ok "nginx strips X-Powered-By from proxied and FastCGI responses"
else
  bad "nginx must hide X-Powered-By on both the proxy and FastCGI paths"
fi

if grep -qE '^[[:space:]]*expose_php[[:space:]]*=[[:space:]]*Off' "$PHP_INI"; then
  ok "PHP does not advertise its version (expose_php = Off)"
else
  bad "docker/php/php.ini must set expose_php = Off"
fi

# The live edge is the standalone Fahimni Caddy container, so it must strip
# X-Powered-By itself (nginx hides it on its own path, but the edge never sees
# the production traffic directly).
if grep -qE '^[[:space:]]*-X-Powered-By[[:space:]]*$' "$ROOT_DIR/deploy/caddy/fahimni-standalone.caddy"; then
  ok "Caddy strips X-Powered-By at the edge"
else
  bad "deploy/caddy/fahimni-standalone.caddy must remove X-Powered-By (add '-X-Powered-By')"
fi

if [[ "$fails" -ne 0 ]]; then
  printf '\n%d security-header check(s) failed\n' "$fails" >&2
  exit 1
fi

printf '\nall security-header checks passed\n'
