#!/usr/bin/env bash
# Validate the additive Fahimni Caddy vhosts against a simulated LooksLoop base,
# and prove the duplicate-host guard fires. Runs with a local `caddy` binary or a
# throwaway caddy container, so it works on a workstation and in CI.
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
DEPLOY_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd -P)"
integrate="$DEPLOY_DIR/scripts/caddy-docker-integrate.sh"
fragment="$DEPLOY_DIR/caddy/fahimni.caddy"
base="$DEPLOY_DIR/tests/looksloop-caddyfile.fixture"

for f in "$integrate" "$fragment" "$base"; do
  [[ -f "$f" ]] || { echo "missing: $f" >&2; exit 1; }
done

work="$(mktemp -d "${TMPDIR:-/tmp}/fahimni-caddy-test.XXXXXX")"
trap 'rm -rf "$work"' EXIT

echo "== positive: LooksLoop base + Fahimni fragment must validate =="
"$integrate" --check --base-file "$base" --source "$fragment"

echo "== negative: base that already defines fahimni.me must be rejected =="
cp "$base" "$work/duplicate.Caddyfile"
printf '\n# pre-existing Fahimni host (simulated mistake)\nfahimni.me {\n\trespond "dup" 200\n}\n' \
  >> "$work/duplicate.Caddyfile"
if "$integrate" --check --base-file "$work/duplicate.Caddyfile" --source "$fragment" >/dev/null 2>&1; then
  echo "ERROR: duplicate-host guard did not fire" >&2
  exit 1
fi
echo "guard correctly rejected the duplicate host"

echo "caddy merge validation: OK"
