# FINAL RELEASE CHECKLIST — منصة فهمني

**Release branch (carries the final SHA):** `fix/launch-blockers-rc`
**Base (RC) code SHA:** `b41494d1c18d0db022cb44dd1e37518c03a084a4`
**Final unified SHA (pinned — CI-green):** `bba55f46eca588cbf0edc01c8be8d7f06abfdab4`
**P0 fixes in this revision:**
- Migration preflight no longer parses `migrate:status`. Laravel 13's
  `Migrator::getMigrationName()` strips `.php`, so the old parser could miss
  **every** pending migration and let an unapproved one through; it now diffs the
  migration **files** against the `migrations` table via a new read-only
  `migrations:pending` command and **fails closed** on anything unparsable.
- A new `release_sha_on_branch` guard refuses to deploy when
  `FAHIMNI_RELEASE_SHA` is not reachable from `FAHIMNI_RELEASE_BRANCH` (the clone
  is `--single-branch`, so a sibling-branch SHA is not fetchable).
- The release-source brand audit now scans only **user-visible** trees
  (`frontend/src`, `frontend/public`, `app`, `resources`, `public`, `routes`,
  `config`) and no longer aborts on the old name inside `docs/`, the deploy
  tooling/tests, or the detection greps themselves — a pattern that *looks for*
  the old name is not a user-visible string. A genuinely user-visible old name
  still fails, and the live surfaces are verified after deploy.
- That same audit no longer **silently aborts the rollout on a clean tree**.
  `grep -v` exits 1 whenever it filters out every line, and in a good release the
  only old-name matches are the two remapping helpers — so `pipefail` made
  `brand_source_audit()` return 1 and `set -e` aborted at
  `offenders="$(brand_source_audit "$REL")"` with nothing printed. It now returns
  0 on a clean tree and still rejects every real offender (regression-tested in
  both directions).
- The isolated restore drill accepts a local plain `.dump` only through its
  explicit `--allow-unencrypted` flag (scoped to `--file`). It is no longer
  acknowledged via the `BACKUP_ALLOW_UNENCRYPTED` env var, which
  `production.env` sets to `0` and which `load_env_file` re-sourced **after** the
  runner exported `1` — that clobbering is what produced `refusing a plain .dump
  without BACKUP_ALLOW_UNENCRYPTED=1`. The off-site path never passes the flag
  and unencrypted archives are still never uploaded.
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

**Read-only production DB check (prepared — run before approving; nothing is written).**
Requires docker/root on the host (owner-only). Run from the live release dir:

```bash
cd /opt/fahimni/production/current
# 1. Our own read-only view: files on disk vs the `migrations` table, names WITH .php.
docker compose --env-file /etc/fahimni/production.env run --rm --no-deps app \
  php artisan migrations:pending
# 2. The framework's own view (informational only — names WITHOUT .php).
docker compose --env-file /etc/fahimni/production.env run --rm --no-deps app \
  php artisan migrate:status --pending --no-ansi
```

Expected: `migrations:pending` lists **exactly** the one approved file above (or nothing if
it is already applied). Any other line — or a non-zero exit — is a **NO-GO**. Never the
generic `migrate` service. Paste the raw output into the release report as evidence.

**Restore-tested backup (the evidence behind `FAHIMNI_DB_BACKUP_VERIFIED=1`).** Restores a
backup into a network-isolated throwaway PostgreSQL and **fails if the archive is stale**,
so it proves a *recent* backup of production exists:

```bash
# (a) newest OFF-SITE archive (needs rclone / the daily 02:00 UTC upload):
deploy/scripts/restore-drill.sh --env production --from-remote --max-age-hours 26 --yes

# (b) an explicit LOCAL archive — no Google Drive/rclone needed for this temporary path:
deploy/scripts/restore-drill.sh --env production \
  --file /opt/fahimni/production/backups/db-<UTC>.dump \
  --allow-unencrypted --max-age-hours 26 --yes
```

> **(b) is a local file on the same server and does NOT protect against losing the
> server.** It is accepted only for this temporary path, is never deleted, and every gate
> still requires the real restore below. `--allow-unencrypted` is a per-invocation
> acknowledgement for an explicit `--file` only; it is never read from the env file, so
> `BACKUP_ALLOW_UNENCRYPTED=0` in `production.env` cannot veto it, and it is refused for
> `--from-remote`. Unencrypted archives are still never *uploaded* (see `backup-offsite.sh`).

