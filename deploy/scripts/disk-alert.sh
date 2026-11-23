#!/usr/bin/env bash
# fahimni-disk-alert — warn when the root filesystem crosses a threshold.
#
# Installed on the host as /usr/local/bin/fahimni-disk-alert and run hourly
# from /etc/cron.d/fahimni-maintenance. No mail agent is installed on this
# host, so the alert is written to a log and to syslog
# (journalctl -t fahimni-disk); it also uses mail if one appears.
set -euo pipefail
THRESH="${FAHIMNI_DISK_THRESHOLD:-75}"
LOG="/var/log/fahimni-disk-alert.log"
USE="$(df -P / | awk 'NR==2{gsub("%","",$5); print $5}')"
AVAIL="$(df -Ph / | awk 'NR==2{print $4}')"
if (( USE >= THRESH )); then
  MSG="$(date -u +%Y-%m-%dT%H:%M:%SZ) WARNING: disk usage ${USE}% >= ${THRESH}% on / (avail ${AVAIL})"
  echo "$MSG" >> "$LOG"
  logger -t fahimni-disk "$MSG" 2>/dev/null || true
  if command -v mail >/dev/null 2>&1; then echo "$MSG" | mail -s "Fahimni disk ${USE}%" root || true; fi
elif [[ -n "${FAHIMNI_DISK_VERBOSE:-}" ]]; then
  echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) ok: ${USE}% < ${THRESH}%" >> "$LOG"
fi
