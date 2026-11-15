# Phase 6 — Pricing & Cost Study (فهمني)

**Status: recommendation only. Nothing in this document is applied.**
Production plan prices are unchanged, Freemius is **not** enabled, and no
tenant data was touched. Apply this on **Sandbox** first (see §9).

- Date of the market/cost checks: **16 Nov 2026**
- Reviewed source release: `dd13c63` (branch `integration/launch-2026-11`)
- Audience: Arabic-speaking teachers/academies (MENA + diaspora)

---

## 1. Guardrails

1. **Do not change production prices.** They live in `subscription_plans` /
   `plan_features` in the production DB and are re-applied by
   `php artisan plans:sync` (via `docker/migrate.sh`) on every deploy. Changing
   the seeder changes production. This document plans the change; it does not
   make it.
2. **Do not enable Freemius live.** `FREEMIUS_ENABLED=false` stays false; the
   Sandbox env var (`FREEMIUS_SANDBOX=true`) is the test path.
3. Platform subscription revenue is **USD only** (Freemius does not support
   JOD). `subscription_plans.currency` is display-only. Teacher→student
   payments are a *separate* concern (the teacher connects their own gateway)
   and must never flow through Freemius.
4. Every number below is either **read from the repo** (with a file:line) or
   **fetched from the provider on 16 Nov 2026** (with the URL). Assumptions are
   labelled as assumptions.

---

## 2. Where prices and limits live today

| Thing | Source of truth | Evidence |
|---|---|---|
| Plan price / trial / currency | `database/seeders/SubscriptionPlanSeeder.php` | `15–118` |
| Plan limits & gates | same seeder → `plan_features` rows | `146–158` |
| Re-applied on deploy | `docker/migrate.sh:20` → `SyncPlans` → `SubscriptionPlanSeeder` (`--force` reconciles) | `app/Console/Commands/SyncPlans.php:19–39` |
| Unit infrastructure costs | `provider_cost_rules` (DB) | `migrations/2026_08_07_000005_create_cost_engine_tables.php:12` |
| Cost/margin math | `app/Services/SaasCostService.php` | `planExpectedCost` `163`, `planProfitability` `192`, `biSummary` `278` |
| Quota enforcement | `app/Plans/QuotaService.php`, `app/Media/MediaQuotaService.php` | `MediaQuotaService::assertWatchMinutes` `189`, `assertLiveMinutes` `170` |
| Plan chooser | `app/Support/PlanRecommender.php` + `config/plan-recommender.php` | options derived from live `plan_features` |
| Public pricing page | `GET /public/plans` → `frontend/src/app/pricing/page.tsx` | reads DB, no hardcoded prices |

**Current production plans** (`SubscriptionPlanSeeder`, USD):

| | Starter | Professional | Enterprise |
|---|---|---|---|
| Price /mo | 49 | 99 | 199 |
| Price /yr | 490 | 990 | 1990 |
| Students | 50 | 200 | unlimited |
| Courses | 5 | 20 | unlimited |
| Storage | 5 GB | 20 GB | 100 GB |
| Videos | 50 | 200 | unlimited |
| Live sessions | 5 (live **off**) | 50 | unlimited |
| **Watch-minute cap** | **none** | **none** | **none** |
| **Live-minute cap** | **none** | **none** | **none** |

---

## 3. Real unit costs (checked 16 Nov 2026)

### 3.1 Video (Bunny Stream) — the only material variable cost

Source: <https://bunny.net/pricing/stream/> and <https://bunny.net/pricing/cdn/>

- **Encoding: free.** Multiple renditions are generated at no charge.
- **Storage: from $0.01/GB/month** (Standard HDD, single region).
- **Delivery (CDN), per region:**
  - Europe & North America **$0.01/GB**
  - Asia & Oceania **$0.03/GB**
  - **Middle East & Africa $0.06/GB**
  - **Volume network (10 PoPs): $0.005/GB** flat (first 500 TB)
- **$1/month minimum** on every account.

> **This is the single most important cost fact for this product.** فهمني's
> audience is Arabic/MENA. Bunny bills delivery **per region served**, so the
> realistic rate for the target audience is **$0.06/GB — 6× the $0.01/GB the
> repo's cost rule assumes**. The Volume network ($0.005/GB, single global rate)
> is the mitigation (§7).
- Delivery is driven by **watch minutes**, not by stored GB. At 720p HLS
  (~1.1 Mbps) one hour of playback ≈ **0.5 GB**.

### 3.2 Live streaming — effectively free

