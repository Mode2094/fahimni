#!/usr/bin/env bash
# Guard the Laravel writable-dir skeleton against regressions.
#
# Root cause this protects against:
#   config('view.compiled') === realpath(storage_path('framework/views')).
#   `storage/framework/views` was untracked, so neither git, the Docker build
#   context, nor a fresh/empty named volume carried it. realpath() then returns
#   false, `php artisan optimize` aborts with "View path not found", and because
#   the app CMD is `optimize && php-fpm`, the container never starts.
#
# The dirs must be COMMITTED (a placeholder file makes the dir survive a fresh
# clone) and must NOT be excluded by .dockerignore.
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd -P)"

# "<dir>:<placeholder committed inside it>"
required=(
  "storage/framework/views:.gitignore"
  "storage/framework/cache/data:.gitignore"
  "storage/framework/sessions:.gitignore"
  "storage/logs:.gitignore"
  "storage/app/backups:.gitkeep"
  "storage/app/public:.gitignore"
  "bootstrap/cache:.gitignore"
)

fails=0
ok()  { printf 'ok   %s\n' "$1"; }
bad() { printf 'FAIL %s\n' "$1" >&2; fails=$((fails + 1)); }

have_git=0
if command -v git >/dev/null 2>&1 \
  && git -C "$REPO_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  have_git=1
fi

echo "== writable dirs exist and their placeholders are tracked =="
for entry in "${required[@]}"; do
  rel="${entry%%:*}"
  marker="${entry##*:}"
  file="$rel/$marker"
  if [[ ! -d "$REPO_DIR/$rel" ]]; then
    bad "$rel is missing (a fresh checkout/image would lack it)"
    continue
  fi
  if [[ ! -e "$REPO_DIR/$file" ]]; then
    bad "$file is missing (the dir would not survive a fresh checkout)"
    continue
  fi
  if [[ "$have_git" -eq 1 ]]; then
    if git -C "$REPO_DIR" check-ignore -q "$file"; then
      bad "$file is git-ignored (would not reach a fresh clone)"
      continue
    fi
    if ! git -C "$REPO_DIR" ls-files --error-unmatch "$file" >/dev/null 2>&1; then
      bad "$file is not tracked by git"
      continue
    fi
  fi
  ok "$rel present + tracked ($marker)"
done

echo "== .dockerignore re-includes those placeholders in the build context =="
for entry in "${required[@]}"; do
  rel="${entry%%:*}"
  marker="${entry##*:}"
  case "$rel" in
    storage/logs|storage/framework/*|storage/app/backups) ;;
    *) continue ;;
  esac
  if grep -qxF "!$rel/$marker" "$REPO_DIR/.dockerignore"; then
    ok ".dockerignore keeps $rel/$marker"
  else
    bad ".dockerignore would drop $rel/$marker from the build context"
  fi
done

echo
if [[ "$fails" -ne 0 ]]; then
  echo "storage dirs: $fails check(s) FAILED" >&2
  exit 1
fi
echo "storage dirs: OK"
