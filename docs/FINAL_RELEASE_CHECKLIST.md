# FINAL RELEASE CHECKLIST — منصة فهمني

**Release branch:** `release/launch-2026-11-rc`
**Base (RC) code SHA:** `b41494d1c18d0db022cb44dd1e37518c03a084a4`
**Blockers-only commit (this work):** `__BLOCKERS_SHA__`
**Status:** **NO-GO for deploy — blockers closed in code, awaiting the owner's approval.**
Nothing in §5 or §6 has been executed.

---

## 0. What this release is

- New identity «منصة فهمني» / Fahimni (presentation only — no DB/API renames).
- **P0 fix:** Google + email/password login session (public-origin BFF) **plus**
  host-header spoofing hardening.
- **P1:** original landing page restored; **only** the Hero redesigned (PR #25 not merged).
- Provider cost rules correctly seeded for a *fresh* DB; last stale brand string
  removed; assignment worksheets can attach to a lesson (needs an **approved**
  migration — §4A).
- Nothing older overwrites newer fixes: approved branches merged, `fix/landing-redesign`
  (PR #25) deliberately excluded.

### Blockers-only work in this commit (before → after)

| Blocker | Before | After |
| --- | --- | --- |
| 1. Migration before cutover | Deploy replaced containers first; the column did not exist → new code 500s | The deploy script runs a **read-only preflight**, then applies **only the allowlisted** migration from the new image **before cutover**; the generic `migrate` service is never used |
| 2. `RolePermissionSeeder` hot-fix | Recommended running the whole seeder (`syncPermissions()` → not additive) | New **additive-only** `permissions:ensure-cards-export` (dry-run by default; creates/grants `cards.export` to Teacher only); the full seeder is **not** a prod step |
| 3. Cost rules | Ambiguous whether to run `CostRulesSeeder` | **Gap report only** (`docs/COST_RULES_GAP_REPORT.md`); seeder is never run in prod |
| 4. Deploy script integrity | Host copy could drift from the reviewed file | `verify-deploy-script.sh` SHA256-gates host vs Git; DB/permission writes are gated; rollback never reverts the schema |
| 5. Launch smoke tests | None | `launch-smoke-test.sh` covers every critical journey; account/provider halves reported **BLOCKED** |

---

## 1. Pre-deploy verification (all green on this tree)

| Gate | Result |
| --- | --- |
| PHPUnit (full) | **394 / 394** |
| `npx tsc --noEmit` | clean |
| Frontend node tests | **26 / 26** |
| Frontend vitest | **20 / 20** |
| `next build` | success |
| `shellcheck -x -P deploy/scripts deploy/scripts/*.sh deploy/tests/*.sh docker/migrate.sh` | **clean** |
| `bash -n deploy/**/*.sh` | clean |
| Brand-rollout tests (`deploy/tests/brand-rollout-nginx-test.sh`) | all pass — incl. **T12–T21** (DB preflight / approved migration / permission gating / sequence / rollback does not revert the schema) |
| Deploy-script integrity test (`deploy/tests/verify-deploy-script-test.sh`) | all pass |
| Launch smoke list test (`deploy/tests/launch-smoke-list-test.sh`) | all pass |
| `permissions:ensure-cards-export` unit tests | **5 / 5** (`tests/Feature/EnsureCardsExportPermissionTest.php`) |
| Landing before/after screenshots | captured (`docs/screenshots/hero/`) |

---

## 2. Deploy path (single host script)

`/home/fahimni/fahimni-brand-rollout.sh` (root-run) will:

1. preflight; record the LooksLoop baseline (must stay identical)
2. back up `/etc/fahimni/production.env` → `backups/production.env.<ts>.bak`
3. clone/checkout the release (`FAHIMNI_RELEASE_BRANCH` / `FAHIMNI_RELEASE_SHA`),
   hard-fail if the tree lacks `frontend/src/lib/request-origin.ts` (the Google/session
   fix) or is dirty
4. brand-audit the source; build images for tag `prod-brand1`
5. verify the brand + logo hashes **inside the built images**
6. **DB preflight (read-only, always):** list the pending migrations reported by the
   new image and compare them to `FAHIMNI_APPROVED_MIGRATIONS`. Any **unapproved**
   pending migration aborts **before anything is touched**; an unreadable status is a
   hard failure (never "nothing pending")
7. **Apply the approved migration(s)** — one `migrate --force --path=database/migrations/<file>`
   per allowlisted file, from the new image, **before the cutover** — only when
   `FAHIMNI_ALLOW_DB_WRITE=1` **and** `FAHIMNI_DB_BACKUP_VERIFIED=1`. The generic
   `docker compose run migrate` service (which would run *every* pending migration and
   `plans:sync`) is **never** used
8. **Permission fix:** print the expected `cards.export` diff (read-only), then apply
   the additive-only change only when `FAHIMNI_ALLOW_PERMISSION_WRITE=1`
9. repoint `APP_NAME` / `MAIL_FROM_NAME` to the new identity
10. `docker compose up -d --no-deps` on **only** `app frontend scheduler horizon` (cutover)
11. `nginx -t && nginx -s reload` (re-resolve upstreams — no 502)
12. readiness gate: containers running+healthy, port listening, DNS resolves
13. re-verify **CSP nonce** (`/teacher/dashboard`) and the **Google redirect**
14. brand verification on the live stack (core pages 200 + new identity,
    `platform-settings`, web manifest, served logo hash, tab title)
15. LooksLoop after == baseline; bookkeeping (`.deployed-tag`, `.previous-tag`, `current`)

**Never touched:** DNS, Caddy, LooksLoop, Staging, the nginx *configuration*, redis,
the generic `migrate` service, Freemius, prices, subscriptions, user data. The DB is
written **only** by steps 7–8 and **only** when explicitly approved; with the gates
unset the script is read-only against the database.

---

## 3. Rollback

The script auto-rolls-back on **any** health failure (restores the env file + previous
images + `nginx -s reload`) and prints the exact revert commands.

**Database note (documented, required):** a rollback **keeps** the applied column. The
migration adds a **nullable** `assignments.lesson_id`; the previous release's code never
reads it, so the old image keeps working with the column present. The column is reverted
**only** by a deliberate, manual, separate action — never by the automatic rollback.
This is printed into the rollback note (`backups/rollback-<tag>.<ts>.txt`).

Manual rollback still uses `deploy/scripts/rollback.sh`.

---

## 4. APPROVAL GATES (each needs an explicit yes)

### 4A — **Approved migration** (applied by the deploy script, before cutover)

`database/migrations/2026_11_16_000001_add_lesson_id_to_assignments.php`

- **What:** adds a **nullable** `lesson_id` (uuid, FK → `lessons.id`, `nullOnDelete`)
  plus index `(lesson_id, sort_order)` to `assignments`.
- **Why required:** `AssignmentResource` emits `lesson_id` unconditionally; without the
  column the new code's assignment endpoints return 500.
- **Impact:** purely additive; existing rows stay valid (NULL = course-level). No data
  modified or deleted.
- **Applied via:** `docker compose run --rm --no-deps app php artisan migrate --force
  --path=database/migrations/2026_11_16_000001_add_lesson_id_to_assignments.php`
  (inside the script, gated). **Never** the generic `migrate` service.
- **Preconditions:** owner approval **and** a fresh, **restore-tested** DB backup
  (set `FAHIMNI_DB_BACKUP_VERIFIED=1` only after restoring it onto an isolated copy).
- **Rollback:** keeps the column (see §3).

### 4B — **Permission data** (needed for card CSV export)

`RolePermissionSeeder` uses `syncPermissions()` → **not** additive; it must **not** be
run whole in production. Instead the release ships:

```bash
php artisan permissions:ensure-cards-export          # dry-run: prints the expected diff
php artisan permissions:ensure-cards-export --apply  # creates cards.export + grants to Teacher only
```

- creates `cards.export` only if missing, grants it to **Teacher** only, never removes or
  re-syncs any other permission, and is idempotent (a second run changes nothing).
- refuses to act if the `Teacher` role is absent.
- Gated by `FAHIMNI_ALLOW_PERMISSION_WRITE=1`.

### 4C — **Deploying the RC branch** (not the default brand commit)

`FAHIMNI_RELEASE_BRANCH` + `FAHIMNI_RELEASE_SHA` override the clone target; the default
(`dd13c63` on `integration/launch-2026-11`) stays intact as a safe fallback.

**Deploy-script integrity gate.** The reviewed script is:

- `sha256 = 0c2295953e03257c59aa0029f7e952819691c334e591591b6df28ec0593f5b45`
- 45 120 bytes, at `/home/fahimni/fahimni-brand-rollout.sh` (mode 700, root-run via `sudo`).

Before running §5, confirm the host copy is byte-identical to the approved one:

```bash
deploy/scripts/verify-deploy-script.sh \
  --git-ref <sha-on-the-branch> \
  --remote fahimni@187.127.87.101 \
  --expect 0c2295953e03257c59aa0029f7e952819691c334e591591b6df28ec0593f5b45
```

A mismatch aborts (exit 1); an unfetchable side is exit 2 (never treated as a pass).

---

## 5. THE SINGLE ROOT COMMAND (ready — **not executed**)

After §4A + §4B are approved **and** a restore-tested backup exists, this one command
performs the whole release (approved migration → permission fix → brand rollout):

```bash
sudo env \
  FAHIMNI_RELEASE_BRANCH=release/launch-2026-11-rc \
  FAHIMNI_RELEASE_SHA=__BLOCKERS_SHA__ \
  FAHIMNI_ALLOW_DB_WRITE=1 \
  FAHIMNI_DB_BACKUP_VERIFIED=1 \
  FAHIMNI_ALLOW_PERMISSION_WRITE=1 \
  bash /home/fahimni/fahimni-brand-rollout.sh
```

- It makes **no** production change until an operator runs it. Do not run it before the
  approvals in §4 and the backup check in §7.
- Set `FAHIMNI_RELEASE_SHA` to the final SHA on the branch that carries this work
  (the merge commit on `release/launch-2026-11-rc`, or the blockers commit above).

**Read-only rehearsal** (safe; no DB or permission write, still builds and deploys the
brand — use it only if a real cutover rehearsal is intended). For an even safer dry
check without deploying, run the verifier and the smoke script first:

```bash
deploy/scripts/verify-deploy-script.sh --git-ref <sha> --remote fahimni@<host> --expect <sha256>
deploy/scripts/launch-smoke-test.sh --base https://fahimni.me --host-header fahimni.me
```

---

## 6. POST-DEPLOY VERIFICATION

### 6.1 Scripted launch smoke test (critical journeys)

```bash
bash deploy/scripts/launch-smoke-test.sh --base https://fahimni.me
```

Covers, read-only: platform health; branding «منصة فهمني» + served logo hash;
CSP nonce; PWA manifest/icons; core page links; and the **public guard** of each
authenticated journey (Google redirect returns `client_id`; role-gated endpoints
answer 401/403 without a session). Every journey that needs a real account or an
external provider is reported **BLOCKED** (see §6.2). Add `--allow-login-probe` to
additionally confirm the login endpoint rejects bad credentials with 401/422.

### 6.2 BLOCKED items — the human click-through required before GO

| Journey | Why BLOCKED |
| --- | --- |
| Google register → teacher dashboard → refresh | needs a real Google account |
| Email/password login | needs real credentials |
| Admin + student login | needs real accounts |
| Create course + lesson + worksheet | needs an authenticated teacher |
| Generate / export / redeem a card | needs a teacher + the `cards.export` grant |
| Video + live access by permission | needs real enrolled users |
| Google OAuth consent screen («تعذر إنشاء الجلسة» must not appear) | external provider |
| Bunny transcode of a real upload | external provider |
| Custom-domain DNS / Freemius checkout | out of scope for this release |

These are the **exact** steps a human must complete (from `docs/FINAL_E2E_TEST_MATRIX.md`)
before the release can be declared GO.

### 6.3 Quick manual table

| Check | Expectation |
| --- | --- |
| `https://fahimni.me/` | 200; title + logo = «منصة فهمني» |
| `/api/v1/platform-settings` | `platform_name` = new name |
| Core pages | `/teacher/login`, `/student/login`, `/admin/login`, `/register/*` all 200 |
| CSP | nonce present; no `unsafe-inline` regression |
| Logo/PWA | served icon + manifest show the new identity |
| Containers | `app frontend scheduler horizon` on `prod-brand1`, healthy |
| Migration | `assignments.lesson_id` exists; `migrate:status` shows nothing unapproved pending |
| Permission | `cards.export` granted to Teacher; other roles/permissions untouched |
| LooksLoop / Staging | unchanged from baseline |

---

## 7. GO / NO-GO

**GO if:** §1 is green (it is), approvals **4A + 4B + 4C** are given, a valid
**restore-tested** DB backup exists, and **every BLOCKED item in §6.2 has been driven
with a real account/provider**.

**Backup check before GO:** confirm the latest backup is fresh and restore-tested
(`docs/backup-strategy.md`, `deploy/scripts/restore-drill.sh`); the deploy script also
backs up the env file itself.

**NO-GO / stop if:** any §1 gate regresses; the env backup cannot be written; the
LooksLoop baseline differs; an **unapproved** pending migration is detected; the
permission diff is not confined to `cards.export`/Teacher; or approvals are withheld
(then §5 must not run).

---

## 8. DO-NOT-TOUCH (enforced)

- Delete data · `migrate:fresh` · `migrate:rollback` · `docker compose down -v` · replace the DB.
- The generic `docker compose run migrate` service / `plans:sync` for this release.
- Run `RolePermissionSeeder` (or `DatabaseSeeder`) whole in production.
- Run `CostRulesSeeder` in production, or change any selling price / actual cost.
- Change DNS / Caddy / Firewall / Cloudflare WAF (review only).
- Enable Freemius or any real payment.
- Move auth tokens into URLs or `localStorage`; disable any security control.
- Deploy an older tree over newer fixes (the script hard-fails on a checkout without
  the Google/session fix).
- Impact LooksLoop or Staging.

---

## 9. Open items carried into release

| # | Item | Owner action |
| --- | --- | --- |
| O1 | `video_watch_minutes_limit` missing from FeatureCatalog (falls back to delivery) | `docs/COST_RULES_GAP_REPORT.md` C4 — separate pricing proposal |
| O2 | `PlansSeeder` vs `SubscriptionPlanSeeder` mismatch | separate proposal |
| O3 | `CostRulesSeeder` uses EU/US Bunny rates, not MENA | `docs/COST_RULES_GAP_REPORT.md` — report only, no change |
| O4 | `SANCTUM_STATEFUL_DOMAINS` lacks `*.fahimni.me` (tenant subdomain BFF calls non-stateful) | env change, approval |
| O5 | `cards.export` missing in some DBs | **closed** — additive `permissions:ensure-cards-export` (§4B) |
| O6 | Production `provider_cost_rules` values unverified | run §2 of `docs/COST_RULES_GAP_REPORT.md` |
