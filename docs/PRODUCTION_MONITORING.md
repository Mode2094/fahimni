# Production Monitoring — SHAHEAD LMS

This document describes how the platform observes itself in production.

## 1. Components

The monitoring stack is 100% inside the repository and runs with the app. It
needs no external vendor to operate (external providers are optional extras).

| Layer | What it answers | Where it lives |
| ----- | --------------- | -------------- |
| Health endpoints | Is the app alive / ready? | `/up`, `/api/health`, `/api/health/live`, `/api/health/ready` |
| Request metrics | Traffic, latency, errors, slow requests | `MetricsMiddleware` + Redis counters |
| Snapshots | Host/DB/Redis/queue/storage/streaming/security every 5 min | `monitoring:collect` → `monitoring_snapshots` |
| Threshold checks | Alerts for crossing thresholds | `monitoring:check` → `monitoring_alerts` + channels |
| Scheduler heartbeat | Is `schedule:work` alive? | heartbeat file written every minute |
| Client errors | Frontend runtime/HTTP/proxy errors | `frontend_errors` + Next.js `lib/monitoring.ts` |
| Admin dashboard | Human-readable view for super-admins | `/admin/monitoring` in the Next.js app |
| Error tracking | Optional Sentry integration (opt-in) | `SENTRY_LARAVEL_DSN` + docs below |

## 2. Health endpoints

| Endpoint | Semantics | Returns |
| -------- | --------- | ------- |
| `/up` | Liveness (process alive) | 200 `OK` (framework default) |
| `/api/health/live` | Liveness (no dependency checks) | 200 `{"status":"ok",...}` |
| `/api/health/ready` | Readiness (DB + Redis + filesystem + queue + config) | 200 `{"status":"ok", checks:{...}}` or 503 |
| `/api/health` | Legacy aggregate (kept for compatibility) | 200/503 with booleans |

Readiness does not block on heavy work: DB is probed with `SELECT 1`, Redis with
`PING`, filesystem with a 8-byte temp file, Horizon via a 30s-cached
`artisan horizon:status`. The scheduler heartbeat is reported (not a hard fail).

## 3. Alerts

Alerts are created by `monitoring:check` (scheduled every 5 minutes) and deduplicated
by key in `monitoring_alerts`. Channels (all opt-in via env):

| Channel | Env | Notes |
| ------- | --- | ----- |
| Log      | — | always (structured `storage/logs/monitoring.log`) |
| Table    | — | always (`monitoring_alerts`) |
| Email    | `MONITORING_ALERT_MAIL_TO` | one plaintext message per new alert |
| Webhook  | `MONITORING_ALERT_WEBHOOK_URL` + optional `MONITORING_ALERT_WEBHOOK_SECRET` | JSON POST, HMAC-signed when a secret is set |

## 4. Thresholds

All thresholds are in `config/monitoring.php` and overridable via env.

| Signal | Warning | Critical |
| ------ | ------- | -------- |
| CPU | > 70% | > 90% sustained (15 min window) |
| RAM | > 75% | > 90% |
| Root disk | > 70% | > 85% |
| PostgreSQL connections | > 70% of max | > 90% of max |
| Redis memory | > 70% of maxmemory | > 90% of maxmemory |
| Queue pending | > 500 | > 2000 or growing N consecutive checks |
| Failed jobs | > 5 | > 20 |
| SSL expiry | < 30 days | < 7 days |
| Backup age | > 12 h | > 24 h or none exists |
| ffmpeg streams | >= 2 active | at `MAX_CONCURRENT_FFMPEG_STREAMS` |

## 5. Redis-specific risk: `allkeys-lru`

Redis is used for cache, sessions, queue and Horizon. The compose command sets
`maxmemory-policy allkeys-lru` (evicts any key under memory pressure). This means
**sessions can be evicted** when memory is exhausted, logging users out. The
monitoring stack reports evictions (see `redis.evictions` alert) so you can
decide whether to raise maxmemory or switch sessions to the database before it
becomes a user-facing incident.

## 6. Error tracking (Sentry, optional)

- Laravel: the SDK is installed. Set `SENTRY_LARAVEL_DSN` and the SDK reports
  exceptions automatically. The app runs normally with an empty DSN (verified in
  production startup checks). Configure via env:
  - `SENTRY_LARAVEL_DSN` (required to activate)
  - `SENTRY_ENVIRONMENT` (defaults to APP_ENV)
  - `SENTRY_TRACES_SAMPLE_RATE` / `SENTRY_SAMPLE_RATE` for performance tracing
  - `SENTRY_SEND_DEFAULT_PII=false` (default) — no PII is sent
- Next.js: no dependency is bundled. If you install `@sentry/nextjs` and set
  `NEXT_PUBLIC_SENTRY_DSN`, the app's own client monitor (`lib/monitoring.ts`)
  yields to Sentry. Otherwise the built-in client monitor is used (dependency-free).

Never send secrets, tokens, cookies, authorization headers or raw request bodies.

## 7. Uptime monitoring (external, optional)

Configure any provider to poll these URLs every 1 minute with alerts after 3
consecutive failures + a recovery notification:

```
https://api.yourdomain/up
https://api.yourdomain/api/health
https://app.yourdomain
```

Providers that work without vendor lock-in: UptimeRobot, BetterStack Uptime,
Healthchecks.io, Hetrix, StatusCake, Grafana Cloud Synthetic Monitoring.

## 8. Secure by design

- Admin endpoints are super-admin-only (`admin` middleware) and rate-limited.
- Public endpoints expose only aggregate/derived values.
- No secrets are ever logged or exposed by the monitoring layer.
- The private key is never read (cert expiry is parsed from the public cert).
- Frontend ingest is size-capped, sanitized and rate-limited.