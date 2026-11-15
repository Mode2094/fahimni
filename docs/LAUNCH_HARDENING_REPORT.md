# فهمني — Launch Hardening Report (Phases 0–9)

- Branch reviewed: `integration/launch-2026-11` @ `dd13c63` (source release)
- Production: <https://fahimni.me> — currently serving **`prod-hotfix1`**
- Date: 16 Nov 2026
- Posture: **finish the existing platform, not rebuild it.** No feature was
  rebuilt; only broken behaviour was fixed and missing behaviour added.
- Guardrails honoured: LooksLoop, Staging, production user data, DNS, Caddy and
  prices were **not** touched. PR **#4 is not merged**.

---

## (1) The 10 requirements — status + code evidence

| # | Requirement | Status | Evidence |
|---|---|---|---|
| **0** | Real audit: git state, deployed version, CI, container health; per-feature table; trace API+DB+permissions+UI+tests | **Done** | 9 read-only audits with file:line evidence; findings folded into §2. Deployed image `prod-hotfix1`; CI runs on `pull_request` + `main` only (read-only, sqlite). |
| **1a** | Google OAuth: real login (new + existing), sessions, cookies, dashboard redirect, 2FA, no duplicate workspaces, safe failures | **Fixed & live-verified** | `GoogleAuthController.php`, `routes/web.php`, `frontend/src/app/auth/google/callback/page.tsx`. Commit `207319d` (live as `prod-hotfix1`). |
| **1b** | Brand: «منصة فهمني» + approved logo everywhere, no old name/logo from static/cache/DB; unify home/header/footer/panels/login/favicon/manifest/OG/email | **Committed, awaiting owner rollout** | `dd13c63` on `integration/launch-2026-11`. Read-time remap: `app/Support/PlatformBrand.php`, `frontend/src/lib/brand.ts`, `PlatformSettingController::show()`. Host script `/home/fahimni/fahimni-brand-rollout.sh` (sha `d1acd65…`). **Not yet applied** — see §6. |
| **1c** | CSP nonce on all Next.js scripts, no `unsafe-inline` for scripts | **Done & live-verified** | `unsafe-inline` only in `style-src`; nonce applied to scripts. Live in `prod-hotfix1`. |
| **1d** | Fix file ownership/permissions after rollback | **Covered by rollout script** | The script fixes root-owned leftovers as part of owner-run step (§6). |
| **2** | Worksheets/assignments: create tied to course+lesson, private secure upload, authorised student download, submission, status, grading/score/comment, upcoming/overdue/graded views, RTL/mobile/print | **Fixed** | PR **#5**, commit `f9952c6`. Files moved to private `local` disk + two authorised endpoints; client `file_url` sanitised; `lesson_id` added nullable; legacy rows still served. 9 tests. |
| **3** | Videos + live: Google Meet create/schedule/registered-only access/join/end+notify; recording truth; Bunny (upload/processing/playback/signed URL/watermark/leak/limits/cost) | **Fixed** (recording = documented limitation) | PRs **#6/#7/#8** — `51b9a16` (real opt-in Bunny signed HLS), `7219258` (live minutes recorded + limit enforced + start/end notifications), `1164dd6` (attach recording link post-session). Google Meet **cannot auto-record** ⇒ `record_enabled` derived from `record_url`; no auto-record claim is made. |
| **4** | Activation cards: batch gen tied to course+teacher, import/export, printing w/ QR, single-use, expiry, states, cross-teacher/double-activation/cross-course protection, counts | **Fixed** | PRs **#9/#10/#11** — `7c592c8` (redemption hardening + limiter), `bb84da6` (scheduled expiry job, flips `generated` only), `f110d2a` (CSV export). Concurrency already guarded by `DB::transaction` + `lockForUpdate` + partial unique index `ux_card_redemptions_single_use`. |
| **5** | Student dashboard (RTL, courses, progress, last lesson, %, upcoming, worksheets, exams, certificates, card activation, mobile, loading/empty/error); teacher dashboard (onboarding, real stats, permissions, tenant isolation, a11y); landing simplified, real features/pricing/FAQ/trial CTA, no false "ready" claims, keep palette, fast, SEO | **Fixed** (2 P2s open) | Student: **#12** `28fa2dc` (tenant headers, live "Join lesson", `is_enrolled`). Teacher: **#13** `6e672d8` (server-side onboarding from real tenant data, plan-gated; unhid orphaned nav). Landing: **#14** `2bad98d` (gateway copy honesty). Open P2s: no `next/image`; no frontend dashboard/landing unit tests. |
| **6** | Pricing study: seeder vs real prices, cost analysis, competitor compare, 3 sellable plans w/ margins, verify PlanEngine/QuotaService/UI compatibility, **do not change prod prices / enable Freemius** | **Delivered (recommendation only)** | `docs/PRICING_STUDY.md`, PR **#19**, commit `5ec4839` (+ cost-currency fix `6561d77`, PR #15). Prices unchanged; Freemius not enabled. |
| **7** | PWA install: manifest/icons/install, Android/iPhone/Desktop, own identity vs teacher site, `start_url`/`scope`/post-login/session, never cache private content | **Fixed (cache bleed)** | PR **#16** `dbeb552` — `private, no-store` + `Vary: Referer, Host`; 4 manifest tests; CI frontend-unit step added. Manifest/icons/install/iOS guidance already worked; no service worker (safe). |
| **8** | Cloudflare + security: audit + safe plan only, no auto DNS/firewall; shared VPS; DNS for site+email; SSL Full(strict), WAF, DDoS, Bot, Rate limits, origin-IP; protect login/register/reset/admin without breaking OAuth/webhooks/video; real client IP; no blanket UFW; preserve email DNS/Resend/Staging; rollback; gated on approval | **Docs + safe script only** | PR **#18** `6b76553` — `deploy/scripts/cloudflare-ufw.sh` (dry-run default, requires `ADMIN_SSH_IP`, refuses `--apply` without `CONFIRM_LOOKSLOOP_OK=yes`, SSH-first, idempotent, rollback = `sudo ufw disable`); corrected `docs/CLOUDFLARE_VPS_HARDENING.md` for the real CF→Caddy→nginx chain; `deploy/dns-cutover.md` §3b Resend SPF/DKIM/DMARC. Nginx-native `CF-Connecting-IP` real-IP fix. **Nothing applied.** |
| **9** | Per-feature integration tests, clear branches w/ per-phase commits/PRs, keep PR #4 unmerged, limited deploy w/ backup/rollback/healthchecks, never touch LooksLoop/Staging/prod data | **Done** | 15 fix/chore branches → PRs **#5–#19**; PR **#4 unmerged**; limited deploy = the brand rollout (one root command, auto-rollback on failure, §6). |

