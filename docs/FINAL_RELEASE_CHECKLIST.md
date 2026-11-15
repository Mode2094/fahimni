# FINAL RELEASE CHECKLIST — منصة فهمني

**Release candidate:** `release/launch-2026-11-rc`
**Code-complete tip (what gets deployed):** `b41494d1c18d0db022cb44dd1e37518c03a084a4`
**Docs commit:** on top of the tip (documentation only — not required in the image)
**Status:** **READY TO DEPLOY PENDING APPROVAL** — do **not** run anything in §5 or §6
before the go/no-go in §8.

---

## 0. What this release is

- New identity «منصة فهمني» / Fahimni (presentation only — no DB/API renames).
- **P0 fix:** Google + email/password login session (public-origin BFF) **plus**
  host-header spoofing hardening.
- **P1:** original landing page restored; **only** the Hero redesigned (PR #25 not merged).
- Provider cost rules actually seeded; last stale brand string removed;
  assignment worksheets can attach to a lesson (needs an approved migration — §4A).
- Nothing older overwrites newer fixes: 17 approved branches merged, `fix/landing-redesign`
  (PR #25) deliberately excluded.

---

## 1. Pre-deploy verification (all green on this tree)

| Gate | Result |
| --- | --- |
| PHPUnit (full) | **389 / 389** |
| `npx tsc --noEmit` | clean |
| Frontend node tests | **26 / 26** |
| Frontend vitest | **20 / 20** |
| `next build` | success (89 pages + 8 handlers) |
| `bash -n deploy/scripts/*.sh` | clean |
| Brand-rollout tests | all pass (incl. T6b + branch override) |
| Landing before/after screenshots | captured (`docs/screenshots/hero/`) |
| Deploy script installed on host | `/home/fahimni/fahimni-brand-rollout.sh` (36 146 B, mode 700, previous copies backed up as `…bak-*` / `…pre-nginxfix.bak`) |

---

## 2. Deploy path (single host script)

`/home/fahimni/fahimni-brand-rollout.sh` (root-run) will:

1. preflight; record LooksLoop baseline (must stay identical)
2. back up `/etc/fahimni/production.env` → `backups/production.env.<ts>.bak`
3. **clone `release/launch-2026-11-rc` and `checkout` the RC sha** (new:
   `FAHIMNI_RELEASE_BRANCH` / `FAHIMNI_RELEASE_SHA`), then hard-fail if the tree
   has no `frontend/src/lib/request-origin.ts` (**refuses to redeploy the broken
   session state**) or is dirty
4. brand-audit the source (old name only inside the two remap helpers)
5. build images for tag `prod-brand1`
6. verify the brand + logo hashes **inside the built images**
7. repoint `APP_NAME` / `MAIL_FROM_NAME` in the env file to the new identity
8. `docker compose up -d --no-deps` on **only** `app frontend scheduler horizon`
9. `nginx -t && nginx -s reload` (re-resolve upstreams — no 502)
10. readiness gate: containers running+healthy, port listening, DNS resolves
11. re-verify **CSP nonce** (`/teacher/dashboard`) and the **Google redirect** (must not regress)
12. brand verification on the live stack (13 core pages 200 + new identity,
    `platform-settings`, web manifest, served logo hash, tab title)
13. LooksLoop after == baseline
14. bookkeeping (`.deployed-tag`, `.previous-tag`, `current` symlink)

**Never touched:** DNS, Caddy, LooksLoop, Staging, the nginx *configuration*,
postgres, redis, the `migrate` service, Freemius, prices, subscriptions, user data,
the production database.

---

## 3. Rollback

The script auto-rolls-back on **any** health failure and prints exact revert
commands (env restore + previous tag `up -d --no-deps` + nginx reload). Manual
rollback uses the standard tooling (`deploy/scripts/rollback.sh`), which also
re-checks nginx and re-verifies the restored version's health.

---

## 4. APPROVAL GATES (each needs an explicit yes)

### 4A — **Schema change** (blocks a clean deploy)

`database/migrations/2026_11_16_000001_add_lesson_id_to_assignments.php`

- **What:** adds a **nullable** `lesson_id` (uuid, FK → `lessons.id`, `nullOnDelete`)
  plus index `(lesson_id, sort_order)` to `assignments`.
- **Impact:** purely **additive**; every existing row stays valid (NULL = course-level
  assignment). **Why it is required:** `AssignmentResource` emits `lesson_id`
  unconditionally, so without the column **assignment endpoints return 500** on the
  new code. No data is modified or deleted. No tenant/user data touched.
- **Rollback:** `down()` drops FK + index + column (assignment rows survive; they
  lose only the lesson link, which did not exist before).
- **Rule:** `migrate --force` is **forbidden** to run automatically. The deploy script
  deliberately does **not** run the `migrate` service. Run it only on approval.

### 4B — **Permission data** (needed for card CSV export)

`RolePermissionSeeder` defines `cards.export` and grants it to `Teacher`. Re-running
it is **idempotent** (`findOrCreate` + `syncPermissions`) and **additive** — it does
not remove any existing permission or role. Without it, teachers get `403` on
`GET /api/v1/cards/export`.

### 4C — **Deploying the RC branch** (not the default brand commit)

The host script now accepts `FAHIMNI_RELEASE_BRANCH` + `FAHIMNI_RELEASE_SHA`; the
default (`dd13c63` on `integration/launch-2026-11`) stays intact as a safe fallback.

---

## 5. THE DEPLOY COMMAND (run only after approval)

```bash
sudo env FAHIMNI_RELEASE_BRANCH=release/launch-2026-11-rc \
         FAHIMNI_RELEASE_SHA=b41494d1c18d0db022cb44dd1e37518c03a084a4 \
     bash /home/fahimni/fahimni-brand-rollout.sh
```

(One command. `env` is used so the overrides survive `sudo`.)

---

## 6. POST-DEPLOY STEPS (each gated by §4)

**(1) Schema — only if 4A approved** (run immediately after §5 so the assignment
endpoints have their column):

```bash
sudo bash -c 'cd /opt/fahimni/production/current && \
  IMAGE_TAG=prod-brand1 docker compose --env-file /etc/fahimni/production.env \
  run --rm migrate'
```

`docker/migrate.sh` runs `migrate --force` + `plans:sync`; it seeds **only** when the
users table is empty, so it will not re-seed. `plans:sync` without `--force` does
**not** touch existing plans or prices — no selling-price change.

**(2) Permissions — only if 4B approved:**

```bash
sudo bash -c 'cd /opt/fahimni/production/current && \
  IMAGE_TAG=prod-brand1 docker compose --env-file /etc/fahimni/production.env \
  exec -T app php artisan db:seed --class=Database\\Seeders\\RolePermissionSeeder --force'
```

---

## 7. POST-DEPLOY VERIFICATION (manual, outside the script)

| Check | Expectation |
| --- | --- |
| `https://fahimni.me/` | 200; title + logo = «منصة فهمني» |
| `/api/v1/platform-settings` | `platform_name` reads as the new name in the UI |
| Core pages | `/teacher/login`, `/student/login`, `/admin/login`, `/register/*` all 200 |
| CSP | no `unsafe-inline` regression; page loads with nonce |
| Logo/PWA | served icon + manifest show the new identity |
| LooksLoop / Staging | unchanged from baseline |
| Containers | `app frontend scheduler horizon` on `prod-brand1`, healthy |
| Rollback markers | `current` → new release; `.previous-tag` = `prod-hotfix1` |

### THE ONE REQUIRED HUMAN TEST — Google login (cannot be automated)

1. Open `https://fahimni.me/teacher/login` → **Continue with Google**.
2. Complete Google consent with a real account.
3. The callback must land you on **`/teacher/dashboard`** (teacher) — student →
   `/my-learning`, admin → `/admin/dashboard`.
4. **Refresh the page**: the session must survive (still logged in).
5. Confirm no «تعذر إنشاء الجلسة» screen.

This is the single item that cannot be verified from CI; everything up to the live
Google consent is unit-covered.

---

## 8. GO / NO-GO

**GO if:** §1 is green (it is), and approvals 4A + 4B + 4C are given, and a valid
pre-deploy DB backup exists.

**Backup check before GO:** confirm the latest backup is fresh and restore-tested
(`docs/backup-strategy.md`, `deploy/scripts/restore-drill.sh`); the deploy script
backs up the env file itself.

**NO-GO / stop if:** any §1 gate regresses, the env backup cannot be written, the
LooksLoop baseline differs, or approvals are withheld (then §5/§6 must not run).

---

## 9. DO-NOT-TOUCH (enforced)

- Delete data · `migrate:fresh` · `docker compose down -v` · replace the DB.
- Change DNS / Caddy / Firewall / Cloudflare WAF (review only).
- Edit selling prices or existing-customer entitlements (separate proposal).
- Enable Freemius or any real payment.
- Move auth tokens into URLs or `localStorage`; disable any security control.
- Deploy an older tree over newer fixes (the script hard-fails on a checkout without
  the Google/session fix).
- Impact LooksLoop or Staging.

---

## 10. Open items carried into release (documented, not blocking the brand/session fix)

| # | Item | Owner action |
| --- | --- | --- |
| O1 | `video_watch_minutes_limit` missing from FeatureCatalog (falls back to delivery) | separate pricing proposal |
| O2 | `PlansSeeder` vs `SubscriptionPlanSeeder` mismatch | separate proposal |
| O3 | `CostRulesSeeder` uses EU/US Bunny rates, not MENA | separate proposal |
| O4 | `SANCTUM_STATEFUL_DOMAINS` lacks `*.fahimni.me` (tenant subdomain BFF calls non-stateful) | env change, approval |
| O5 | `cards.export` needs §6(2) | covered by gate 4B |
