#!/usr/bin/env bash
#
# Cloudflare origin firewall for the shared fahimni.me VPS (187.127.87.101).
#
# ██  DRY RUN BY DEFAULT. IT DOES NOT TOUCH UFW UNLESS YOU PASS --apply.  ██
#
# WHY THIS IS NOT AUTOMATIC
# -------------------------
# This host is shared: the same Caddy container terminates TLS for BOTH
# fahimni.me and LooksLoop. Restricting inbound 80/443 to Cloudflare IP ranges
# is only safe if *every* vhost on the host is fronted by Cloudflare. If
# LooksLoop is ever reachable without Cloudflare, denying non-CF traffic will
# take it offline. That is a business decision, not a routine script.
#
# The script therefore:
#   * prints the exact rules and exits 0 by default (dry run),
#   * refuses to apply unless CONFIRM_LOOKSLOOP_OK=yes is also set,
#   * always allows SSH from ADMIN_SSH_IP before anything else, and never
#     issues a blanket `ufw deny` for port 22,
#   * is idempotent (safe to re-run).
#
# Usage
# -----
#   ADMIN_SSH_IP=203.0.113.4 ./deploy/scripts/cloudflare-ufw.sh              # dry run
#   sudo ADMIN_SSH_IP=203.0.113.4 CONFIRM_LOOKSLOOP_OK=yes \
#        ./deploy/scripts/cloudflare-ufw.sh --apply                          # apply
#
# Rollback (always possible over SSH):
#   sudo ufw disable
#
# Keep the Cloudflare ranges in sync from https://www.cloudflare.com/ips/

set -euo pipefail

APPLY=0
[[ "${1:-}" == "--apply" ]] && APPLY=1

if [[ -z "${ADMIN_SSH_IP:-}" ]]; then
  echo "ERROR: set ADMIN_SSH_IP to the admin's SSH source IP/CIDR (e.g. 203.0.113.4)." >&2
  echo "       Refusing to touch the firewall without a known SSH source." >&2
  exit 1
fi

# Cloudflare edge ranges (IPv4). IPv6 is intentionally left out of the allow
# list: add the v6 ranges from cloudflare.com/ips-v6 only after verifying they
# are in use, otherwise you may blackhole v6 clients.
CF_V4=(
  173.245.48.0/20 103.21.244.0/22 103.22.200.0/22 103.31.4.0/22
  141.101.64.0/18 108.162.192.0/18 190.93.240.0/20 188.114.96.0/20
  197.234.240.0/22 198.41.128.0/17 162.158.0.0/15 104.16.0.0/13
  104.24.0.0/14 172.64.0.0/13 131.0.72.0/22
)

print_plan() {
  echo "Planned ufw changes:"
  echo "  1. allow from ${ADMIN_SSH_IP} to any port 22 proto tcp     # SSH first, always"
  for net in "${CF_V4[@]}"; do
    echo "  2. allow from ${net} to any port 80,443 proto tcp"
  done
  echo "  3. default deny incoming   (outgoing stays allowed)"
  echo "  4. ufw enable"
  echo
  echo "Rollback: sudo ufw disable"
}

if [[ "$APPLY" -eq 0 ]]; then
  echo "== DRY RUN — nothing will change. Re-run with --apply to apply. =="
  echo
  print_plan
  exit 0
fi

if [[ "$(id -u)" -ne 0 ]]; then
  echo "ERROR: --apply must run as root (use sudo)." >&2
  exit 1
fi

if [[ "${CONFIRM_LOOKSLOOP_OK:-}" != "yes" ]]; then
  echo "ERROR: refusing to apply. This host also serves LooksLoop via the same Caddy." >&2
  echo "       If (and only if) LooksLoop is fully behind Cloudflare, re-run with:" >&2
  echo "         CONFIRM_LOOKSLOOP_OK=yes" >&2
  exit 1
fi

command -v ufw >/dev/null || { echo "ERROR: ufw is not installed." >&2; exit 1; }

print_plan
echo

# SSH FIRST — the one rule that must exist before any deny.
ufw allow from "${ADMIN_SSH_IP}" to any port 22 proto tcp

for net in "${CF_V4[@]}"; do
  ufw allow from "$net" to any port 80,443 proto tcp
done

ufw default allow outgoing
ufw default deny incoming
ufw --force enable

echo
echo "Applied. Verify from another session BEFORE closing this one:"
echo "  ssh ${ADMIN_SSH_IP:-(admin)}   # must still work"
echo "  curl -sI https://fahimni.me | head -1   # must be 200/30x, not a timeout"
echo "Rollback if anything is wrong: sudo ufw disable"
