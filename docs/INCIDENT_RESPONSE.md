# Incident Response — SHAHEAD LMS

Playbooks for the most likely production incidents. Every playbook starts from
the same rule: **observe first, act second, verify third.** The monitoring
stack tells you what is wrong; these steps tell you what to do about it.

## 0. First 60 seconds

1. Read the monitoring page or run `docker compose ps` and `docker compose logs --tail=50 <service>`.
2. Check `/api/health` — is it 200 or 503? What does `checks` say?
3. If the site is down, check the host: `uptime`, `free -h`, `df -h`, `docker stats --no-stream`.
4. Decide: can you fix it fast, or do you need to restore service first (rollback / restart)?

## 1. Site/API down

1. `docker compose ps` — is nginx/app up?
2. `docker compose logs nginx --tail=100` — upstream errors? TLS errors?
3. `docker compose logs app --tail=100` — PHP fatal? OOM killer?
4. `docker compose restart app nginx` — quick restart first.
5. If containers won't start: check `docker compose config` and disk space (`df -h`).

## 2. CPU at 100%

1. `top` / `mpstat -P ALL 1` — which process consumes CPU?
2. `docker stats --no-stream` — which container?
3. If it is PHP-FPM: check slow requests (`monitoring:metrics` / admin page) — a spike in traffic or a bad query?
4. If it is ffmpeg: how many live streams are running? Are they legitimate? Enforce `MAX_CONCURRENT_FFMPEG_STREAMS`.
5. If it is PostgreSQL: a heavy report/backup job — check scheduler log.

## 3. RAM full / OOM

1. `free -h` — is swap being used? `dmesg | grep -i oom` — which process was killed?
2. Docker memory limits are in place; raise them only after evidence (monitoring snapshots).
3. If Redis is evicting keys (alert `redis.evictions`): consider raising `maxmemory` or moving sessions to the DB.

## 4. Redis down

1. `docker compose ps redis` + `redis-cli -a <REDIS_PASSWORD> ping`.
2. Restart: `docker compose restart redis`.
3. Impact: cache cleared, sessions lost, queue workers blocked — Horizon will reconnect automatically.
4. Long-term: check `monitoring/redis` for the reason (memory pressure? network?).

## 5. PostgreSQL down

1. `docker compose ps postgres` + `pg_isready -h 127.0.0.1 -U lms`.
2. Restart: `docker compose restart postgres`.
3. Check `/var/log/postgresql/` inside the container for corruption/full disk.
4. If the disk is full: stop write-heavy jobs, free space, verify backups.

## 6. Horizon stopped

1. `docker compose ps horizon` + `php artisan horizon:status` inside the container.
2. `docker compose restart horizon` (healthcheck will restart it automatically otherwise).
3. Check `monitoring_alerts` for `horizon` alerts.

## 7. Queue backlog rising

1. Admin monitoring page → Queue section — which queue is growing?
2. Check failed jobs — are jobs failing in a loop?
3. Inspect a failed job's exception (`admin/backups` has no failed-job view; use `php artisan queue:failed` inside the container).
4. If Horizon is healthy, add workers (`maxProcesses`) only after evidence.

## 8. Disk nearly full

1. `df -h` — where is it full? `du -sh /var/www/html/storage/app/*`.
2. Backups grow fastest — check retention settings (`admin/backups`).
3. Move backups off-box (rclone) — the monitoring alert `backup.offsite` tells you when it is not configured.

## 9. Backup failure

1. Admin monitoring page → Backup section — status and age of the newest backup.
2. Run a manual backup and watch the logs.
3. Verify a restore in a test environment before declaring the incident over.

## 10. SSL expiry

1. Run `openssl x509 -in certs/fullchain.pem -noout -enddate` on the host.
2. Renew with Let's Encrypt (`certbot renew`) or the provider's UI.
3. Reload nginx to pick up the new certs.

## 11. Bunny down

1. Bunny dashboard / status page. The platform degrades gracefully: the site and API keep working, only video playback fails.
2. Check `monitoring_alerts` for `provider` alerts and the media webhook log.
3. Consider switching the active provider in `admin/media` if Bunny is degraded for hours.

## 12. SMTP failing

1. Check the mail log and the SMTP settings in `.env`.
2. Verify with a test email from the admin panel.
3. Without SMTP: no verification/invitation/renewal emails — registration is effectively broken.

## 13. Security incident (brute force / abuse)

1. Admin monitoring page → Security section — failed logins, rate-limit hits, 403s, 5xx.
2. Check the audit log (`admin/logs`) for the affected accounts.
3. Block the source IPs at the firewall or Cloudflare, then investigate accounts.

## 14. Rollback

1. Keep the previous image tags: redeploy the last known-good tag.
2. If the failure is code-related: revert the release, rebuild, redeploy.
3. If the failure is data-related: restore from the newest verified backup into a staging DB, verify, then switch over.

## 15. Verifying a restore

1. Restore the dump into an empty database (`pg_restore`).
2. Run the smoke-test checklist (see PRODUCTION_RUNBOOK.md §Smoke tests).
3. Confirm backup verification flags (`monitoring/backup`).

## 16. Reading a load-test report

1. Find the stage where p95 crosses 500 ms — that is the WARNING point.
2. Find the stage where 5xx exceeds 2% or p95 exceeds 2 s — that is the BREAKING point.
3. Correlate with server metrics captured during the run (CPU/RAM/DB/Redis/queue).
4. The bottleneck is the resource that hit its ceiling first, not the one that looked busy last.