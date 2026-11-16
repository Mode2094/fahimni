#!/usr/bin/env bash
# =============================================================================
#  Tests for deploy/scripts/verify-deploy-script.sh.
#
#  Proves the deployment gate that matches the host script against the
#  Git-approved version: a byte-identical copy passes, a drifted copy fails, and
#  a wrong --expect hash fails even when the two sides agree. Entirely hermetic
#  (a throwaway git repo + temp files); it never touches the network or the host.
#
#  Run: deploy/tests/verify-deploy-script-test.sh
# =============================================================================
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd -P)"
SCRIPT="$REPO_DIR/deploy/scripts/verify-deploy-script.sh"
[ -f "$SCRIPT" ] || { echo "missing: $SCRIPT" >&2; exit 1; }

work="$(mktemp -d "${TMPDIR:-/tmp}/fahimni-verify-test.XXXXXX")"
trap 'rm -rf "$work"' EXIT

failures=0
pass(){ printf '  ok    %s\n' "$*"; }
fail(){ printf '  FAIL  %s\n' "$*" >&2; failures=$((failures + 1)); }
run(){ "$SCRIPT" "$@"; }

sha_of(){ if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi; }

printf '\n== verify-deploy-script tests ==\n'

# ── T0: syntactically valid + hash helper ───────────────────────────────────
printf '\n-- T0 the script parses and hashes a file\n'
if bash -n "$SCRIPT"; then pass "bash -n"; else fail "bash -n"; fi

APPROVED="$work/approved.sh"
printf '#!/usr/bin/env bash\necho approved\n' > "$APPROVED"
APPR_HASH="$(sha_of "$APPROVED")"

# ── T1: identical copies pass (file vs file) ────────────────────────────────
printf '\n-- T1 identical copies pass\n'
SAME="$work/same.sh"; cp -p "$APPROVED" "$SAME"
if run --ref-file "$APPROVED" --host-file "$SAME" >/dev/null 2>&1; then pass "matching hashes return 0"; else fail "matching hashes returned non-zero"; fi

# ── T2: a drifted host copy fails ───────────────────────────────────────────
printf '\n-- T2 a drifted host copy fails\n'
DRIFT="$work/drift.sh"; cp -p "$APPROVED" "$DRIFT"; printf '\n# hand edit on the host\n' >> "$DRIFT"
if run --ref-file "$APPROVED" --host-file "$DRIFT" >/dev/null 2>&1; then fail "a drifted copy was accepted"; else pass "a drifted copy is rejected"; fi

# ── T3: --expect must match the reference ───────────────────────────────────
printf '\n-- T3 --expect matches the approved hash\n'
if run --ref-file "$APPROVED" --host-file "$SAME" --expect "$APPR_HASH" >/dev/null 2>&1; then
  pass "the approved hash matches (--expect)"
else
  fail "the approved hash was rejected"
fi
if run --ref-file "$APPROVED" --host-file "$SAME" --expect "deadbeef" >/dev/null 2>&1; then
  fail "a wrong --expect was accepted"
else
  pass "a wrong --expect is rejected even when both sides agree"
fi

# ── T4: --git-ref reads the approved blob from git, not the worktree ────────
printf '\n-- T4 the reference can be a git ref\n'
gitrepo="$work/gitrepo"
mkdir -p "$gitrepo/deploy/scripts"
git -C "$gitrepo" init -q
git -C "$gitrepo" config user.email t@example.com
git -C "$gitrepo" config user.name test
cp -p "$APPROVED" "$gitrepo/deploy/scripts/fahimni-brand-rollout.sh"
git -C "$gitrepo" add -A
git -C "$gitrepo" commit -qm approved
# The worktree is then changed AFTER the commit — the git-ref side must still
# see the approved blob (this is exactly the drift the gate is meant to catch).
printf '\n# local edit after approval\n' >> "$gitrepo/deploy/scripts/fahimni-brand-rollout.sh"
if run --repo "$gitrepo" --git-ref HEAD --host-file "$APPROVED" >/dev/null 2>&1; then
  pass "--git-ref HEAD compares against the committed blob (not the dirty worktree)"
else
  fail "--git-ref HEAD did not read the approved blob"
fi
if run --repo "$gitrepo" --host-file "$APPROVED" >/dev/null 2>&1; then
  fail "the dirty worktree was accepted as the approved version"
else
  pass "the dirty worktree differs from the approved file (correctly rejected)"
fi

# ── T5: infrastructure errors exit 2, not 1 ─────────────────────────────────
printf '\n-- T5 a missing side is an infrastructure error (exit 2)\n'
if run --ref-file "$work/does-not-exist" --host-file "$SAME" >/dev/null 2>&1; then
  fail "a missing reference was accepted"
else
  rc=$?
  if [ "$rc" = 2 ]; then pass "a missing reference exits 2"; else fail "a missing reference exited $rc (want 2)"; fi
fi
if run --ref-file "$APPROVED" --host-file "$work/nope.sh" >/dev/null 2>&1; then
  fail "a missing candidate was accepted"
else
  rc=$?
  if [ "$rc" = 2 ]; then pass "a missing candidate exits 2"; else fail "a missing candidate exited $rc (want 2)"; fi
fi

# ── T6: the default (bare) run is a safe informational hash ─────────────────
printf '\n-- T6 a bare run hashes the real rollout script and exits 0\n'
if out="$(run 2>&1)"; then
  case "$out" in
    *"deploy/scripts/fahimni-brand-rollout.sh"*) pass "the bare run reports the rollout script hash" ;;
    *) fail "the bare run did not name the rollout script" ;;
  esac
else
  fail "the bare run failed"
fi

# ── T7: the real rollout script is present at the default path ─────────────
printf '\n-- T7 the default path resolves to the real rollout script\n'
if [ -f "$REPO_DIR/deploy/scripts/fahimni-brand-rollout.sh" ]; then
  pass "deploy/scripts/fahimni-brand-rollout.sh exists"
else
  fail "the rollout script is missing — the gate would have nothing to verify"
fi

printf '\n'
if [ "$failures" -eq 0 ]; then
  echo "✅ all verify-deploy-script tests passed"
else
  echo "❌ $failures verify-deploy-script test(s) failed" >&2
fi
exit "$(( failures > 0 ? 1 : 0 ))"