Required evidence (paste into the release report — do **not** set
`FAHIMNI_DB_BACKUP_VERIFIED=1` without it): the `backup freshness ok: <N>h old (limit 26h)`
line, the `restore drill: OK` line, and the `migrations=… users=… tenants=… size=…` line,
plus the archive name (it carries the UTC stamp). Production is never contacted or
modified by the drill.

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

The blockers work lives on `fix/launch-blockers-rc`, which is **one commit ahead of**
`release/launch-2026-11-rc`. The deploy must therefore name **that** branch, because the
clone is `--single-branch`: the target SHA is only fetchable from the branch that actually
contains it. `release_sha_on_branch()` enforces this and aborts before checkout when
`FAHIMNI_RELEASE_SHA` is not reachable from `FAHIMNI_RELEASE_BRANCH`.

`FAHIMNI_RELEASE_BRANCH` + `FAHIMNI_RELEASE_SHA` override the clone target; the default
(`dd13c63` on `integration/launch-2026-11`) stays intact as a safe fallback.

**Final unified SHA — pinned literally. Never deploy a branch tip** (it moves; the SHA
does not):

```
bba55f46eca588cbf0edc01c8be8d7f06abfdab4
```

Confirm it is on the branch — read-only, and exactly what `release_sha_on_branch()`
enforces before checkout:

```bash
# from a clean single-branch clone of fix/launch-blockers-rc
git merge-base --is-ancestor bba55f46eca588cbf0edc01c8be8d7f06abfdab4 \
  refs/remotes/origin/fix/launch-blockers-rc && echo "on-branch OK"
```

**Deploy-script integrity gate.** The reviewed script is:

- `sha256 = 91ae95e28b092f5ca90e6770bd7c72c1ab7f4294b639ce58eb129818a50bbf4d`
- 50 590 bytes, at `/home/fahimni/fahimni-brand-rollout.sh` (mode 700, root-run via `sudo`).

**Runner fingerprint.** The single go-live runner on the host is:

- `sha256 = b35c11fce1a366acd56d6c330df9b733676e15ae0c3d8419815f75c3c7be9edd`
- 28 438 bytes, at `/home/fahimni/fahimni-golive.sh` (mode 700, `fahimni`-owned, run via `sudo`).
- It pins `bba55f4…` / `fix/launch-blockers-rc` and the deploy-script `sha256` above; it
  re-derives the deploy-script hash from the pinned commit and refuses to run a host copy
  that does not match.

Before running §5, confirm the host copy is byte-identical to the approved one:

```bash
deploy/scripts/verify-deploy-script.sh \
  --git-ref bba55f46eca588cbf0edc01c8be8d7f06abfdab4 \
  --remote fahimni@187.127.87.101 \
  --expect 91ae95e28b092f5ca90e6770bd7c72c1ab7f4294b639ce58eb129818a50bbf4d
```

A mismatch aborts (exit 1); an unfetchable side is exit 2 (never treated as a pass).
If the host copy is still the previous revision, install the reviewed one first (as
`fahimni`, no root and no service restart):

```bash
scp deploy/scripts/fahimni-brand-rollout.sh \
  fahimni@187.127.87.101:/home/fahimni/fahimni-brand-rollout.sh
```

Install the single go-live runner alongside it (mode 700, `fahimni`-owned):

```bash
scp deploy/scripts/fahimni-golive.sh fahimni@187.127.87.101:/home/fahimni/fahimni-golive.sh
ssh fahimni@187.127.87.101 'chmod 700 ~/fahimni-golive.sh'
```

---

## 5. THE SINGLE ROOT COMMAND (ready — **not executed**)

After §4A + §4B are approved **and** a restore-tested backup exists, this one command
performs the whole release (approved migration → permission fix → brand rollout):

```bash
sudo env \
  FAHIMNI_RELEASE_BRANCH=fix/launch-blockers-rc \
  FAHIMNI_RELEASE_SHA=bba55f46eca588cbf0edc01c8be8d7f06abfdab4 \
  FAHIMNI_ALLOW_DB_WRITE=1 \
  FAHIMNI_DB_BACKUP_VERIFIED=1 \
  FAHIMNI_ALLOW_PERMISSION_WRITE=1 \
  bash /home/fahimni/fahimni-brand-rollout.sh
```

