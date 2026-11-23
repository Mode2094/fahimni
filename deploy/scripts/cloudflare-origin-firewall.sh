#!/usr/bin/env bash
#
# Cloudflare origin firewall — Docker-aware variant for fahimni.me (187.127.87.101).
#
# ── WHY NOT ufw ──────────────────────────────────────────────────────────────
# This host publishes 80/443 from a Docker container (fahimni-edge-caddy).
# Docker installs its own rules in the FORWARD chain (DOCKER-USER →
# DOCKER-FORWARD) that are evaluated BEFORE ufw's forward chains, so a ufw rule
# can NOT restrict a Docker-published port (verified on 2026-11-19: the FORWARD
# chain reaches DOCKER-FORWARD/ACCEPT before ufw-before-forward). The only hook
# Docker exposes for this is the DOCKER-USER chain, traversed first for every
# forwarded packet. This script uses that, and never touches INPUT/ufw.
#
# ── WHAT IT DOES ─────────────────────────────────────────────────────────────
#   * Allows inbound 443 (tcp + udp) on the public interface ONLY from
#     Cloudflare's published ranges; DROPs all other traffic to 443.
#   * Leaves :22 (SSH) and :80 alone. :80 stays open so Caddy can still answer
#     Let's Encrypt HTTP-01 challenges and the HTTP→HTTPS redirect; it never
#     proxies to nginx, so it is not a spoofing path for CF-Connecting-IP.
#   * Only pins the *public* interface (-i eth0), so container-to-container
#     traffic (Caddy → nginx, over the Docker bridge) is unaffected.
#
# This is the §3 firewall required before/with the §5 real-client-IP change
# (see docs/CLOUDFLARE_VPS_HARDENING.md): without it, a direct-to-origin
# request could forge CF-Connecting-IP and nginx would trust it.
#
# Idempotent — re-running removes its own rules (tagged comment `fahimni-cf`)
# and reinstalls them.
#
# Usage:
#   ./cloudflare-origin-firewall.sh            # dry run: show the plan
#   sudo ./cloudflare-origin-firewall.sh status
#   sudo ./cloudflare-origin-firewall.sh apply
#   sudo ./cloudflare-origin-firewall.sh remove
#
set -euo pipefail

TAG="fahimni-cf"
IFACE="${IFACE:-$(ip route get 1.1.1.1 2>/dev/null | sed -n 's/.* dev \([^ ]*\).*/\1/p' | head -1)}"
IFACE="${IFACE:-eth0}"

# Cloudflare edge ranges — keep in sync with https://www.cloudflare.com/ips/
CF4=(
  173.245.48.0/20 103.21.244.0/22 103.22.200.0/22 103.31.4.0/22
  141.101.64.0/18 108.162.192.0/18 190.93.240.0/20 188.114.96.0/20
  197.234.240.0/22 198.41.128.0/17 162.158.0.0/15 104.16.0.0/13
  104.24.0.0/14 172.64.0.0/13 131.0.72.0/22
)
CF6=(
  2400:cb00::/32 2606:4700::/32 2803:f800::/32 2405:b500::/32
  2405:8100::/32 2a06:98c0::/29 2c0f:f248::/32
)

remove_rules() {
  local bin rules r
  for bin in iptables ip6tables; do
    "$bin" -N DOCKER-USER 2>/dev/null || true
    rules=$("$bin" -S DOCKER-USER 2>/dev/null | grep -F -- "--comment $TAG" || true)
    while IFS= read -r r; do
      [ -n "$r" ] || continue
      # shellcheck disable=SC2086
      $bin ${r/-A /-D } || true
    done <<< "$rules"
  done
}

install_rules() {
  local net
  for net in "${CF4[@]}"; do
    iptables -A DOCKER-USER -i "$IFACE" -s "$net" -p tcp -m comment --comment "$TAG" -m tcp --dport 443 -j RETURN
    iptables -A DOCKER-USER -i "$IFACE" -s "$net" -p udp -m comment --comment "$TAG" -m udp --dport 443 -j RETURN
  done
  for net in "${CF6[@]}"; do
    ip6tables -A DOCKER-USER -i "$IFACE" -s "$net" -p tcp -m comment --comment "$TAG" -m tcp --dport 443 -j RETURN
    ip6tables -A DOCKER-USER -i "$IFACE" -s "$net" -p udp -m comment --comment "$TAG" -m udp --dport 443 -j RETURN
  done
  # The default deny, appended AFTER the allows above.
  iptables  -A DOCKER-USER -i "$IFACE" -p tcp -m comment --comment "$TAG" -m tcp --dport 443 -j DROP
  iptables  -A DOCKER-USER -i "$IFACE" -p udp -m comment --comment "$TAG" -m udp --dport 443 -j DROP
  ip6tables -A DOCKER-USER -i "$IFACE" -p tcp -m comment --comment "$TAG" -m tcp --dport 443 -j DROP
  ip6tables -A DOCKER-USER -i "$IFACE" -p udp -m comment --comment "$TAG" -m udp --dport 443 -j DROP
}

plan() {
  echo "Interface : $IFACE      tag: $TAG"
  echo "IPv4 ranges: ${#CF4[@]}    IPv6 ranges: ${#CF6[@]}"
  echo
  echo "Planned DOCKER-USER rules:"
  echo "  * RETURN 443/tcp,udp  from each Cloudflare range   (~$(((${#CF4[@]} + ${#CF6[@]}) * 2)) rules)"
  echo "  * DROP   443/tcp,udp  from everywhere else          (4 rules)"
  echo "  * :22 (SSH) and :80 (ACME) are NOT touched."
}

case "${1:-}" in
  ""|--dry-run|dry-run)
    echo "== DRY RUN — nothing changes. Use 'apply' to apply, 'remove' to roll back. =="
    plan
    ;;
  apply)
    [[ "$(id -u)" -eq 0 ]] || { echo "ERROR: apply needs root (sudo)." >&2; exit 1; }
    command -v iptables >/dev/null || { echo "ERROR: iptables missing." >&2; exit 1; }
    remove_rules
    install_rules
    echo "Applied on $IFACE. Current rules:"
    iptables -S DOCKER-USER | grep -F -- "--comment $TAG"
    ip6tables -S DOCKER-USER | grep -F -- "--comment $TAG"
    echo
    echo "Verify:"
    echo "  direct  : curl -sk --resolve fahimni.me:443:187.127.87.101 https://fahimni.me/  # should hang/timeout"
    echo "  via CF  : curl -sI https://fahimni.me/ | head -1                              # must be 200/30x"
    echo "Rollback: $0 remove"
    ;;
  remove)
    [[ "$(id -u)" -eq 0 ]] || { echo "ERROR: remove needs root (sudo)." >&2; exit 1; }
    remove_rules
    echo "Removed all '$TAG' rules."
    ;;
  status)
    echo "== ipv4 =="; iptables  -S DOCKER-USER | grep -F -- "--comment $TAG" || echo "(none)"
    echo "== ipv6 =="; ip6tables -S DOCKER-USER | grep -F -- "--comment $TAG" || echo "(none)"
    ;;
  *)
    echo "Usage: $0 [apply|remove|status]" >&2
    exit 2
    ;;
esac