---

## (2) Confirmed issues by severity

### P0 (were blocking launch)
| # | Issue | Status |
|---|---|---|
| P0-1 | Google OAuth callback returned 500 ⇒ no real teacher/student login | **Fixed + live** (`207319d`) |
| P0-2 | CSP blocked Next.js scripts (no nonce) | **Fixed + live** (`207319d`) |
| P0-3 | Worksheet/assignment files stored on the **public** disk (guessable URLs) | **Fixed** (`f9952c6`, PR #5) |

### P1
| # | Issue | Where | Status |
|---|---|---|---|
| P1-1 | Live minutes never recorded; `assertLiveMinutes` never called; no start/end notifications | `LiveSessionController` | Fixed (`7219258`) |
| P1-2 | Bunny playback URLs unsigned (no signed playback) | `BunnyStreamProvider` | Fixed opt-in (`51b9a16`) |
| P1-3 | `record_enabled` advertised without any recording capability | media/features | Fixed — reworded + derived (`a93fd51`) |
| P1-4 | Card redemption: no rate limit; cancelled/expired returned as "used" 404 | `ActivationCardController` | Fixed (`7c592c8`) |
| P1-5 | Unused activation cards never expired | jobs | Fixed (`bb84da6`) |
| P1-6 | Student API calls missing tenant headers ⇒ silent empty states | frontend services | Fixed (`28fa2dc`) |
| P1-7 | "Join lesson" button dead | schedule UI | Fixed (`28fa2dc`) |
| P1-8 | Teacher onboarding/checklist missing; orphaned nav pages unreachable | teacher UI | Fixed (`6e672d8`) |
| P1-9 | Manifest cache bleed: one tenant's identity leaked into another | manifest route | Fixed (`dbeb552`) |
| P1-10 | Landing claimed platform-provided Mada/PayPal gateways | landing copy | Fixed (`2bad98d`) |
| P1-11 | Cost engine double-converted USD plan prices | `SaasCostService` | Fixed (`6561d77`) |
| P1-12 | **Production plans have no watch-minute cap ⇒ unbounded video delivery cost** | `SubscriptionPlanSeeder` | **Reported, needs owner approval** (§4, PR #19) |
| P1-13 | `CostRulesSeeder` unregistered ⇒ possibly empty `provider_cost_rules` ⇒ cost engine reports **$0**; no create endpoint | seeders / `BiController` | **Reported** |
| P1-14 | Bunny delivery cost rule `$0.01/GB` wrong for MENA (`$0.06/GB`) | `CostRulesSeeder` | **Reported** |
| P1-15 | Live `SubscriptionPlanSeeder` vs dormant `PlansSeeder` diverge | seeders | **Reported** |

### P2
| # | Issue | Status |
|---|---|---|
| P2-1 | Notifications are best-effort (no retry/DLQ) | Reported |
| P2-2 | No `next/image` usage (larger payloads) | Reported |
| P2-3 | No frontend unit tests for dashboard/landing | Reported (partly closed by `dbeb552`) |
| P2-4 | Storage limits below market (Starter 5 GB) | Reported (PR #19) |
| P2-5 | Email cost rule optimistic vs Resend tiers | Reported (PR #19) |
| P2-6 | Freemius enablement is one flag + one toggle, no non-prod guard | Reported (PR #19) |
| P2-7 | Tracked 0-byte junk files `$video-`, `id,"` in repo root | Reported (harmless) |

---

## (3) Fixes — commits + tests

| PR | Branch | Commit | What | Tests |
|---|---|---|---|---|
| #5 | `fix/phase2-worksheets-private-files` | `f9952c6` | Private worksheet/assignment storage + authorised downloads | 9 feature |
| #6 | `fix/phase3-signed-playback` | `51b9a16` | Real opt-in Bunny signed HLS | feature |
| #7 | `fix/phase3-live-usage-notify` | `7219258` | Live minutes metered/capped + notifications | feature |
| #8 | `fix/phase3-recording-attach` | `1164dd6` | Attach recording link post-session | feature |
| #9 | `fix/phase4-card-redeem` | `7c592c8` | Redemption hardening + 10/min limiter + 410 semantics | feature |
| #10 | `fix/phase4-card-expiry-job` | `bb84da6` | Scheduled expiry job | feature |
| #11 | `fix/phase4-cards-export` | `f110d2a` | CSV export (`cards.export`) | feature |
| #12 | `fix/phase5-student-api` | `28fa2dc` | Tenant headers, live join, `is_enrolled` | `StudentSchedulePayloadTest` (4) |
| #13 | `fix/phase5-teacher-onboarding-nav` | `6e672d8` | Real onboarding + unhidden nav | `TeacherOnboardingTest` (4) |
| #14 | `fix/landing-gateway-copy` | `2bad98d` | Gateway copy honesty | tsc/build |
| #15 | `fix/phase6-cost-currency` | `6561d77` | Cost-currency fix (`toUsd()`) | `SaasCostCurrencyTest` (3) |
| #16 | `fix/phase7-manifest-cache` | `dbeb552` | Manifest cache isolation | `scripts/manifest.test.ts` (4) + CI step |
| #17 | `fix/recording-claims-honesty` | `a93fd51` | Remove phantom recording feature key | `RecordingClaimsTest` (2) |
| #18 | `chore/phase8-cloudflare-caddy-docs` | `6b76553` | CF/Caddy safe plan + ufw script | `bash -n` + dry-run |
| #19 | `chore/phase6-pricing-study` | `5ec4839` | Pricing & cost study | doc |
| — | `integration/launch-2026-11` | `dd13c63` (PR #4) | Phase 1 brand change set (read-time remap) | build green |

Local verification recipe used per branch: `vendor/bin/phpunit --testsuite=Feature`
(+ `Unit`), `npx tsc --noEmit`, `npx next build`, `npm run test:unit`. Suite
totals vary 338–351 by base; all green. No branch deletes or rewrites production
data.

---

## (4) Needs external setup / paid accounts (owner)

1. **Monitored mailbox** for `support@fahimni.me` (sending already points there).
2. **Run `CostRulesSeeder` once** (idempotent): if `provider_cost_rules` is empty,
   the BI/cost engine reports $0. `php artisan db:seed --class=CostRulesSeeder --force`.
3. **Correct the Bunny delivery rate** in `provider_cost_rules` to the real
   MENA/Volume rate (see PR #19).
4. **Decide `SubscriptionPlanSeeder` vs dormant `PlansSeeder`** and approve the
   PR #19 limit changes (add `delivery_minutes_limit`, `live_minutes_limit`).
5. **Bunny token-auth** secrets if signed playback is to be switched on
   (`BUNNY_TOKEN_AUTH` + pull-zone security key).
6. **Freemius plan IDs** created in the Freemius dashboard before any live
   enablement (Sandbox first).
7. **Google Cloud OAuth** — confirm production redirect URIs + consent screen.

---

## (5) Needs human testing (real devices / accounts)

1. **Google OAuth end-to-end** as a brand-new teacher and a brand-new student
   (real Google account) — cookies, redirect, 2FA, no duplicate workspace.
2. **Brand rollout verification** after §6: name + logo on home/header/footer/
   login/panels/favicon/manifest/OG; `/logo.png` hash `6e08e7c9…`; tab titles;
   settings API; no stale name/logo from cache or DB settings.
3. **PWA install** on Android Chrome, iPhone Safari, Desktop; teacher-site install
   keeps its own identity (post-login-out-of-scope redirect is a known limitation).
4. **Activation cards** on real Postgres (the single-use partial unique index is
   Postgres-only; sqlite tests don't exercise it): concurrent double-redeem.
5. **Worksheets**: upload → student sees → submits → teacher grades → student sees
   score/comment; **print** layout; RTL + mobile.
6. **Live session** via Google Meet: create, registered-only join, teacher join,
   end + notifications; attach a recording link.
7. **Bunny** real upload → processing → playback → signed-URL expiry → delete.

---

## (6) One root command per deploy

Only after the pre-checks pass (CI green on `dd13c63`; host script sha matches
`d1acd65…`; `bash -n` clean; backup taken). **One** command, auto-rollback on any
failure:

```bash
sudo bash /home/fahimni/fahimni-brand-rollout.sh
```

The script replaces `app frontend scheduler horizon` with
`docker compose up -d --no-deps`, sets `IMAGE_TAG=prod-brand1`,
`APP_NAME=منصة فهمني`, `MAIL_FROM_NAME="منصة فهمني"`, fixes root-owned leftover
files, verifies live, and rolls back on failure.

---

## Owner decisions queued (no approval yet)

- PR #19 pricing limits (F1, F4, F5) — apply on **Sandbox** first.
- Seed `CostRulesSeeder` + correct the delivery rate.
- Resolve `SubscriptionPlanSeeder` vs `PlansSeeder`.
- Phase 8 DNS/firewall/Caddy changes — **gated on explicit approval**; nothing
  applied.