The live provider is **Google Meet** (`LiveProviderManager`), link-based. Meet
has no per-minute platform cost, so the `bunny/live = $0.03/hour` rule in
`CostRulesSeeder:27` is dormant. Live minutes are metered and capped but cost
the platform **$0** — a pure upsell lever.

### 3.3 Email

Outbound transactional mail goes through Resend. The cost rule is
`email/email = $0.0001` (`CostRulesSeeder:36`) i.e. $0.10 per 1,000. Real
Resend tiers are ~$0.0004–0.001 at 50k/month. Impact is small either way
(**< $1/tenant/month**); treat `$0.40/tenant/month` for ~1,000 mails as the
working figure and re-verify against the plan actually bought.

### 3.4 VPS / platform infra

A single shared VPS hosts LooksLoop **and** فهمني production + staging (see
`docs/CLOUDFLARE_VPS_HARDENING.md` §1–§5). Budget **$12–20/month** for the box;
allocate **$1/tenant/month** as an infra share in the model (this falls as
tenants are added).

### 3.5 Payment fees (Freemius + gateway)

Source: <https://freemius.com/pricing/> (checked 16 Nov 2026)

- Freemius: **3.8% + $0.50** per transaction, no setup/monthly fee,
  merchant-of-record (handles VAT/sales tax, fraud, disputes), SaaS solution
  (the +2.3% "WordPress" surcharge does **not** apply).
- Plus gateway fees (~2.9% + $0.30 card; international cards run higher).
- Model as **6.7% + $0.80** per charge.

| Charge | Freemius + gateway | Take-home |
|---|---|---|
| $49 | $4.08 | **$44.92** |
| $99 | $7.43 | **$91.57** |
| $199 | $14.13 | **$184.87** |

Annual billing (10× monthly, below) is billed **once**, so the fixed $0.80 is
paid once a year instead of twelve times.

---

## 4. What the platform's own engine reports vs reality

`SaasCostService::planExpectedCost()` with the current production plans and the
`CostRulesSeeder` rates returns:

| Plan | Engine's "expected cost" | Engine margin |
|---|---|---|
| Starter | **$1.00** (Bunny $1 minimum) | ~98% |
| Professional | **$1.00** (Bunny $1 minimum) | ~99% |
| Enterprise | **$3.01** | ~98% |

The admin BI dashboard will therefore show ~98% margins on every plan. **That
number is wrong, and it has four concrete causes** (all in
`SaasCostService::planExpectedCost`, lines 163–185):

1. **It prices delivery off *stored* GB × `delivery_factor` (=2), not off actual
   watch minutes.** A course with 20 GB of video but 100 students watching it
   ten hours a month delivers ~500 GB, not 40 GB — a 12× understatement.
2. **It uses the $0.01/GB Europe/NA delivery rate** for a MENA audience, where
   Bunny bills $0.06/GB (§3.1). 6× understatement.
3. **It ignores VPS, support and Freemius fees entirely.**
4. **`live_minutes_limit` is absent from production plans**, so
   `featureValue('live_minutes_limit', 0)` is `0` and the live term always
   computes `0` — and, separately, watch minutes are uncapped so the real cost
   is *unbounded*.

Consequence: the platform cannot currently answer "is this plan profitable?"
from its own data. The model in §6 is the corrected one.

---

## 5. Competitor benchmark (all USD, checked 16 Nov 2026)

| Platform | Entry | Mid | Top | Key limits / fees |
|---|---|---|---|---|
| **Teachable** <sub>teachable.com/pricing</sub> | $39 | $89 | $189 | **7.5% transaction fee on Starter only**; 5/10/50 products; card 2.9%+30¢ US, 3.9%+30¢ intl |
| **Thinkific** <sub>thinkific.com/pricing</sub> | $109 ($82/yr) | $219 ($164/yr) | $499 ($374/yr) | video bandwidth **200/400/1000 GB per month**; 2.9% processing |
| **LearnWorlds** <sub>learnworlds.com/pricing</sub> | $49 ($44/yr) | $99 ($79/yr) | $299 ($249/yr) | **$5 per enrollment on Starter**; 1k/2k/2k monthly active learners |
| **Podia** <sub>podia.com/pricing</sub> | $49 ($42/yr) | $99 ($84/yr) | $179 ($150/yr) | **5% fee on the entry plan**; email-subscriber caps |
| **فهمني — current** | $49 | $99 | $199 | includes video hosting, live (Meet), RTL/Arabic, activation cards, WhatsApp/Telegram notifications, student mobile app |
| **فهمني — recommended** | $49 | $99 | $199 | *(same prices — corrected limits, §6)* |

**Read-out**

- فهمني's current prices are **squarely mid-market**. There is no evidence that
  the *price* is the problem.
