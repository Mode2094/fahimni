#!/usr/bin/env bash
# DEPRECATED — kept only as a forwarder.
#
# The host's public TLS terminator is the LooksLoop Caddy *container*
# (Docker), not a systemd `caddy` unit, and its Caddyfile is bind-mounted
# read-only from an immutable release path. Installing an `import` line and
# `systemctl reload caddy` (what this script used to do) does not apply here.
#
# All real work now lives in caddy-docker-integrate.sh, which merges the
# additive vhosts into the running container's config over the Caddy admin API.
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"

printf '%s\n' \
  'note: caddy-activate.sh is deprecated; forwarding to caddy-docker-integrate.sh' >&2

exec "$SCRIPT_DIR/caddy-docker-integrate.sh" "$@"
