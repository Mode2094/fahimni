#!/usr/bin/env bash
# Prove the Redis sizing guard: REDIS_MAXMEMORY must sit at least 32 MiB below
# the container cap MEM_REDIS, otherwise Redis is OOM-killed before it can evict
# (the bug this fixes: maxmemory 512mb inside a 256M container).
#
# Sources deploy/scripts/lib.sh directly and exercises mem_to_mib /
# validate_redis_memory in subshells (validate_redis_memory calls `die`, which
# exits — so it must never run in this shell).
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
DEPLOY_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd -P)"
# shellcheck source=../scripts/lib.sh
source "$DEPLOY_DIR/scripts/lib.sh"

fails=0
ok()  { printf 'ok   %s\n' "$1"; }
bad() { printf 'FAIL %s\n' "$1" >&2; fails=$((fails + 1)); }

check_mib() { # <input> <expected|FAIL>
  local got
  if got="$(mem_to_mib "$1")"; then
    if [[ "$2" != FAIL && "$got" == "$2" ]]; then ok "mem_to_mib('$1') = $got"
    else bad "mem_to_mib('$1') = $got, expected $2"; fi
  else
    if [[ "$2" == FAIL ]]; then ok "mem_to_mib('$1') rejected"
    else bad "mem_to_mib('$1') rejected, expected $2"; fi
  fi
}

check_redis() { # <MEM_REDIS> <REDIS_MAXMEMORY> <ok|fail>  ("" means unset/default)
  local rc=0
  ( MEM_REDIS="$1" REDIS_MAXMEMORY="$2" validate_redis_memory ) >/dev/null 2>&1 || rc=$?
  if [[ "$3" == ok ]]; then
    if [[ "$rc" -eq 0 ]]; then
      ok "MEM_REDIS='$1' REDIS_MAXMEMORY='$2' accepted"
    else
      bad "MEM_REDIS='$1' REDIS_MAXMEMORY='$2' rejected (expected accepted)"
    fi
  else
    if [[ "$rc" -ne 0 ]]; then
      ok "MEM_REDIS='$1' REDIS_MAXMEMORY='$2' rejected"
    else
      bad "MEM_REDIS='$1' REDIS_MAXMEMORY='$2' accepted (expected rejected)"
    fi
  fi
}

echo "== size parsing =="
check_mib '512mb' 512
check_mib '256M'  256
check_mib '192mb' 192
check_mib '1G'    1024
check_mib '1g'    1024
check_mib '1024k' 1
check_mib '300'   300
check_mib ''      FAIL
check_mib 'abc'   FAIL

echo "== redis headroom =="
# The fix's defaults: 192 MiB maxmemory under a 256 MiB cap (>=32 MiB headroom).
check_redis ''      ''      ok
check_redis 256M    192mb   ok
check_redis 256M    224mb   ok    # exactly 32 MiB headroom
# The bug: maxmemory at/above the cap, or too little headroom.
check_redis 256M    512mb   fail
check_redis 256M    256M    fail
check_redis 256M    225mb   fail  # 31 MiB headroom
check_redis 256M    300mb   fail
check_redis 256M    garbage fail
check_redis garbage 192mb   fail

echo
if [[ "$fails" -ne 0 ]]; then
  echo "redis memory guard: $fails check(s) FAILED" >&2
  exit 1
fi
echo "redis memory guard: OK"