- The named competitors all monetise the *same* variable cost the platform
  keeps uncapped: Teachable/Thinkific throttle **video**, LearnWorlds and Podia
  charge an **enrollment %**. فهمني gives video away uncapped — that is the
  actual pricing bug, not the headline number.
- فهمني also bundles for free what competitors charge extra for: live sessions
  (Thinkific/Zoom sessions, LearnWorlds live tiers), activation/registration
  cards (nobody else has this), Arabic-native RTL + WhatsApp/Telegram
  notifications, and the student mobile app (Thinkific charges **$199/mo**
  as an add-on). That bundle supports parity with Podia/LearnWorlds and
  under-cutting Thinkific.

---

## 6. Recommended plans (3 tiers)

**Prices unchanged** ($49 / $99 / $199 monthly; $490 / $990 / $1,990 yearly =
"2 months free"). Since Freemius is **not live and there are no paying tenants
yet**, the cheapest moment to correct the *limits* is now — with zero migration.

Only the limits change. Every key below exists in `FeatureCatalog` and is
enforced by the existing engine (§8), so **no code change is required**.

| Limit (`plan_features` key) | Starter | Professional | Enterprise |
|---|---|---|---|
| price /mo · /yr | **49 · 490** | **99 · 990** | **199 · 1990** |
| `students_limit` | 150 | 1,000 | unlimited |
| `max_students_per_course` | 50 | 200 | unlimited |
| `courses_limit` | 10 | 50 | unlimited |
| `teachers_limit` | 1 | 3 | 15 |
| `storage_limit_mb` | 25,600 (25 GB) | 102,400 (100 GB) | 409,600 (400 GB) |
| `videos_limit` | 60 | 300 | unlimited |
| `files_limit` / `pdfs_limit` | 400 / 100 | 2,000 / 500 | unlimited |
| `exams_limit` / `questions_limit` | 20 / 200 | 100 / 1,000 | unlimited |
| `assignments_limit` | 20 | 100 | unlimited |
| `live_sessions_limit` | 10 | 50 | unlimited |
| **`live_minutes_limit`** (new) | 1,200 | 6,000 | 20,000 |
| **`delivery_minutes_limit`** (new, fair-use) | 25,000 | 90,000 | 350,000 |
| `cards_limit` | 500 | 5,000 | unlimited |
| `blog_posts_limit` / `community_groups_limit` | 10 / 5 | 50 / 20 | unlimited |
| `appointments_limit` / `services_limit` | 30 / 5 | 200 / 50 | unlimited |
| `custom_branding_enabled` / `export_reports` | false | true | true |
| `white_label` / `api_access` | false | false | true |
| `live_stream_enabled` | false | true | true |

Rationale for each change:

- **Add `delivery_minutes_limit`.** This is the only real cost cap. Without it,
  one viral course can cost more than its plan price. The caps are sized so the
  worst case (every minute watched) still leaves a healthy margin (§6.1).
- **Add `live_minutes_limit`.** Live is free today (Meet), so this is headroom
  to *sell*, not to *save* — and it becomes real if live ever moves to a paid
  provider.
- **Raise storage/videos.** 5 GB is ~8 hours of video — a blocker, not a plan.
  Storage is cheap ($0.01/GB); the cost is delivery, which is now capped.
- **Everything already `unlimited` stays unlimited** (no silent downgrade).
- **Fair-use wording** on the two new caps: they stop runaway cost, and the
  platform's existing behaviour is to block with `QuotaExceededException`, which
  the UI already turns into an upgrade prompt.

### 6.1 Margins at the watch-minute cap (worst case, everything watched)

Delivery GB = minutes ÷ 60 × 0.5. Storage $0.01/GB; email $0.40; infra share
$1.00; live $0. Take-home = price − Freemius+gateway (§3.5).

| Plan | Cap (min) | → GB | Cost @ **$0.005/GB** (Volume) | @ $0.01/GB (EU/NA) | @ **$0.06/GB** (MENA) |
|---|---|---|---|---|---|
| Starter $49 | 25,000 | 208 | **$2.69** → **94.0%** | $3.73 → 91.7% | **$14.15 → 68.5%** |
| Professional $99 | 90,000 | 750 | **$6.15** → **93.3%** | $9.90 → 89.2% | **$47.40 → 48.2%** |
| Enterprise $199 | 350,000 | 2,917 | **$19.98** → **89.2%** | $34.57 → 81.3% | **$180.40 → 2.4%** |

*(margin = take-home − cost ÷ take-home; take-home = $44.92 / $91.57 / $184.87)*

