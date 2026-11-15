# فهمني — Safe Merge & Deploy Plan (PRs #5–#21)

- Base for every PR: `integration/launch-2026-11` @ `dd13c63`
- Release gate: **PR #4** (`integration/launch-2026-11` → `main`) — **must stay
  unmerged until the owner approves**
- Constraint: **do not touch LooksLoop or Staging.** Nothing here does.
- Date: 16 Nov 2026

> Merging any of these PRs into `integration/launch-2026-11` **does not deploy
> anything.** Production only changes when PR #4 is merged into `main` **and**
> the owner runs the single deploy command (§5).

---

## 1. Precedence rules

1. **Data-protection and access-control fixes first**, then correctness, then
   UX/perf, then docs.
2. **The stacked branch group must merge in its own order** (§2) or as a whole —
   merging a later member first would drag earlier members in without a review
   boundary.
3. **`routes/api.php` is the shared hot file**: it is edited by #5, #8, #9, #11,
   #12, #13. Merge one at a time and re-run the full suite after each.
4. **Never merge PR #4** until every wave below is green on `integration`.

---

## 2. Dependencies (verified, not assumed)

Five PRs are **stacked** — each branch was cut from the previous one, so it
already contains the earlier commits:

```
dd13c63
 └─ 7c592c8  → #9  fix/phase4-card-redeem            (stack root)
     └─ 6561d77  → #15 fix/phase6-cost-currency
         └─ dbeb552  → #16 fix/phase7-manifest-cache
             └─ 2bad98d  → #14 fix/landing-gateway-copy
                 └─ 28fa2dc  → #12 fix/phase5-student-api   (stack tip)
```

Consequence: **#15 contains #9; #16 contains #9+#15; #14 contains #9+#15+#16;
#12 contains all four.** Merging the stack **bottom-up** (#9 → #15 → #16 → #14 →
#12) is a clean fast-forward per step and gives one reviewable commit per PR.

Everything else is an independent 1-commit branch off `dd13c63`.

Verified conflict map (same file touched by more than one PR):

| File | PRs | Resolution |
|---|---|---|
| `app/Http/Controllers/Api/ActivationCardController.php` | #9 (stack root), #11 | keep both: hardening (#9) + `export()` (#11) |
| `app/Http/Controllers/Api/LiveSessionController.php` | #7, #8 | keep both: live-minutes+notify (#7) + `attachRecording()` (#8) |
| `tests/Feature/LiveStreamingTest.php` | #7, #8 | keep both test blocks |
| `routes/api.php` | #5, #8, #9, #11, #12, #13 | union of added routes; no removals |
| `frontend/src/app/teacher/dashboard/page.tsx` | #13, #21 | keep onboarding bar (#13) + resilient `loadAll()` (#21) |
| `frontend/package.json` | #16 (via stack), #21 | keep #21 scripts (`test:scripts`, `test:ui`, `test:unit` superset) |
| `.github/workflows/ci.yml` | #16 (via stack), #21 | exactly **one** "Frontend unit tests" step running `npm run test:unit` |

No other file is shared between PRs.

---

## 3. Merge order

One PR at a time, full suite green after each (§4).

| Step | PR | Branch | Commit | Wave | Notes |
|---|---|---|---|---|---|
| 1 | **#9** | `fix/phase4-card-redeem` | `7c592c8` | A — access/abuse | stack root; must precede #12/#14/#15/#16 |
| 2 | **#15** | `fix/phase6-cost-currency` | `6561d77` | A — cost correctness | fast-forward from #9 |
| 3 | **#16** | `fix/phase7-manifest-cache` | `dbeb552` | A — tenant isolation | adds `test:unit` + CI step (conflicts with #21 → §2) |
| 4 | **#14** | `fix/landing-gateway-copy` | `2bad98d` | C — honesty | fast-forward from #16 |
| 5 | **#12** | `fix/phase5-student-api` | `28fa2dc` | B — student UX | fast-forward from #14; touches `my-learning` (recheck #21 tests) |
| 6 | **#5** | `fix/phase2-worksheets-private-files` | `f9952c6` | **A — P0 data exposure** | `routes/api.php` merge |
| 7 | **#6** | `fix/phase3-signed-playback` | `51b9a16` | A — leak prevention | isolated |
| 8 | **#7** | `fix/phase3-live-usage-notify` | `7219258` | B | resolve `LiveSessionController`/test with #8 |
| 9 | **#8** | `fix/phase3-recording-attach` | `1164dd6` | B | resolve with #7; `routes/api.php` |
| 10 | **#10** | `fix/phase4-card-expiry-job` | `bb84da6` | B | isolated |
| 11 | **#11** | `fix/phase4-cards-export` | `f110d2a` | B | resolve `ActivationCardController` with #9 |
| 12 | **#13** | `fix/phase5-teacher-onboarding-nav` | `6e672d8` | C — teacher UX | `routes/api.php`; then #21 |
| 13 | **#17** | `fix/recording-claims-honesty` | `a93fd51` | C — honesty | isolated |
| 14 | **#21** | `fix/phase5-frontend-images-tests` | `090db5b` | C — tests/perf | resolve `package.json`, `ci.yml`, teacher dashboard with #16/#13 |
| 15 | **#18** | `chore/phase8-cloudflare-caddy-docs` | `6b76553` | D — docs | no code |
| 16 | **#19** | `chore/phase6-pricing-study` | `5ec4839` | D — docs | recommendation only |
| 17 | **#20** | `chore/launch-hardening-report` | `e0f8ef4` | D — docs | report |

