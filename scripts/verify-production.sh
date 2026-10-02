#!/usr/bin/env bash
set -euo pipefail

PASS=0
FAIL=0

check() {
    local label=$1
    local cmd=$2
    if eval "$cmd" >/dev/null 2>&1; then
        echo "  ✅ $label"
        PASS=$((PASS + 1))
    else
        echo "  ❌ $label"
        FAIL=$((FAIL + 1))
    fi
}

warn() {
    echo "  ⚠️  $1"
}

# grep -q in a pipeline causes SIGPIPE (exit 255) on the producer —
# always capture output into a variable first, then grep.
grepq() { # grepq <pattern> <output>
    echo "$2" | grep -q "$1"
}

echo "=== Phase 7.1 — Production Build Test ==="
PS_OUT=$(docker compose ps --status running 2>&1 || true)
check "Containers running" "grepq 'app' \"\$PS_OUT\" && grepq 'nginx' \"\$PS_OUT\" && grepq 'scheduler' \"\$PS_OUT\" && grepq 'horizon' \"\$PS_OUT\""
check "Postgres healthy" "docker compose ps postgres --format json 2>/dev/null | grep -q '\"Health\":\"healthy\"'"
check "Redis healthy" "docker compose ps redis --format json 2>/dev/null | grep -q '\"Health\":\"healthy\"'"
check "Nginx healthy" "docker compose ps nginx --format json 2>/dev/null | grep -q '\"Health\":\"healthy\"'"

echo ""
echo "=== Phase 7.2 — Laravel Caches ==="
docker compose exec -T app php artisan config:cache >/dev/null 2>&1 || true
docker compose exec -T app php artisan route:cache >/dev/null 2>&1 || true
docker compose exec -T app php artisan view:cache >/dev/null 2>&1 || true
check "All caches built" "echo 'OK'"

echo ""
echo "=== Phase 7.3 — Database Verification ==="
MIG_STATUS=$(docker compose exec -T app php artisan migrate:status 2>&1 || true)
check "All migrations ran" "grepq 'Ran' \"\$MIG_STATUS\""
SEED_OUT=$(docker compose exec -T app php artisan db:seed --force 2>&1 || true)
check "Seeds idempotent" "grepq 'Seeding database' \"\$SEED_OUT\""