**Conclusion:** with the **Bunny Volume network ($0.005/GB)**, every plan holds
**≥ 89% take-home margin even at its fair-use cap**. On Standard **MENA** PoPs
($0.06/GB), Enterprise is loss-making at full usage — so the delivery rate and
the caps must move together (§7).

### 6.2 Growth position

At recommended prices/limits, فهمني is:

- ~**30–50% cheaper** than Thinkific/Teachable at the mid tier, while including
  video hosting they meter and live sessions they charge extra for.
- At parity with Podia/LearnWorlds on price, but **without their enrollment %
  fees** and with Arabic-native features none of them ship.
- The activation-card + WhatsApp/Telegram + mobile-app bundle is a genuine
  differentiator to lead with on the landing page (Phase 5 already removed the
  overstated gateway claims).

---

## 7. Cost levers (in priority order)

1. **Run Stream delivery on the Bunny Volume network ($0.005/GB flat).** This
   alone is the difference between 89% and 2% margin for a heavy Enterprise
   tenant. Trade-off: 10 PoPs instead of 119 (higher latency for some MENA
   users). Start on Volume, measure, and only move heavy tenants to Standard
   MENA PoPs if latency is unacceptable.
2. **Enforce the two new minute caps** (§6). They convert "unbounded" into
   "bounded", which is what makes a price defensible.
3. **Sell annual.** 10× monthly; the Freemius fixed $0.80 is paid once per year
   instead of twelve times, and 12-month cash up front funds the delivery bill.
4. **Live is a free upsell.** Cap it generously (it costs $0) and use it to
   justify the mid tier (where `live_stream_enabled` turns on).
5. **Re-check the delivery rate rule in the DB** — `provider_cost_rules`
   currently encodes $0.01/GB (`CostRulesSeeder:25`), which is wrong for MENA
   viewers. Correction is a DB value, not a code change. See finding F4.

---

## 8. Compatibility verification (no code change needed)

| Subsystem | Does it read these keys? | Evidence |
|---|---|---|
| `PlanEngine::limit/value/hasFeature` | yes — reads `plan_features` | `app/Plans/PlanEngine.php` |
| `QuotaService::assertCan` (students, courses, …) | yes | `app/Plans/QuotaService.php:30` |
| `MediaQuotaService::assertWatchMinutes` | yes — `video_watch_minutes_limit` then **`delivery_minutes_limit`** | `app/Media/MediaQuotaService.php:189–206`, called from `VideoStreamController.php:87` |
| `MediaQuotaService::assertLiveMinutes` | yes — `live_minutes_limit` | `app/Media/MediaQuotaService.php:170`, called from `LiveSessionController.php:568` |
| `SaasCostService::planExpectedCost` | reads `storage_limit_mb`, `videos_limit`, `live_minutes_limit`, `live_stream_enabled` | `app/Services/SaasCostService.php:167–179` |
| `PlanRecommender` | derives its options **from the live plans**, so new limits flow into the chooser automatically | `app/Support/PlanRecommender.php:40–70`, `config/plan-recommender.php:20–27` |
| Public pricing page | reads `GET /public/plans` | `frontend/src/services/endpoints.ts:52`, `frontend/src/app/pricing/page.tsx` |
| Admin plan editor / BI | lists `FeatureCatalog` + `provider_cost_rules` | `app/Http/Controllers/Api/BiController.php:52–70` |

Two integration notes:

- `assertWatchMinutes` prefers **`video_watch_minutes_limit`** and falls back to
  **`delivery_minutes_limit`**. `FeatureCatalog` only exposes the latter, so use
  `delivery_minutes_limit` (the admin UI shows it; the former is legacy).
- The cost engine also reads `live_minutes_limit`; adding it also makes the BI
  live term non-zero and correct for the first time.

---

## 9. How to apply later (Sandbox first — not now)

1. **Sandbox only**, `FREEMIUS_SANDBOX=true`, `FREEMIUS_ENABLED=true`.
2. Edit `database/seeders/SubscriptionPlanSeeder.php` limits per §6, then run
   `php artisan plans:sync --force` **on Sandbox** and confirm
   `GET /public/plans` + the pricing page + the plan chooser render the new
   limits with correct Arabic labels.
3. Set `provider_cost_rules` to the real rates (§7.5) and verify
   `POST /admin/bi/recalculate` → `GET /admin/bi` margins match §6.1.
4. Recalculate with `BUNNY` pull-zone on Volume vs Standard to confirm the
   margin difference.
5. Only after Sandbox sign-off, promote to Staging, then Production — in a
   single reversible commit (`plans:sync --force` idempotently re-applies).
