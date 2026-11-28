#!/usr/bin/env bash
# =============================================================================
#  Fahimni — verify the DEPLOY SCRIPT the host will run is byte-identical to
#  the version that was reviewed and approved in Git.
#
#  The rollout script is executed on the host as root (`sudo bash …`). Before it
#  runs, this proves the copy staged on the host is the SAME file the release
#  approved — no drift, no hand-edited leftover on the server.
#
#  It compares SHA256 of:
#    • the reference (default: this repo's git blob at a ref, or a file), and
#    • the candidate (default: the repo working-tree file; a host file; or a
#      file fetched over ssh).
#
#  Usage
#    verify-deploy-script.sh                                   # hash only (info)
#    verify-deploy-script.sh --git-ref <sha> \
#        --remote fahimni@<host> --expect <approved-sha256>
#    verify-deploy-script.sh --ref-file ./approved.sh --host-file /tmp/staged.sh
#
#  Options
#    --git-ref REF      reference = REF:<path> from the git repo (the approval)
#    --ref-file PATH    reference = this file instead of git
#    --host-file PATH   candidate = this file (e.g. a staged copy)
#    --remote TARGET    candidate = the host copy fetched with `ssh TARGET cat`
#    --remote-path PATH remote file path (default /home/fahimni/fahimni-brand-rollout.sh)
#    --path PATH        repo-relative script path
#                       (default deploy/scripts/fahimni-brand-rollout.sh)
#    --repo DIR         git repo to read from (default: this repository)
#    --expect SHA256    also require the reference to equal this approved hash
#
#  Exit: 0 = candidate matches the reference (and --expect); 1 = mismatch;
#        2 = usage/infrastructure error (could not fetch a side).
# =============================================================================
set -Eeuo pipefail

SELF_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_DEFAULT="$(cd -- "$SELF_DIR/../.." && pwd -P)"
REPO="$REPO_DEFAULT"
REL_PATH="deploy/scripts/fahimni-brand-rollout.sh"
REMOTE_PATH="/home/fahimni/fahimni-brand-rollout.sh"
GIT_REF=""; REF_FILE=""; HOST_FILE=""; REMOTE=""; EXPECT=""

usage(){ sed -n '2,40p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; }

die2(){ printf 'verify-deploy-script: %s\n' "$*" >&2; exit 2; }

while [ $# -gt 0 ]; do
  case "$1" in
    --git-ref)     GIT_REF="${2:?}"; shift 2 ;;
    --ref-file)    REF_FILE="${2:?}"; shift 2 ;;
    --host-file)   HOST_FILE="${2:?}"; shift 2 ;;
    --remote)      REMOTE="${2:?}"; shift 2 ;;
    --remote-path) REMOTE_PATH="${2:?}"; shift 2 ;;
    --path)        REL_PATH="${2:?}"; shift 2 ;;
    --repo)        REPO="${2:?}"; shift 2 ;;
    --expect)      EXPECT="${2:?}"; shift 2 ;;
    -h|--help)     usage; exit 0 ;;
    *)             die2 "unknown argument: $1" ;;
  esac
done

sha256_of_file(){  # $1 = file
  if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1
  else shasum -a 256 "$1" | cut -d' ' -f1; fi
}
sha256_of_stdin(){  # hash stdin (no temp file)
  if command -v sha256sum >/dev/null 2>&1; then sha256sum | cut -d' ' -f1
  else shasum -a 256 | cut -d' ' -f1; fi
}

# ── reference side ──────────────────────────────────────────────────────────
if [ -n "$REF_FILE" ]; then
  [ -f "$REF_FILE" ] || die2 "reference file not found: $REF_FILE"
  ref_hash="$(sha256_of_file "$REF_FILE")"
  ref_label="$REF_FILE"
elif [ -n "$GIT_REF" ]; then
  command -v git >/dev/null 2>&1 || die2 "git is required for --git-ref"
  ref_hash="$(git -C "$REPO" show "$GIT_REF:$REL_PATH" 2>/dev/null | sha256_of_stdin)" \
    || die2 "could not read $GIT_REF:$REL_PATH from $REPO"
  [ -n "$ref_hash" ] || die2 "empty blob for $GIT_REF:$REL_PATH"
  ref_label="$GIT_REF:$REL_PATH"
else
  [ -f "$REPO/$REL_PATH" ] || die2 "reference not found: $REPO/$REL_PATH"
  ref_hash="$(sha256_of_file "$REPO/$REL_PATH")"
  ref_label="worktree:$REL_PATH"
fi

# ── candidate side ──────────────────────────────────────────────────────────
if [ -n "$HOST_FILE" ]; then
  [ -f "$HOST_FILE" ] || die2 "host file not found: $HOST_FILE"
  cand_hash="$(sha256_of_file "$HOST_FILE")"
  cand_label="$HOST_FILE"
elif [ -n "$REMOTE" ]; then
  command -v ssh >/dev/null 2>&1 || die2 "ssh is required for --remote"
  # shellcheck disable=SC2086
  # shellcheck disable=SC2029
  cand_hash="$(ssh ${SSH_OPTS:--o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=15} \
    "$REMOTE" "cat '$REMOTE_PATH'" 2>/dev/null | sha256_of_stdin)" \
    || die2 "could not fetch $REMOTE_PATH from $REMOTE"
  [ -n "$cand_hash" ] || die2 "empty or unreadable remote file: $REMOTE:$REMOTE_PATH"
  cand_label="$REMOTE:$REMOTE_PATH"
else
  [ -f "$REPO/$REL_PATH" ] || die2 "candidate not found: $REPO/$REL_PATH"
  cand_hash="$(sha256_of_file "$REPO/$REL_PATH")"
  cand_label="worktree:$REL_PATH"
fi

printf 'reference (%s): %s\n' "$ref_label" "$ref_hash"
printf 'candidate (%s): %s\n' "$cand_label" "$cand_hash"

rc=0
if [ -n "$EXPECT" ]; then
  if [ "$ref_hash" = "$EXPECT" ]; then
    printf 'approved hash  : MATCH (--expect)\n'
  else
    printf 'approved hash  : MISMATCH — reference %s != expected %s\n' "$ref_hash" "$EXPECT" >&2
    rc=1
  fi
fi

if [ "$ref_hash" = "$cand_hash" ]; then
  printf 'RESULT: OK — the script to be executed matches the approved version.\n'
else
  printf 'RESULT: MISMATCH — the host copy differs from the approved version.\n' >&2
  rc=1
fi
exit "$rc"