- It makes **no** production change until an operator runs it. Do not run it before the
  approvals in §4 and the backup check in §7.
- The SHA above is **pinned literally** and must be used as-is — **never** substitute a
  branch tip or a `git ls-remote` result. It must be a commit reachable from
  `FAHIMNI_RELEASE_BRANCH`; the script refuses to run otherwise (the clone is
  `--single-branch`).
- The migration preflight uses the read-only `migrations:pending` command (files vs the
  `migrations` table) and fails closed — it no longer reads `migrate:status`, whose Laravel-13
  output drops the `.php` extension.

### 5.1 The single go-live runner (recommended — one command does everything)

`deploy/scripts/fahimni-golive.sh` chains **pre-flight → backup → isolated restore drill →
deploy → verify** with a safe stop at every gate. It is the one command to run; it verifies
the host script's SHA256, pins the approved SHA/branch, ensures a recent restorable backup,
and only then passes `FAHIMNI_DB_BACKUP_VERIFIED=1` to the rollout above. It never retries a
failed deploy, never rolls the schema back, and never touches DNS/Caddy/LooksLoop/Staging.

```bash
# LOCAL-backup path (no Google Drive required) — the one command to run:
sudo bash /home/fahimni/fahimni-golive.sh \
  --backup-file /opt/fahimni/production/backups/db-20261116T191419Z.dump
```

Off-site path (when rclone/Google Drive is configured) — the same runner, newest archive:

```bash
sudo bash /home/fahimni/fahimni-golive.sh
```

Dry gate check that stops before the deploy (pre-flight + backup + drill only):

```bash
sudo bash /home/fahimni/fahimni-golive.sh \
  --backup-file /opt/fahimni/production/backups/db-20261116T191419Z.dump --dry-run
```

- `--backup-file PATH` runs the isolated restore on **that** local archive (integrity
  pre-check → real `restore drill: OK` → row counts) and **never deletes** it; the plain
  `.dump` is acknowledged with the drill's explicit `--allow-unencrypted` flag, passed by
  the runner for exactly this `--file` invocation. That flag is **not** read from
  `production.env`, so `BACKUP_ALLOW_UNENCRYPTED=0` there cannot veto it, and the off-site
  path never passes it. A local file on the same server does **not** protect against
  losing the server.

Every run writes `<stack>/backups/golive-<UTC>.log` and a `<stack>/backups/golive-<UTC>.result`
summary (status, deployed SHA, backup/drill evidence, smoke result). Exit codes:
`0` LIVE · `1` SAFE STOPPED · `2` ROLLED BACK · `3` LIVE with anomalies.

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
| Migration | `assignments.lesson_id` exists; `php artisan migrations:pending` lists nothing unapproved |
| Permission | `cards.export` granted to Teacher; other roles/permissions untouched |
| LooksLoop / Staging | unchanged from baseline |

---

## 7. GO / NO-GO

**GO if:** §1 is green (it is), approvals **4A + 4B + 4C** are given, a valid
**restore-tested** DB backup exists, and **every BLOCKED item in §6.2 has been driven
with a real account/provider**.

**Backup check before GO:** confirm the latest backup is fresh and restore-tested — either
the newest **off-site** archive:
`deploy/scripts/restore-drill.sh --env production --from-remote --max-age-hours 26 --yes`
or an explicit **local** archive (no Google Drive required for this temporary path):
`deploy/scripts/restore-drill.sh --env production --file /opt/fahimni/production/backups/db-<UTC>.dump --allow-unencrypted --max-age-hours 26 --yes`
(see §4A for the exact evidence to record). The drill refuses a stale archive, so a GO
without its `restore drill: OK` + freshness output is not allowed — `FAHIMNI_DB_BACKUP_VERIFIED=1`
must never be set on the strength of the variable alone. A locally-stored archive does **not**
protect against losing the server; keep an off-site copy too. The deploy script also backs up
the env file itself.

**NO-GO / stop if:** any §1 gate regresses; the env backup cannot be written; the
LooksLoop baseline differs; an **unapproved** pending migration is detected; the
permission diff is not confined to `cards.export`/Teacher; the restore drill cannot prove a
recent backup; or approvals are withheld (then §5 must not run).

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
