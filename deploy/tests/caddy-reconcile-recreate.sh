#!/usr/bin/env bash
# Prove `caddy-docker-integrate.sh --reconcile` re-applies the Fahimni vhosts
# even when the base Caddyfile bytes (and therefore the merged hash) are
# UNCHANGED. Three independent triggers must each be detected:
#
#   A. the LooksLoop container was recreated and lost the network attachment;
#   B. the container was recreated while still attached (new container id);
#   C. the container reloaded its OWN Caddyfile (same id, same base) and dropped
#      the in-memory merge.
#
# A local fake LooksLoop Caddy container is created/started/removed; the real
# LooksLoop container is never touched. Needs a Docker daemon (available in CI).
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
DEPLOY_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd -P)"
integrate="$DEPLOY_DIR/scripts/caddy-docker-integrate.sh"
fragment="$DEPLOY_DIR/caddy/fahimni.caddy"
base="$DEPLOY_DIR/tests/caddy-reconcile-base.fixture"
CADDY_IMAGE="${CADDY_IMAGE:-caddy:2-alpine}"

for f in "$integrate" "$fragment" "$base"; do
  [[ -f "$f" ]] || { echo "missing: $f" >&2; exit 1; }
done

command -v docker >/dev/null 2>&1 || { echo "docker CLI not found" >&2; exit 1; }
docker info >/dev/null 2>&1 || { echo "docker daemon not reachable" >&2; exit 1; }

# A valid bcrypt string used ONLY for syntax validation (never applied to a real
# user). Matches what --apply requires without storing a secret.
# shellcheck disable=SC2016  # the $ are literal bcrypt salt/marker characters
DUMMY_HASH='$2a$12$R9h/cIPz0gi.URNNX3kh2OPST9/PgBkqquzi.Ss7KIUgO2t0jWMUW'

suffix="$$"
name="fahimni-reconcile-test-caddy-$suffix"
net="fahimni-reconcile-test-net-$suffix"

work="$(mktemp -d "${TMPDIR:-/tmp}/fahimni-reconcile-test.XXXXXX")"
state="$work/state"

cleanup() {
  docker rm -f "$name" >/dev/null 2>&1 || true
  docker network rm "$net" >/dev/null 2>&1 || true
  rm -rf "$work"
}
trap cleanup EXIT

fails=0
note()  { printf '   %s\n' "$*"; }
pass()  { printf 'ok  %s\n' "$1"; }
fail()  { printf 'FAIL %s\n' "$1" >&2; fails=$((fails + 1)); }
assert_contains() { # <haystack> <needle> <label>
  if grep -Fq "$2" <<<"$1"; then pass "$3"; else fail "$3 (missing: $2)"; fi
}

run() { # run <mode...> — always from the repo, with the test paths
  FAHIMNI_STAGING_BASIC_HASH="$DUMMY_HASH" \
    "$integrate" "$@" --container "$name" --network "$net" --state-dir "$state"
}
status()      { run --status; }
state_field() { sed -n "s/^$1=//p" "$state/caddy-integrate.state" 2>/dev/null; }

start_caddy() { # (re)create + start the fake LooksLoop Caddy container
  docker rm -f "$name" >/dev/null 2>&1 || true
  local cid
  cid="$(docker create --name "$name" "$CADDY_IMAGE")"
  # Copy the base Caddyfile in rather than bind-mounting it: no host path is
  # shared with the Docker VM, so this works on Linux and macOS alike.
  docker cp "$base" "$cid:/etc/caddy/Caddyfile"
  docker start "$cid" >/dev/null
}

wait_ready() {
  local i=0
  while [[ "$i" -lt 60 ]]; do
    if docker exec "$name" wget -qO- http://127.0.0.1:2019/config/ >/dev/null 2>&1; then
      return 0
    fi
    i=$((i + 1))
    sleep 1
  done
  return 1
}

cid() { docker inspect -f '{{.Id}}' "$name"; }

echo "== start fake LooksLoop Caddy container ($CADDY_IMAGE) =="
start_caddy
wait_ready || { echo "caddy admin API never became ready" >&2; exit 1; }
original_id="$(cid)"
note "container id: $original_id"

echo "== apply =="
run --apply --yes >/dev/null
assert_contains "$(status)" 'live vhosts: present' "apply installs the Fahimni vhosts"
assert_contains "$(status)" 'connected:   yes' "apply attaches the shared network"

echo "== A. recreate container WITHOUT reconnecting (network detached) =="
start_caddy
wait_ready || { echo "caddy admin API never became ready after recreate" >&2; exit 1; }
new_id="$(cid)"
if [[ "$new_id" != "$original_id" ]]; then pass "container was recreated (new id)"; else fail "container id did not change"; fi
assert_contains "$(status)" 'live vhosts: absent' "recreated container has no Fahimni vhosts"
run --reconcile --yes >/dev/null
assert_contains "$(status)" 'live vhosts: present' "reconcile re-applies after recreation"
assert_contains "$(status)" 'connected:   yes' "reconcile re-attaches the network"
if [[ "$(state_field container_id)" == "$new_id" ]]; then
  pass "state records the new container id"
else
  fail "state container_id is stale: $(state_field container_id)"
fi

echo "== B. recreate container while still attached (same Caddyfile bytes) =="
attached_id="$(cid)"
start_caddy
wait_ready || { echo "caddy admin API never became ready after recreate" >&2; exit 1; }
docker network connect "$net" "$name"     # LooksLoop-side attachment survives
wait_ready || { echo "caddy admin API never became ready after reconnect" >&2; exit 1; }
second_id="$(cid)"
if [[ "$second_id" != "$attached_id" ]]; then pass "container was recreated again"; else fail "container id did not change"; fi
assert_contains "$(status)" 'connected:   yes' "recreated container is attached"
assert_contains "$(status)" 'live vhosts: absent' "recreated container has no Fahimni vhosts"
run --reconcile --yes >/dev/null
assert_contains "$(status)" 'live vhosts: present' "reconcile detects the new container id and re-applies"

echo "== C. container reloads its own Caddyfile (same id, same base) =="
docker exec "$name" caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null
assert_contains "$(status)" 'live vhosts: absent' "self-reload dropped the in-memory merge"
run --reconcile --yes >/dev/null
assert_contains "$(status)" 'live vhosts: present' "reconcile detects the missing live vhosts and re-applies"

echo "== D. a fully current state is a no-op =="
out="$(run --reconcile --yes)"
assert_contains "$out" 'nothing to do' "reconcile is a no-op when everything is current"

echo
if [[ "$fails" -ne 0 ]]; then
  echo "caddy reconcile auto-detection: $fails check(s) FAILED" >&2
  exit 1
fi
echo "caddy reconcile auto-detection: OK"
