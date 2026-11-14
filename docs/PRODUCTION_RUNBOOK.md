# Production Runbook — SHAHEAD LMS

Day-to-day operations for the platform on a clean Ubuntu 24.04 VPS.

> For the isolated staging/production Docker layout, DNS/Caddy steps,
> one-command deploy/rollback and the off-site backup/restore-drill scripts
> introduced for launch, see `deploy/README.md`. This runbook remains the
> day-to-day operations guide.

## 1. Stack layout

Docker Compose services: `nginx`, `app` (PHP-FPM), `migrate`, `scheduler`,
`horizon`, `postgres`, `redis`. The Next.js frontend runs separately (same VPS
or a CDN/host) and talks to the API over HTTPS.

## 2. First deployment (one-time)

1. Provision the VPS (Ubuntu 24.04, 4 vCPU / 16 GB / 200 GB).
2. Harden SSH (key-only), enable UFW (22/80/443), install Docker + Compose v2.
3. Clone the repository (without `.env`), generate a fresh `.env` (see
   `.env.example`), generate a strong `APP_KEY` + `VIDEO_SIGNING_KEY` + DB/Redis
   passwords, configure SMTP, domains, CORS, Sanctum domains.
4. Configure DNS: `api.domain.com` and `app.domain.com` → VPS IP; teacher CNAMEs
   → `app.domain.com`.
5. Obtain certificates (Let's Encrypt or Cloudflare origin cert) into `./certs/`.
6. `docker compose build && docker compose up -d` (migrate runs automatically).
7. Configure Bunny/Cloudflare credentials in `admin/media`.
8. Enable automatic backups in `admin/backups` and configure rclone for off-site.
9. Build and start the Next.js app with production env (`npm run build && npm start`).
10. Run `./scripts/verify-production.sh` and the smoke-test checklist.

## 3. Daily health check

1. `./scripts/verify-production.sh` or the admin monitoring page.
2. Confirm the scheduler heartbeat is fresh (`monitoring:scheduler` in the page).
3. Check the newest backup age and its verification flag.

## 4. Deploying a release

1. Build images with the new code/tag: `docker compose build app scheduler horizon`.
2. `docker compose up -d --remove-orphans` (migrate + seed run automatically).
3. Run `./scripts/verify-production.sh`.
4. Rebuild and restart the frontend if the frontend changed.

## 5. Upgrading resources

1. Before upgrading RAM/CPU: review the last 30 days of monitoring snapshots.
2. Only raise limits after evidence (see CAPACITY_TESTING.md).

## 6. Common commands

```bash
docker compose ps                     # container states
docker compose logs --tail=100 app    # app logs
docker compose logs --tail=100 nginx  # web server logs
docker compose exec app php artisan schedule:list   # scheduled tasks
docker compose exec app php artisan monitoring:metrics --json  # live snapshot
docker compose exec app php artisan monitoring:check       # alert check now
docker compose exec app php artisan horizon:status         # queue workers
docker compose exec postgres psql -U lms -c 'select 1'    # DB probe
```

## 7. Backups

- Automatic daily backups (7 retained, encrypted) live in `backups_data`.
- Off-site sync via rclone is mandatory before public launch — configure
  `BACKUP_RCLONE_REMOTE` and verify the sync job log.
- Test a restore at least weekly (INCIDENT_RESPONSE.md §15).

## 8. Monitoring recap

The platform ships its own observability stack; see PRODUCTION_MONITORING.md
for the full picture, CAPACITY_TESTING.md for capacity measurement, and
INCIDENT_RESPONSE.md for what to do when something breaks.