# Capacity Testing — SHAHEAD LMS

This document explains how to measure what the platform can actually handle and
how to read the results. It does not predict numbers — it tells you how to
measure them.

## 1. Vocabulary

- **Concurrent users (VUs):** active clients making requests at a given moment.
- **Requests per second (RPS):** total requests / elapsed time. 100 VUs doing 1
  request every 10s is 10 RPS, not 100 RPS.
- **Active sessions:** authenticated sessions that exist (may be idle).
- **Latency percentiles:** p50/p95/p99 of request duration — the shape matters
  more than the average.

## 2. Setup

The stack must run somewhere with the same image tags and seeds as production.
Use the `loadtest` Docker Compose profile — it runs k6 without installing it:

```bash
docker compose up -d
docker compose --profile loadtest run --rm loadtest 2>/dev/null || true
# then run scenarios:
./tests/load/run.sh health
```

Configure the target with env: `BASE_URL`, `API_URL`, test-account credentials.

## 3. Environments

| Environment | What it proves | Never do |
| ----------- | -------------- | -------- |
| Local (Docker Desktop) | End-to-end wiring, scenario validity | Use results as capacity numbers |
| Staging VPS (same spec as prod) | Real capacity on real hardware | — |
| Production | Only light smoke tests after deploys | Load testing (ever) |

Docker Desktop shares the host CPU/RAM and has different network overhead —
it is a functional test bed, not a capacity bed.

## 4. Scenarios

See `tests/load/scenarios/*.js` for the 10 scenarios (A–J) covering health,
public API, authenticated users, teachers, students, tenant isolation,
queues, uploads, database reads, and staged concurrency.

## 5. Capacity definitions

| Band | Meaning |
| ---- | ------- |
| **SAFE** | p95 < 500 ms, errors < 1%, CPU < 70%, RAM < 80% |
| **WARNING** | p95 500 ms–1 s, CPU 70–90% |
| **CRITICAL / BREAKING** | p95 > 2 s, 5xx > 2%, CPU > 90%, RAM > 90%, queue continuously growing |

These are starting points, not laws.

## 6. Running a staged capacity test

```bash
VUS=1000 ./tests/load/run.sh concurrent-users
```

This ramps 10 → 25 → 50 → 100 → 250 → 500 → 1000 VUs. The run never aborts —
the goal is to observe degradation, not to fail a threshold. The JSON output
lands in `tests/load/results/` for later comparison.

## 7. Server-side metrics during the test

Capture these from the platform's own monitoring while a scenario runs:

- Host CPU/RAM/disk/load (`monitoring:metrics` or admin monitoring page)
- PostgreSQL connections, slow queries, locks, cache-hit ratio
- Redis memory, evictions, clients, ops/s
- Queue depth, failed jobs, Horizon status
- ffmpeg process count (live streaming scenarios)

## 8. Reporting template

```text
10 users:   PASS
25 users:   PASS
50 users:   PASS
100 users:  PASS
250 users:  WARNING (p95 x ms, CPU y%)
500 users:  FAIL (5xx z%, p95 > 2s)

Recommended safe concurrent users:  X  (from the last PASS stage)
Recommended peak:                  Y  (from the last WARNING stage)
Expected bottleneck:                PostgreSQL connections / PHP-FPM / CPU / RAM
```

Do not guess X/Y — take them from the measurements.

## 9. What to do with the numbers

- Compare against the VPS proposal (e.g. 4 vCPU / 16 GB / 200 GB).
- Tune `pm.max_children` / Horizon worker count only after you see where the
  first bottleneck appears.
- Re-run after any significant architecture change to regenerate the report.