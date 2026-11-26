#!/usr/bin/env bash
# =============================================================================
#  Regression: deploy.sh must tag images with the code it actually built.
#
#  fresh-install.sh pins IMAGE_TAG to the install sha in the env file, and only
#  the brand/golive rollouts ever rewrote it. A plain `deploy.sh` therefore let
#  a STALE pin win: brand-new images were retagged with an old commit, the
#  `.deployed-tag`/auto-restore tag pointed at the new images, and tag-based
#  rollback silently lost its target. This guards the derivation, the explicit
#  override and the persist step, and exercises the real persist_image_tag.
#
#  Hermetic: a throwaway env file, no Docker, no network, no root.
#  Run: deploy/tests/deploy-image-tag-test.sh
# =============================================================================
# shellcheck disable=SC2016  # the guarded patterns are literal shell text
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd -P)"
DEPLOY="$REPO_DIR/deploy/scripts/deploy.sh"
[ -f "$DEPLOY" ] || { echo "missing: $DEPLOY" >&2; exit 1; }

work="$(mktemp -d "${TMPDIR:-/tmp}/fahimni-image-tag.XXXXXX")"
trap 'rm -rf "$work"' EXIT

failures=0
pass(){ printf '  ok    %s\n' "$*"; }
fail(){ printf '  FAIL  %s\n' "$*" >&2; failures=$((failures + 1)); }

# check_present <description> <grep-flags> <pattern> <file>
check_present(){
  if grep "$2" -- "$3" "$4" >/dev/null 2>&1; then pass "$1"; else fail "$1"; fi
}
# check_absent <description> <grep-flags> <pattern> <file>
check_absent(){
  if grep "$2" -- "$3" "$4" >/dev/null 2>&1; then fail "$1"; else pass "$1"; fi
}
mode_of(){ if stat -Lc '%a' "$1" >/dev/null 2>&1; then stat -Lc '%a' "$1"; else stat -Lf '%Lp' "$1"; fi; }
check_mode(){ if [ "$(mode_of "$2")" = "$3" ]; then pass "$1"; else fail "$1 (got $(mode_of "$2"))"; fi; }

printf '\n== deploy image-tag tests ==\n'

# ── T1: the tag derives from the git commit, with an explicit override ───────
printf '\n-- T1 the tag tracks the built commit\n'
check_present 'tag defaults to the git commit and honours --image-tag' \
  -qF 'tag="${image_tag:-$sha}"' "$DEPLOY"
check_absent 'the stale env IMAGE_TAG is no longer authoritative' \
  -qE '^[[:space:]]*tag="[$]IMAGE_TAG"' "$DEPLOY"
check_present 'a stale env pin is reported, not silently trusted' \
  -qF 'ignoring stale env IMAGE_TAG' "$DEPLOY"

# ── T2: --image-tag is a documented, parsed option ───────────────────────────
printf '\n-- T2 --image-tag is a supported option\n'
check_present 'usage documents --image-tag'   -qF '--image-tag TAG' "$DEPLOY"
check_present '--image-tag is parsed'         -qF '--image-tag)   image_tag=' "$DEPLOY"

# ── T3: a successful deploy persists the effective tag ───────────────────────
printf '\n-- T3 the env file is kept truthful\n'
check_present 'deploy persists the effective tag' \
  -qF 'persist_image_tag "$env_file" "$tag"' "$DEPLOY"

# ── T4: exercise the REAL persist_image_tag (no duplication of its logic) ────
printf '\n-- T4 persist_image_tag rewrites, appends and preserves mode\n'
fn="$(sed -n '/^persist_image_tag() {/,/^}/p' "$DEPLOY")"
resolver="$(sed -n '/^resolve_env_file() {/,/^}/p' "$DEPLOY")"
if [ -z "$fn" ] || [ -z "$resolver" ]; then
  fail 'could not extract persist_image_tag/resolve_env_file from deploy.sh'
else
  # invoked indirectly through the eval'd helper below
  # shellcheck disable=SC2329
  warn(){ :; }
  eval "$resolver"
  eval "$fn"

  envf="$work/replace.env"
  printf 'APP_ENV=production\nIMAGE_TAG=stale_sha\nCOMPOSE_PROJECT_NAME=fahimni\n' > "$envf"
  chmod 600 "$envf"
  persist_image_tag "$envf" "fresh_sha"
  check_present 'an existing pin is replaced' -qx 'IMAGE_TAG=fresh_sha' "$envf"
  if grep -qx 'APP_ENV=production' "$envf" && grep -qx 'COMPOSE_PROJECT_NAME=fahimni' "$envf"; then
    pass 'unrelated keys are preserved'
  else
    fail 'unrelated keys were lost'
  fi
  check_mode 'the file mode is preserved (600)' "$envf" 600

  envf2="$work/append.env"
  printf 'APP_ENV=production\n' > "$envf2"
  persist_image_tag "$envf2" "fresh_sha"
  check_present 'a missing pin is appended' -qx 'IMAGE_TAG=fresh_sha' "$envf2"

  # ── the production layout: `<release>/.env` is a symlink to /etc/fahimni ────
  # `mv` onto a symlink would replace it with a COPY (and write the wrong file),
  # which is exactly the bug this locks out.
  real="$work/real.env"
  printf 'APP_ENV=production\nIMAGE_TAG=stale_sha\n' > "$real"
  chmod 600 "$real"
  link="$work/link.env"
  ln -s "$real" "$link"
  persist_image_tag "$link" "fresh_sha"
  if [ -L "$link" ]; then
    pass 'persisting through a symlink keeps it a symlink'
  else
    fail 'persist replaced the .env symlink with a copy'
  fi
  check_present 'the REAL env file gets the fresh pin' -qx 'IMAGE_TAG=fresh_sha' "$real"
  check_mode 'the real env file keeps its mode (600)' "$real" 600
fi

printf '\n'
if [ "$failures" -eq 0 ]; then
  echo "✅ all deploy image-tag tests passed"
else
  echo "❌ $failures deploy image-tag test(s) failed" >&2
fi
exit "$(( failures > 0 ? 1 : 0 ))"
