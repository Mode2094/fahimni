#!/usr/bin/env bash
# k6 load test runner for SHAHEAD LMS.
#
# Usage (from repo root):
#   ./tests/load/run.sh <scenario> [extra k6 args...]
#
# Examples:
#   ./tests/load/run.sh health
#   ./tests/load/run.sh concurrent-users --vus 500 --stage-min 1
#
# The scenarios run inside the k6 Docker image (no local install required).
# Configure the target via env before running:
#   BASE_URL, API_URL, PLATFORM_SLUG, TEST_USER_EMAIL, TEST_USER_PASSWORD,
#   TEST_TENANT_ID, VUS, STAGE_MIN, RATE, DURATION, K6_ADMIN_EMAIL/PASSWORD.
#
# NEVER point this at production.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SCENARIO="${1:-health}"
shift || true

mkdir -p "$ROOT/tests/load/results"

TS=$(date +%Y%m%d-%H%M%S)
OUT_FILE="$ROOT/tests/load/results/${SCENARIO}-${TS}.json"

echo "=== k6 scenario: ${SCENARIO} ==="
echo "  BASE_URL=${BASE_URL:-http://nginx:80}"
echo "  API_URL=${API_URL:-http://nginx:80/api/v1}"
echo "  Output: ${OUT_FILE}"

docker run --rm \
  -v "$ROOT/tests/load:/tests" \
  -v "$ROOT/tests/load/results:/results" \
  -e BASE_URL="${BASE_URL:-http://nginx:80}" \
  -e API_URL="${API_URL:-http://nginx:80/api/v1}" \
  -e PLATFORM_SLUG="${PLATFORM_SLUG:-demo}" \
  -e TEST_USER_EMAIL="${TEST_USER_EMAIL:-}" \
  -e TEST_USER_PASSWORD="${TEST_USER_PASSWORD:-}" \
  -e TEST_TENANT_ID="${TEST_TENANT_ID:-}" \
  -e K6_ADMIN_EMAIL="${K6_ADMIN_EMAIL:-}" \
  -e K6_ADMIN_PASSWORD="${K6_ADMIN_PASSWORD:-}" \
  -e VUS="${VUS:-1000}" \
  -e STAGE_MIN="${STAGE_MIN:-0.5}" \
  -e RATE="${RATE:-}" \
  -e DURATION="${DURATION:-}" \
  --network lms_lms-net \
  grafana/k6:latest run "/tests/scenarios/${SCENARIO}.js" \
  --out "json=/results/$(basename "${OUT_FILE}")" "$@"

echo ""
echo "JSON results written to: ${OUT_FILE}"
echo "Summary (k6 end-of-run):"
echo "  check ./tests/load/results/  for machine-readable metrics."