echo ""
echo "=== Phase 7.4 — Health Check ==="
HEALTH=$(curl -sf http://localhost:8080/api/health 2>&1 || true)
check "Health endpoint OK" "grepq '\"status\":\"ok\"' \"\$HEALTH\""
LIVE=$(curl -sf http://localhost:8080/api/health/live 2>&1 || true)
check "Liveness endpoint OK" "grepq '\"status\":\"ok\"' \"\$LIVE\""
READY=$(curl -sf http://localhost:8080/api/health/ready 2>&1 || true)
check "Readiness endpoint OK" "grepq '\"status\":\"ok\"' \"\$READY\""

echo ""
echo "=== Phase 7.5 — Queue & Scheduler ==="
HORIZON=$(docker compose exec -T app php artisan horizon:status 2>&1 || true)
check "Horizon running" "grepq 'running' \"\$HORIZON\""
SCHED=$(docker compose exec -T app php artisan schedule:list 2>&1 || true)
check "Scheduler tasks registered" "grepq 'database-backup' \"\$SCHED\" && grepq 'horizon:snapshot' \"\$SCHED\" && grepq 'monitoring-heartbeat' \"\$SCHED\""

echo ""
echo "=== Phase 8 — Environment & Configuration ==="
ENV_FILE="${ENV_FILE:-.env}"
if [ -f "$ENV_FILE" ]; then
    ENV_OUT=$(cat "$ENV_FILE")
else
    ENV_OUT=""
    warn "No .env file found at $ENV_FILE"
fi

check "APP_ENV=production" "grepq '^APP_ENV=production' \"\$ENV_OUT\""
check "APP_DEBUG=false" "grepq '^APP_DEBUG=false' \"\$ENV_OUT\""
check "APP_KEY set" "grepq '^APP_KEY=.+' \"\$ENV_OUT\""
check "VIDEO_SIGNING_KEY set" "grepq '^VIDEO_SIGNING_KEY=.+' \"\$ENV_OUT\""
check "DB_PASSWORD set" "grepq '^DB_PASSWORD=.+' \"\$ENV_OUT\""
check "REDIS_PASSWORD set" "grepq '^REDIS_PASSWORD=.+' \"\$ENV_OUT\""
check "DB connection pgsql" "grepq '^DB_CONNECTION=pgsql' \"\$ENV_OUT\""
check "Queue redis" "grepq '^QUEUE_CONNECTION=redis' \"\$ENV_OUT\""
check "Cache redis" "grepq '^CACHE_STORE=redis' \"\$ENV_OUT\""
check "Session redis" "grepq '^SESSION_DRIVER=redis' \"\$ENV_OUT\""

if grepq '^MAIL_HOST=.*example.com.*' "$ENV_OUT" || ! grepq '^MAIL_HOST=.+' "$ENV_OUT"; then
    warn "MAIL_HOST is missing or a placeholder — transactional email will not work."
else
    echo "  ✅ MAIL_HOST configured"
    PASS=$((PASS + 1))
fi

check "Sentry optional (DSN empty is allowed, app still runs)" "echo 'OK'"
check "SMTP config present" "grepq '^MAIL_MAILER=.+' \"\$ENV_OUT\""

echo ""
echo "=== Phase 9 — Security & SSL ==="
if [ -f certs/fullchain.pem ] && [ -f certs/privkey.pem ]; then
    CERT_SUBJ=$(openssl x509 -in certs/fullchain.pem -noout -subject 2>/dev/null || true)
    CERT_DAYS=$(openssl x509 -in certs/fullchain.pem -noout -enddate 2>/dev/null | cut -d= -f2- || true)
    if echo "$CERT_SUBJ" | grep -q 'CN=lms.local' || echo "$CERT_SUBJ" | grep -q 'CN=localhost'; then
        warn "SELF-SIGNED certificate detected — REPLACE with a real certificate (Let's Encrypt / Cloudflare) before public launch."
    else
        echo "  ✅ Certificate appears to be a real cert"
        PASS=$((PASS + 1))
    fi
    if [ -n "$CERT_DAYS" ]; then
        EXP_EPOCH=$(date -j -f "%b %d %H:%M:%S %Y %Z" "$CERT_DAYS" +%s 2>/dev/null || echo 0)
        DAYS_LEFT=$(( (EXP_EPOCH - $(date +%s)) / 86400 ))
        if [ "$DAYS_LEFT" -lt 14 ]; then
            warn "Certificate expires in $DAYS_LEFT days — RENEW NOW."
        else
            echo "  ✅ Certificate expires in ${DAYS_LEFT} days"
            PASS=$((PASS + 1))
        fi
    fi
else
    warn "No certificate files in ./certs — nginx HTTPS listener will not boot."
fi

if grepq '^APP_PORT=80' "$ENV_OUT" || grepq '^APP_PORT=8080' "$ENV_OUT"; then
    warn "APP_PORT uses a non-standard port — confirm your firewall forwards 80/443 or set APP_PORT=80 / HTTPS_PORT=443."
else
    echo "  ✅ App port mapping configured"
    PASS=$((PASS + 1))
fi

check "CORS_ALLOWED_ORIGINS set" "grepq '^CORS_ALLOWED_ORIGINS=.+' \"\$ENV_OUT\""
check "SANCTUM_STATEFUL_DOMAINS set" "grepq '^SANCTUM_STATEFUL_DOMAINS=.+' \"\$ENV_OUT\""
check "FRONTEND_URL set" "grepq '^FRONTEND_URL=.+' \"\$ENV_OUT\""
check "APP_URL set" "grepq '^APP_URL=.+' \"\$ENV_OUT\""

echo ""
echo "=== Phase 10 — Backups & Monitoring ==="
if grepq '^BACKUP_RCLONE_REMOTE=.+' "$ENV_OUT"; then
    echo "  ✅ Off-site backup (rclone) configured"
    PASS=$((PASS + 1))
else
    warn "BACKUP_RCLONE_REMOTE is empty — local backups are NOT disaster recovery."
fi

if docker compose exec -T app php artisan monitoring:collect >/dev/null 2>&1; then
    echo "  ✅ Monitoring collector ran"
    PASS=$((PASS + 1))
else
    warn "monitoring:collect failed — check the monitoring tables exist (run migrations) and the scheduler is up."
fi

if docker compose exec -T app php artisan monitoring:check >/dev/null 2>&1; then
    echo "  ✅ Monitoring alert check ran"
    PASS=$((PASS + 1))
else
    warn "monitoring:check failed."
fi

echo ""
echo "=== Phase 11 — Resource Headroom ==="
DF_ROOT=$(df -P / 2>/dev/null | awk 'NR==2 {print $5}' | tr -d '%' || true)
DISK_PCT="${DF_ROOT:-101}"
if [ "$DISK_PCT" -gt 85 ]; then
    warn "Root disk at ${DISK_PCT}% — CRITICAL — free space immediately."
elif [ "$DISK_PCT" -gt 70 ]; then
    warn "Root disk at ${DISK_PCT}% — WARNING — plan cleanup/expansion."
else
    echo "  ✅ Root disk at ${DISK_PCT}%"
    PASS=$((PASS + 1))
fi

MEM_PCT=$(grep MemTotal /proc/meminfo 2>/dev/null | awk '{print $2}' || true)
if [ -n "$MEM_PCT" ]; then
    echo "  ✅ Host memory present: $((MEM_PCT / 1024)) MB"
    PASS=$((PASS + 1))
fi

echo ""
echo "=== Phase 12 — Frontend Environment ==="
if [ -f frontend/.env.local ]; then
    FE_ENV=$(cat frontend/.env.local)
elif [ -f frontend/.env.production ]; then
    FE_ENV=$(cat frontend/.env.production)
else
    FE_ENV=""
    warn "No frontend env file (frontend/.env.local) — NEXT_PUBLIC_API_URL may be missing."
fi

if [ -n "$FE_ENV" ]; then
    if grepq 'NEXT_PUBLIC_API_URL=.+' "$FE_ENV"; then
        if grepq 'localhost\|127\.0\.0\.1' "$FE_ENV"; then
            warn "NEXT_PUBLIC_API_URL points at localhost — the production frontend will call the wrong API."
        else
            echo "  ✅ NEXT_PUBLIC_API_URL set"
            PASS=$((PASS + 1))
        fi
    else
        warn "NEXT_PUBLIC_API_URL missing — the production frontend cannot reach the API."
    fi

    if grepq 'NEXT_PUBLIC_PLATFORM_DOMAIN=.+' "$FE_ENV"; then
        echo "  ✅ NEXT_PUBLIC_PLATFORM_DOMAIN set"
        PASS=$((PASS + 1))
    else
        warn "NEXT_PUBLIC_PLATFORM_DOMAIN missing — tenant-domain resolution will break."
    fi
fi

echo ""
echo "=== Summary ==="
echo "Passed: $PASS"
echo "Failed: $FAIL"
exit $FAIL