# Load Testing (k6) — SHAHEAD LMS

Real load tests for the platform. The tests exercise the **actual deployed
API** (never production) and produce machine-readable JSON results plus the
k6 end-of-run summary.

## Prerequisites

- Docker (k6 runs in a container — no local install needed).
- The stack must be up (`docker compose up -d`) with migrations + seeds done.
- Test accounts pre-seeded (see env vars below) — never use production accounts.

## Quick start

```bash
# Health endpoints
./tests/load/run.sh health

# Authenticated user flow (needs TEST_USER_EMAIL / TEST_USER_PASSWORD)
TEST_USER_EMAIL=student@example.test TEST_USER_PASSWORD=secret ./tests/load/run.sh auth-user

# Capacity discovery (ramp to 1000 VUs)
VUS=1000 ./tests/load/run.sh concurrent-users
```

## Scenarios

| File | Scenario | What it does |
| ---- | ------- | ------------ |
| `health.js`          | A  | `/up`, `/api/health/live`, `/api/health/ready` |
| `public-api.js`      | B  | Public endpoints that exist in the API |
| `auth-user.js`       | C  | Login → me → dashboard → courses → search |
| `teacher.js`         | D  | Teacher dashboard, courses, students, lessons, analytics |
| `student.js`         | E  | Mobile login → home, courses, site, profile |
| `tenant-isolation.js` | F  | **Fails on ANY cross-tenant data leak** |
| `queue.js`           | G  | Reads real queue signals (readiness, pending, failed, Horizon) |
| `upload.js`          | H  | Small in-memory upload through the real upload endpoint |
| `database.js`        | I  | Read-heavy API load + DB metrics via admin monitoring summary |
| `concurrent-users.js`| J  | Staged ramp 10 → 25 → 50 → 100 → 250 → 500 → 1000 |

## Environment variables

| Variable | Purpose |
| -------- | ------- |
| `BASE_URL`  | Base URL (default `http://nginx:80` inside the docker network) |
| `API_URL`   | API base (default `http://nginx:80/api/v1`) |
| `PLATFORM_SLUG` | Public site slug for public scenarios |
| `TEST_USER_EMAIL` / `TEST_USER_PASSWORD` | Authenticated scenarios |
| `TEST_TENANT_ID` | Tenant context header for tenant-scoped calls |
| `K6_ADMIN_EMAIL` / `K6_ADMIN_PASSWORD` | DB-metrics scenario (admin token) |
| `VUS` / `STAGE_MIN` / `RATE` / `DURATION` | Load shape tuning |

## How to run against a staging VPS

1. Deploy the stack to a staging VPS (same image tags, same seeds).
2. Open the firewall for the load-test machine (or run k6 on the VPS).
3. Set `BASE_URL=https://staging.yourdomain` and `API_URL=https://staging.yourdomain/api/v1`.
4. Run the scenarios and save the JSON outputs into `tests/load/results/`.

**NEVER run load tests against production.** The point is to characterise the
staging box so you can predict production behaviour before launch.

## Reading the results

Each run writes `<scenario>-<timestamp>.json` into `tests/load/results/`.
The k6 end-of-run summary gives you: VUs, iteration rate (RPS), request
durations (avg/p95/p99), success rate, and checks. Correlate those against the
server-side numbers captured by the platform's own monitoring:

- Host CPU/RAM/disk/load (`monitoring:metrics` or the admin monitoring page)
- PostgreSQL connections / slow queries / locks / cache-hit ratio
- Redis memory / evictions / clients / ops
- Queue pending/failed/Horizon status
- ffmpeg process count per live stream

## Capacity definition (guidelines — adjust from real results)

| Band | Definition |
| ---- | ---------- |
| SAFE    | p95 < 500ms, errors < 1%, CPU < 70%, RAM < 80% |
| WARNING | p95 500ms–1s, CPU 70–90% |
| CRITICAL| p95 > 2s, 5xx > 2%, CPU > 90%, RAM > 90%, queue continuously growing |

These are not laws. Use them as a baseline for the first capacity report.