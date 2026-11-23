#!/usr/bin/env bash
# fahimni-docker-gc — bounded, safe Docker cleanup for the production host.
#
# Installed on the host as /usr/local/bin/fahimni-docker-gc and run daily from
# /etc/cron.d/fahimni-maintenance. Kept in the repo so a rebuilt host can
# reinstall it (see deploy/MAINTENANCE.md).
#
# Safety guarantees (never weakened):
#   * NEVER removes running/used images, any volume, backups, databases,
#     application storage, or Bunny assets.
#   * ALWAYS keeps the current release images and the recorded rollback
#     images (.previous-tag), plus the newest prior release set.
#   * Skips entirely while a deploy/build runs or a deploy lock is held.
#
# Bounds (avoid the slow disk-fill observed on 2026-11-23):
#   * release image sets : current + rollback + newest prior  (<= 3 tags)
#   * build cache        : <= 8 GiB (BuildKit cache GC)
#   * release checkouts  : current + rollback + 3 newest       (<= 5 dirs)
set -euo pipefail

STACK="/opt/fahimni/production"
LOG="/var/log/fahimni-docker-gc.log"
KEEP_IMAGES=3
KEEP_RELEASES=5
CACHE_KEEP_BYTES=$((8 * 1024 * 1024 * 1024))
REPOS=(fahimni-app fahimni-frontend fahimni-horizon fahimni-scheduler)

log() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }

if [[ -e "$STACK/.deploy.lock" ]]; then log "SKIP: deploy lock present"; exit 0; fi
if pgrep -f 'fresh-install\.sh|deploy\.sh|docker compose build|buildkitd' >/dev/null 2>&1; then
  log "SKIP: a deploy/build appears to be running"; exit 0
fi

CUR_TAG=""
[[ -f "$STACK/.release-sha" ]] && CUR_TAG="$(tr -d '[:space:]' < "$STACK/.release-sha")"
if [[ -z "$CUR_TAG" ]]; then
  CUR_TAG="$(basename "$(readlink -f "$STACK/current" 2>/dev/null || true)")"
fi
PREV_TAG=""
[[ -f "$STACK/.previous-tag" ]] && PREV_TAG="$(tr -d '[:space:]' < "$STACK/.previous-tag")"

# Newest-first list of existing fahimni image tags.
# `docker image ls` already lists newest-first, so no date parsing is needed.
mapfile -t ALL_TAGS < <(
  docker image ls --format '{{.Repository}}:{{.Tag}}' \
    | grep -E '^fahimni-(app|frontend|horizon|scheduler):' \
    | sed 's/^[^:]*://' | awk '!seen[$0]++'
)

KEEP_TAGS=()
add_tag() { local t="$1" k; [[ -z "$t" ]] && return 0; for k in "${KEEP_TAGS[@]:-}"; do [[ "$k" == "$t" ]] && return 0; done; KEEP_TAGS+=("$t"); }
add_tag "$CUR_TAG"; add_tag "$PREV_TAG"
for t in "${ALL_TAGS[@]:-}"; do (( ${#KEEP_TAGS[@]} >= KEEP_IMAGES )) && break; add_tag "$t"; done

tag_kept() { local t="$1" k; for k in "${KEEP_TAGS[@]:-}"; do [[ "$k" == "$t" ]] && return 0; done; return 1; }

log "existing tags: ${ALL_TAGS[*]:-none}"
log "keep image tags: ${KEEP_TAGS[*]:-none}"

removed_imgs=0
for repo in "${REPOS[@]}"; do
  while IFS= read -r ref; do
    [[ -z "$ref" ]] && continue
    tag="${ref##*:}"
    if tag_kept "$tag"; then continue; fi
    if docker image rm "$ref" >/dev/null 2>&1; then log "removed image $ref"; removed_imgs=$((removed_imgs+1));
    else log "kept image $ref (in use or shared)"; fi
  done < <(docker image ls --format '{{.Repository}}:{{.Tag}}' | grep -E "^${repo}:" || true)
done
log "images removed: $removed_imgs"

if docker builder prune -a -f --keep-storage "$CACHE_KEEP_BYTES" >>"$LOG" 2>&1; then
  log "build cache pruned (kept <= $((CACHE_KEEP_BYTES/1024/1024)) MiB)"
else
  log "builder prune with --keep-storage failed; using age filter"
  docker builder prune -a -f --filter until=72h >>"$LOG" 2>&1 || true
fi

declare -A KEEP_DIRS=()
[[ -n "$CUR_TAG" ]] && KEEP_DIRS["$CUR_TAG"]=1
[[ -n "$PREV_TAG" ]] && KEEP_DIRS["$PREV_TAG"]=1
mapfile -t NEWEST_RELS < <(
  find "$STACK/releases" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %f\n' 2>/dev/null \
    | sort -rn | awk '{print $2}'
)
for base in "${NEWEST_RELS[@]:-}"; do
  [[ -z "$base" ]] && continue
  (( ${#KEEP_DIRS[@]} >= KEEP_RELEASES )) && break
  KEEP_DIRS["$base"]=1
done
for d in "$STACK"/releases/*/; do
  [[ -d "$d" ]] || continue
  base="$(basename "$d")"
  [[ -n "${KEEP_DIRS[$base]:-}" ]] && continue
  rm -rf -- "$d" && log "removed release $base"
done

log "done: releases=$(find "$STACK/releases" -mindepth 1 -maxdepth 1 -type d 2>/dev/null | wc -l) images=$(docker image ls --format '{{.Repository}}' | grep -c fahimni- || true)"