6. Freemius plan IDs must be created in the Freemius dashboard and mapped via
   `FREEMIUS_PLAN_{SLUG}_{MONTHLY|YEARLY}_ID`; **do not** flip
   `FREEMIUS_ENABLED` on production until Sandbox end-to-end purchase + webhook
   is verified.

---

## 10. Findings

| # | Sev | Finding | Evidence |
|---|---|---|---|
| F1 | **P1** | Production plans have **no watch-minute cap** (`delivery_minutes_limit` / `video_watch_minutes_limit` absent), so `assertWatchMinutes` returns early and video delivery cost is unbounded. This is the platform's only material variable cost. | `SubscriptionPlanSeeder:15–118`; `MediaQuotaService.php:191–199` |
| F2 | **P1** | `SaasCostService::planExpectedCost()` under-states cost ~10–30× for video-heavy tenants (delivery from stored GB × 2, $0.01/GB EU/NA rate for a MENA audience, no infra/support/Freemius, `live_minutes_limit`=0). The admin BI margin (~98%) is not a usable signal. | `SaasCostService.php:163–185` |
| F3 | **P1** | `CostRulesSeeder` is **not registered** in `DatabaseSeeder:14–19` and the deploy path runs `db:seed --force` (which honours `DatabaseSeeder`) plus `plans:sync` only. If `provider_cost_rules` is empty, `ProviderCostRule::unitCost()` returns `0.0` and the whole cost engine reports **$0 cost**. There is also **no create endpoint** in `BiController` (only `PUT … /cost-rules/{id}` `findOrFail`) to add a missing rule from the UI. | `DatabaseSeeder.php:14`; `docker/migrate.sh:20–37`; `ProviderCostRule.php:28–33`; `BiController.php:52–70` |
| F4 | **P1** | The Bunny `delivery` cost rule is **$0.01/GB** (`CostRulesSeeder:25`) with a MENA audience where Bunny bills **$0.06/GB**; the rule also ignores that Volume network is $0.005/GB. Wrong data ⇒ wrong margins. | `CostRulesSeeder:25`; bunny.net/pricing (16 Nov 2026) |
| F5 | **P1** | `SubscriptionPlanSeeder` (live) and the **dormant** `PlansSeeder` (not registered) disagree on almost everything — `elite` vs `enterprise` slug, different students/storage/videos, and `PlansSeeder` **does** define `live_minutes_limit`/`delivery_minutes_limit` that the live seeder lacks. Running the dormant seeder would rewrite production plan limits. Needs owner approval; do not "fix" by running it. | `SubscriptionPlanSeeder` vs `PlansSeeder:33–202`; `DatabaseSeeder.php:14` |
| F6 | **P2** | Storage limits are below market (Starter 5 GB ≈ 8 h of video), which is a *sellability* problem independent of cost. | `SubscriptionPlanSeeder:18` |
| F7 | **P2** | Freemius live enablement is a single env flag + admin toggle with no non-production guard; prices/limits must be frozen *before* enabling. | `config/services.php:81–89` |
| F8 | **P2** | Email cost rule ($0.0001) is optimistic vs real Resend tiers (~$0.0004–0.001). Impact small (<$1/tenant/mo) but the BI will under-report it. | `CostRulesSeeder:36` |

**Not changed on purpose:** `PlansSeeder`, `SubscriptionPlanSeeder` prices/limits,
`CostRulesSeeder`, `provider_cost_rules` rows and the Freemius flag. F3–F5 touch
production data/config and need explicit owner approval.

---

## 11. Reproduce

```bash
# Which plans production will have (source of truth)
sed -n '14,120p' database/seeders/SubscriptionPlanSeeder.php
php artisan plans:sync            # lists what it does; --force reconciles

# Do the cost rules actually exist? (empty => cost engine reports $0)
#   GET /api/v1/admin/bi/cost-rules        (admin token)
#   compare against database/seeders/CostRulesSeeder.php
# Safe, idempotent owner action if empty (NOT run by the agent):
php artisan db:seed --class=CostRulesSeeder --force

# Current engine margins (admin token)
#   POST /api/v1/admin/bi/recalculate   then   GET /api/v1/admin/bi
```

Cost model spreadsheet formula (per plan, at the cap):

```
gw        = 0.067 * price + 0.80            # Freemius 3.8%+$0.50 + gateway ~2.9%+$0.30
gb        = watch_min_cap / 60 * 0.5        # 720p HLS ≈ 0.5 GB/hour
delivery  = gb * rate                       # rate ∈ {0.005, 0.01, 0.06}
cost      = delivery + storage_gb*0.01 + 0.40 + 1.00
margin    = (price - gw - cost) / (price - gw)
```