**Priority reading:** Wave **A** (#9, #15, #16, #5, #6) is the security / cost /
tenant-isolation core and should land first. Wave **B** is correctness. Wave
**C** is UX + honesty + tests. Wave **D** is documentation.

> **Important:** the *actual* video-minute caps and cost-rule corrections are
> **not in any of these PRs.** They change production data and require owner
> approval (§6). These PRs only fix the engines those limits run on.

---

## 4. Verification after each merge

On `integration/launch-2026-11`, after every single merge:

```bash
# backend
vendor/bin/phpunit --testsuite=Feature
vendor/bin/phpunit --testsuite=Unit

# frontend
cd frontend
npm ci --legacy-peer-deps
npx tsc --noEmit
npm run test:unit          # script tests + vitest components
NEXT_PUBLIC_API_URL=/api/v1 NEXT_PUBLIC_PLATFORM_DOMAIN=fahimni.me npx next build
```

CI (`.github/workflows/ci.yml`) runs the same backend + frontend jobs on every
PR, so each merge also carries a CI gate. **Stop the line on any red.**

Post-merge recheck specifically for #21's tests: they exercise
`my-learning/page.tsx` (#12) and `teacher/dashboard/page.tsx` (#13) — run
`npm run test:ui` again after #12 and #13 land and fix any assertion that the
new markup invalidates.

---

## 5. Release & deploy (gated, one command per deploy)

Nothing in §3 reaches users by itself.

1. **Owner approves the release** and merges **PR #4**
   (`integration/launch-2026-11` → `main`). This is the only path to production.
2. **Backup first** (owner): DB dump + current image tag recorded, so rollback is
   "redeploy the previous tag".
3. **Exactly one root command** to deploy (owner-confirmed host path — the deploy
   script used by this stack):
   ```bash
   sudo bash /home/fahimni/fahimni-deploy.sh      # confirm the host path with the owner
   ```
   (If the release is bundled with the Phase 1 brand change, use the dedicated
   brand rollout command instead — not both.)
4. **Health checks after deploy:** `/up`/health endpoint 200, `/api/v1/public/plans`
   JSON, `/logo.png` hash, a teacher login, a student `my-learning` load, a
   worksheet download, and one live-session create.
5. **Rollback:** re-run the deploy command with the recorded previous `IMAGE_TAG`
   (the migration set is additive; no destructive migrations in these PRs).
6. **LooksLoop / Staging:** untouched throughout — no DNS, Caddy, firewall or
   compose changes to shared services.

---

## 6. Not in scope of these PRs — needs owner approval

| Item | Where | Action |
|---|---|---|
| Add `delivery_minutes_limit` (+ `live_minutes_limit`) caps | `SubscriptionPlanSeeder` | apply per `docs/PRICING_STUDY.md` on Sandbox first |
| Seed `CostRulesSeeder` (cost engine may report $0 if `provider_cost_rules` is empty) | `php artisan db:seed --class=CostRulesSeeder --force` | idempotent, safe |
| Correct Bunny delivery rate (MENA `$0.06/GB` vs stored `$0.01/GB`) | `provider_cost_rules` | owner edits the rule |
| Resolve live `SubscriptionPlanSeeder` vs dormant `PlansSeeder` | seeders | owner decision |
| Billing enablement | Freemius | **do not enable** until Sandbox E2E passes |
| Cloudflare DNS / firewall / WAF | Phase 8 docs | dry-run script only; gated on explicit approval |

---

## 7. Risks / unknowns

- **Stack merge semantics:** #15/#16/#14/#12 are supersets of earlier members —
  if any is merged out of order or closed, re-derive the remaining diff from the
  branch itself (`git diff integration/launch-2026-11...<branch>`).
- **`routes/api.php`** is edited six times; expect a conflict each time. Keep the
  union of routes; no route is removed by any PR.
- **Frontend tests depend on markup** from #12/#13 — treat a post-merge test
  failure as an assertion to update, not a code regression, unless behaviour
  changed.
- **`sharp`** ships via `next` (lockfile carries `sharp-linuxmusl-x64`), so the
  new `next/image` usage needs no extra production dependency.
- Host deploy script paths are owner-owned; confirm before running.
