# FINAL PLATFORM AUDIT — منصة فهمني

**Release candidate:** `release/launch-2026-11-rc` (base `integration/launch-2026-11` @ `dd13c63`)
**Audit date:** 2026-11-16
**Method:** the code is the source of truth. Every row below is tied to a real
route, controller, policy/middleware, migration or test file in this repository.
Statuses are only `PASS` when backed by an executed test or a verified run; anything
not executed says so.

---

## 1. Inventory (measured, not estimated)

| Surface | Count | Command used |
| --- | --- | --- |
| Laravel routes (all) | **423** | `php artisan route:list --json` |
| — of which under `api/v1` | **393** | filter on `uri` |
| — methods | `GET 211 · POST 132 · PUT 45 · DELETE 35` (+211 `HEAD`) | same |
| Next.js pages (server/client routes) | **89** | `find frontend/src/app -name page.tsx` |
| Next.js route handlers (BFF/misc) | **8** | `find frontend/src/app -name route.ts` |
| BFF session endpoints | 7 (`login, register, logout, me, refresh, csrf, 2fa`) | `frontend/src/app/api/session/**` |
| Feature-catalog keys | **50** | `app/Support/FeatureCatalog.php` |
| Permissions | **54** | `database/seeders/RolePermissionSeeder.php` |
| Roles | 3 Spatie roles: `Super Admin`, `Teacher`, `Student` (+ tenant pivot roles `owner/teacher/admin/student`) | same |
| PHPUnit test files | **36** (35 Feature, 2 Unit incl. a shared base) | `find tests -name '*Test.php'` |
| PHPUnit tests executed | **389 passing / 389** | full suite |
| Frontend node tests | **26 passing** (`bff-origin`, `csp`, `manifest`) | `npm run test:scripts` |
| Frontend vitest tests | **20 passing / 4 files** | `npx vitest run` |

Route modules under `api/v1` (top of the tree):

```
admin 76 · teacher 75 · community 24 · exams 17 · public 16 · auth 15 · mobile 13
student 11 · cards 10 · lessons 9 · questions 9 · subscriptions 9 · assignments 8
conversations 8 · courses 8 · enrollments 7 · announcements 6 · payments 6 · sections 5
lesson-contents 4 · notifications 4 · search 4 · tenants 4 · tickets 4 · analytics 3
videos 3 · invoices 2 · live-sessions 2 · media 2 · my-bookings 2 · plans 2
platform-settings 2 · preferences 2 · push-tokens 2 · settings 2 · submissions 2
transactions 2 · user 2 · + singletons
```

---

## 2. Authentication & session architecture (P0)

### 2.1 The real flow

```
browser ──▶ nginx (docker/nginx/default.conf)
              ├─ /api/session/*  ─▶ Next.js :3000 (BFF route handlers)
              │                       └─ server-to-server fetch ─▶ nginx /api/v1 ─▶ Laravel
              └─ /api/v1/*       ─▶ PHP-FPM (Laravel)
```

The browser only ever holds **HttpOnly cookies**. The BFF (`frontend/src/lib/bff.ts`)
talks to Laravel server-to-server and rewrites `Set-Cookie` back to the browser.
Sanctum tokens are never placed in the URL, `localStorage` or JS-readable cookies
in the web flow (the mobile bearer flow is separate and lives in the Flutter app).

### 2.2 Root cause that was fixed (PR #24)

Behind nginx/Caddy, Next.js derives `request.url` from its own listen address, so
`new URL(request.url).origin` resolved to `http://<container>:3000` — an origin that
is **not** in `SANCTUM_STATEFUL_DOMAINS`. Sanctum’s
`EnsureFrontendRequestsAreStateful` therefore treated the call as stateless, no
session was started, `/api/v1/auth/me` answered `401`, and the UI showed
«تعذر إنشاء الجلسة» after the Google callback. Email/password login hit the same
path (silent bearer fallback).

**Fix:** `frontend/src/lib/request-origin.ts` rebuilds the public origin from
`x-forwarded-host` / `host` + `x-forwarded-proto`, and the BFF forwards that as both
`Origin` and `Referer`. Every `/api/session/*` handler now calls `requestOrigin(request)`
instead of `new URL(request.url).origin` (asserted by a test that greps all handlers).

### 2.3 Host-header spoofing hardening (defence in depth, added here)

Because the forwarded `Origin` decides whether Sanctum treats a call as stateful, a
client-supplied `Host` / `X-Forwarded-Host` must never steer it.

- nginx **overwrites** `X-Forwarded-Host: $host` on **both** Next.js proxy locations
  (`/api/session/` and `/`), matching the overwrite discipline already used for
  `X-Forwarded-For` / `X-Forwarded-Proto` (`docker/nginx/default.conf`).
- `requestOrigin()` trusts a host only when it is loopback, the platform domain
  (`NEXT_PUBLIC_PLATFORM_DOMAIN`, subdomains included) or the `NEXT_PUBLIC_SITE_URL`
  host. Look-alikes such as `fahimni.me.evil.com` are rejected (label-boundary check,
  no foreign wildcard). An untrusted or absent host, or an unexpected scheme, falls
  back to the canonical origin.

7 regression tests cover spoofed XFH, spoofed Host, look-alike suffix, tenant
subdomain, bad scheme, localhost, and an assertion that nginx pins XFH on both blocks.

### 2.4 Google OAuth (end-to-end review)

`app/Http/Controllers/Api/Auth/GoogleAuthController.php` — reviewed line by line:

| Control | Evidence | Status |
| --- | --- | --- |
| HMAC-signed, 10-min `state`; forged `role=admin` rejected | `signState()` / `verifyState()` (`hash_hmac` with `app.key`, `hash_equals`) | PASS |
| Admin can only be self-provisioned if it is the configured super-admin email | `isConfiguredSuperAdminEmail()` (constant-time compare vs `app.super_admin_email`) | PASS |
| Teacher provisioning respects the same gate as email signup (or a pending invitation) | `teacherRegistrationAllowed()` | PASS |
| Inactive accounts blocked before any write | `if ($user && ! $user->is_active)` | PASS |
| 2FA never bypassed: server-side pending challenge + retry | `auth.2fa_pending` session key / cache `2fa:<nonce>` | PASS |
| **No duplicate tenant on Google register** | `ensureWorkspaceTenant()` returns the existing membership tenant first; creation happens inside one `DB::transaction` with `lockForUpdate`; `unique` collision is caught and re-read | PASS |
| **Teacher role assigned** | `$user->assignRole(Role::findOrCreate('Teacher','web'))` | PASS |
| **Trial assigned** | `TenantSubscription` `status=trialing`, `trial_ends_at = now()+plan->trial_days` | PASS |
| Workspace creation is atomic (tenant+setting+membership+trial commit together) | single `DB::transaction` | PASS |
| Concurrent-callback race converges | `lockForUpdate` + `isDuplicateKey()` | PASS |
| Tokens never in URL/logs in the web flow | web path uses `Auth::guard('web')->login()` + `session()->regenerate()`; only the mobile path emits a token, and it goes in the URL **fragment** (never sent to servers) | PASS |

### 2.5 Session / cookie / CSRF posture

| Setting | Production value | Assessment |
| --- | --- | --- |
| `SESSION_DRIVER` | `redis` | PASS (shared across replicas) |
| `SESSION_SECURE_COOKIE` | `true` | PASS |
| `SESSION_SAME_SITE` | `lax` | PASS |
| `SESSION_DOMAIN` | *(empty → host-only)* | PASS (no cross-site cookie) |
| `SANCTUM_STATEFUL_DOMAINS` | `fahimni.me,www.fahimni.me,api.fahimni.me` | **GAP (pre-existing)** — a tenant **subdomain** origin is not in the list, so a BFF call originating from e.g. `teacher.fahimni.me` would be treated as stateless. The apex/public site and custom-domain flow are unaffected. **Recommendation (needs approval, env change only):** add `*.fahimni.me`; Sanctum matches with `Str::is`, so a wildcard entry works. Not applied. |
| Trusted proxies | loopback + RFC1918 only, never `*` | PASS (`bootstrap/app.php`) |
| `redirectGuestsTo(null)` + JSON 401 for `api/*` | PASS |
| Email links | built from `config('app.frontend_url')` / `config('app.url')`, **not** the request host | PASS — no Host-header email poisoning (verified: no `url()`/`route()` host usage in `app/Notifications`, `app/Mail`) |

### 2.6 Rate limiting

Per-endpoint limiters defined in `app/Providers/AppServiceProvider.php`
(`login 5/min per email+ip`, `register 3/min`, `forgot/reset 5/min`,
`two-factor 10/min`, `redeem 10/min`, `video-upload 6/min`, `checkout 30/min`,
`webhooks 120/min`, `api 300/min`, …) and applied via `throttle:*` middleware.
`cards/redeem` was explicitly kept **un-gated by feature** and behind `throttle:redeem`
during the merge, so cards already issued remain redeemable if a plan changes.

---

## 3. Authorisation model

- **Role + tenant enforcement:** `app/Http/Middleware/EnsureRole.php` — resolves the
  tenant from `X-Tenant-Id`/`tenant_id`, requires an **active** pivot row in the
  requested roles, and for `permission:<name>` only applies the staff allow-list when
  the pivot role is `teacher`. Super admins bypass. Missing tenant → `400`; wrong role
  → `403`; anonymous → `401`.
- **Feature gating:** `feature:<key>` → `CheckFeature` (403 `FeatureUnavailableException`).
- **Plan gating:** `subscription` → `RequireSubscription`.
- **Tenant isolation:** `tenant` → `TenantMiddleware`; models use the `TenantScope`
  / `withoutTenant()` escape hatch. Verified by `TenantIsolationTest`.
- **Permissions:** 54 permissions; `Super Admin` = all, `Teacher` = 34 (courses,
  sections, lessons, content, students, cards, questions, exams, own analytics, live,
  own settings/branding), `Student` = 5 (`profile.*`, `cards.redeem`, `exams.view`,
  `exams.attempt`).

> **Note on `cards.export`:** the route exists and is guarded by `permission:cards.export`.
> The permission is defined in `RolePermissionSeeder` and granted to `Teacher`, but the
> **production database must be re-seeded** (`RolePermissionSeeder` is idempotent:
> `findOrCreate` + `syncPermissions`) or export returns `403` for teachers. This is an
> **additive** permission change and is gated behind deploy approval (see checklist §4).

---

## 4. Coverage matrix (module × action × guard × status)

Legend: **PASS** = executed green · **FIXED+PASS** = was broken, now green ·
**BLOCKED** = needs a real external service/account · **NOT TESTED** = no executed coverage.

### 4.1 Super Admin

| Action | Route(s) | Guard | Test | Status |
| --- | --- | --- | --- | --- |
| Dashboard / metrics | `GET admin/bi`, `admin/monitoring/*` | `admin` | MonitoringTest (14) | PASS |
| Plans CRUD | `admin/plans*` | `admin` | PlanSystemTest, PlanEngineTest (18) | PASS |
| Plan features CRUD | `admin/plans/{plan}/features*` | `admin` | PlanSystemTest | PASS |
| Subscriptions override/extend/plan | `admin/subscriptions/*` | `admin` | PlanSystemTest | PASS |
| Teachers list/create/status/delete | `admin/teachers*` | `admin` | RoleAuthorizationTest | PASS |
| Students list/create/status/delete | `admin/students*` | `admin` | RoleAuthorizationTest | PASS |
| Payments review + proof + status | `admin/payments*` | `admin` | PaymentAndFileSecurityTest (9) | PASS |
| Coupons CRUD + toggle | `admin/coupons*` | `admin` | PlanSystemTest | PASS |
| Cost rules read/update, recalc | `admin/bi/cost-rules`, `admin/bi/recalculate` | `admin` | CostEngineTest, CostRulesSeederTest | PASS |
| Media providers sync/test/pricing | `admin/media/*` | `admin` | MediaEngineTest (21) | PASS |
| Backups list/create/verify/download | `admin/backups*` | `admin` | BackupApiTest (15), BackupPipelineTest (6) | PASS |
| Error logs, tickets, quran-requests | `admin/*` | `admin` | — | NOT TESTED (thin admin UI) |
| Feature catalog | `GET admin/feature-catalog` | `admin` | — | NOT TESTED |

### 4.2 Teacher

| Action | Route(s) | Guard | Test | Status |
| --- | --- | --- | --- | --- |
| Courses create/edit/publish/unpublish/delete | `courses*` | `role:teacher,owner,admin,permission:courses.*` | RoleAuthorizationTest, SubscriptionGateTest | PASS |
| Sections / lessons / lesson-contents CRUD | `sections*`, `lessons*`, `lesson-contents*` | role + `*.manage` | RoleAuthorizationTest | PASS |
| Card generate/import/export/cancel/batches | `cards*` | `role:...,permission:cards.*`, `feature:cards_enabled` | ActivationCardExportTest, ActivationCardRedeemTest | FIXED+PASS |
| Card expiry sweep (scheduled) | job `ExpireActivationCardsJob` | n/a | ActivationCardExpiryTest | FIXED+PASS |
| Card redeem (student) | `POST cards/redeem` | `throttle:redeem` (no feature gate) | ActivationCardRedeemTest (5) | FIXED+PASS |
| Assignments create/edit/grade + worksheet file | `assignments*` | role + `students.*` | AssignmentWorksheetTest (9) | FIXED+PASS |
| Video upload/ticket/playback (signed) | `videos*`, `teacher/media/*` | `feature:video_*`, `throttle:video-upload` | BunnyMediaProviderTest (11), VideoSecurityTest (11), MediaEngineTest | FIXED+PASS |
| Live sessions create/start/end/cancel/join-ticket/recording | `teacher/live-sessions*`, `live-sessions/*` | `live.create/manage`, `throttle:live-create` | LiveStreamingTest (27) | FIXED+PASS |
| Exams + questions + attempts + grade | `exams*`, `questions*` | `exams.*` | ExamIntegrityTest, RenewalAndGradingTest | PASS |
| Scheduled lessons CRUD/complete/cancel | `teacher/scheduled-lessons*` | role | StudentSchedulePayloadTest (4) | PASS |
| Analytics + Excel/PDF report | `teacher/analytics*` | `analytics.view_own` | — | NOT TESTED (report render) |
| Staff invitations + members | `teacher/staff*` | role owner/admin | TeacherOnboardingTest (4) | PASS |
| Onboarding | `GET teacher/onboarding` | role | TeacherOnboardingTest | PASS |
| Domain add/verify/remove | `teacher/domain*` | role | — | **BLOCKED** (needs real DNS) |
| Blog CRUD/publish | `teacher/blog*` | `feature:blog_enabled` | — | NOT TESTED |
| Services / availabilities / bookings | `teacher/services*`, `teacher/bookings*` | role | — | NOT TESTED |
| Site design / branding / file+device+video policy | `teacher/site-design`, `settings`, `policies` | `branding.customize`, `settings.manage_own` | — | NOT TESTED |
| Student app regenerate | `teacher/student-app*` | role | StudentMobileAppTest (34, mobile) | PASS |

### 4.3 Student

| Action | Route(s) | Guard | Test | Status |
| --- | --- | --- | --- | --- |
| Enroll / my-courses / progress | `enrollments*`, `my-courses`, `lessons/*/complete` | `role:student` | RoleAuthorizationTest | PASS |
| Schedule payload (silent empty states + join button) | `student/*`, `scheduled-lessons` | role | StudentSchedulePayloadTest | FIXED+PASS |
| Exam attempt / submit / results / certificate | `exams/{exam}/attempt*`, `student/certificates*` | `exams.attempt` | ExamIntegrityTest | PASS |
| Assignment submit | `assignments/{assignment}/submit` | role | AssignmentWorksheetTest | PASS |
| Favorites / leaderboard / points / achievements | `student/*` | role | — | NOT TESTED |
| Card redeem | `POST cards/redeem` | auth + throttle | ActivationCardRedeemTest | PASS |
| Mobile app flows | `mobile/*` | device session | StudentMobileAppTest (34) | PASS |

### 4.4 Public

| Action | Route(s) | Guard | Test | Status |
| --- | --- | --- | --- | --- |
| Home / landing (all sections) | `/` | none | `home-client.test.tsx` (vitest) + `page.test.ts` | FIXED+PASS |
| Plans list | `GET public/plans` | none | `page.test.ts` | PASS |
| Public tenant site, courses, posts, booking | `public/{slug}*` | none | — | NOT TESTED |
| Public course lesson preview | `public/courses/{course}/lessons/{lesson}` | none | VideoSecurityTest | PASS |
| Contact form | `POST public/contact` | `throttle:contact` | — | NOT TESTED |
| Sitemap | `GET public/sitemap` | none | — | NOT TESTED |
| Card/certificate public lookup | `public/cards/{code}`, `public/certificates/{number}` | none | ActivationCardRedeemTest (card) | PASS (card) / NOT TESTED (cert) |

---

## 5. Findings

### 5.1 Fixed in this release

| # | Finding | Severity | Fix |
| --- | --- | --- | --- |
| F1 | «تعذر إنشاء الجلسة» after Google/email login (BFF sent container origin) | **P0** | `request-origin.ts` + BFF handlers (PR #24) |
| F2 | Host-header spoofing of the forwarded `Origin` | **P0** | nginx XFH overwrite + `isTrustedHost()` allowlist + 7 tests |
| F3 | Landing page replaced by a minimal redesign (PR #25); many real sections gone | **P1** | Original page restored verbatim from `dd13c63`; **only** the Hero redesigned (PR #25 not merged) |
| F4 | `CostRulesSeeder` never registered → no provider cost baseline on a fresh install | **P1** | Registered in `DatabaseSeeder`; seeder made event-independent (see F5) |
| F5 | Fresh `db:seed` would **abort**: `DatabaseSeeder` uses `WithoutModelEvents`, so the nested seeder got no UUID → `NOT NULL constraint failed: provider_cost_rules.id` | **P1** | Seeder generates its own UUID on create, updates in place on re-run; idempotency test |
| F6 | Last stale brand string «منصة شahead» in the Quran-teacher approval email (subject + body) | **P1** | → «منصة فهمني» |
| F7 | Deploy script hard-coded `SHA=dd13c63` (brand-only, no Google fix) → would reintroduce F1 | **P0** | `FAHIMNI_RELEASE_SHA` override + hard guard refusing a checkout without `request-origin.ts` |
| F8 | Teacher dashboard: one failing endpoint blanked the page / stuck skeletons | **P1** | Resilient `Promise.all` with per-request `.catch()` + `try/catch/finally`, merged with the onboarding fetch without dropping either fix |

### 5.2 Fixed earlier in the launch branch (still present, re-verified)

Activation-card redemption hardening (`cards/redeem` throttle, single-use, cross-tenant block);
card CSV export; signed Bunny playback tickets; live-session usage notify + recording attach;
Bunny provider signed-URL correctness; payment/file security; settings-secret encryption;
tenant isolation; PWA manifest per-tenant isolation; CSP per-request nonce;
student schedule payload; teacher onboarding.

### 5.3 Open findings (documented, **not** applied — require approval)

| # | Finding | Impact | Proposal (separate change) |
| --- | --- | --- | --- |
| O1 | `video_watch_minutes_limit` is **not** a `FeatureCatalog` key. `MediaQuotaService::assertWatchMinutes()` falls back `video_watch_minutes_limit → delivery_minutes_limit`. | A plan that intends a dedicated watch-minute cap has no catalog entry to edit; it silently reuses the delivery cap. | Add the key to `FeatureCatalog` (additive) and back-fill plan features. **Pricing/entitlement change → separate proposal.** |
| O2 | `SubscriptionPlanSeeder` is registered; the richer, cost-computed `PlansSeeder` is **not**. | A fresh install seeds the flat plans, not the cost-derived ones — the two can drift. | Decide which is canonical; **changing seeded plans changes what new customers can buy → separate proposal.** |
| O3 | `CostRulesSeeder` uses Bunny **EU/US** rates (`$0.01/GB` storage & delivery) rather than MENA rates. | Provider-cost (internal profitability) figures are off for MENA delivery. | Update rates + recalc. **Affects internal cost/pro fit, and possibly displayed pricing → separate proposal.** |
| O4 | `SANCTUM_STATEFUL_DOMAINS` lacks tenant subdomains. | BFF calls originating from `*.fahimni.me` subdomains are treated stateless. | Add `*.fahimni.me` (env-only). **Production env change → approval.** |
| O5 | `cards.export` needs `RolePermissionSeeder` re-run in prod. | Teachers get `403` on export until re-seeded. | Run the idempotent seeder as an explicit, approved post-deploy step (see checklist). |
| O6 | Freemius / payment gateways. | — | **Not enabled.** No real payments turned on. Review only. |
| O7 | Cloudflare plan (WAF/firewall/DNS). | — | **Review only.** Production DNS/Firewall/WAF untouched. |

### 5.4 Known-untested / blocked

- **Google OAuth real-account E2E** — requires a real Google account + owner approval.
  All code paths and the HMAC/state/2FA/tenant logic are unit-covered; the live
  login → dashboard → refresh journey is the **single required human test** (§7).
- **Custom-domain add/verify** — needs real DNS.
- **Bunny Stream live** (upload → transcode → signed playback) — needs Bunny credentials;
  provider contract is covered by `BunnyMediaProviderTest` / `VideoSecurityTest` against
  fakes.
- **Freemius checkout**, **Google Meet live creation** — external accounts.
- Admin error-logs/tickets UI, teacher blog/services/bookings/analytics-report,
  student favorites/leaderboard, public tenant-site pages, contact, sitemap,
  certificate lookup — **NOT TESTED** (no executed coverage). Not claimed as passing.

---

## 6. Test evidence (this release, executed)

| Suite | Command | Result |
| --- | --- | --- |
| PHPUnit (full) | `php vendor/bin/phpunit --no-coverage` | **389 / 389 pass** (1463 assertions) |
| Frontend node | `npm run test:scripts` | **26 / 26 pass** |
| Frontend vitest | `npx vitest run` | **20 / 20 pass** (4 files) |
| TypeScript | `npx tsc --noEmit` | clean |
| Next build | `NEXT_PUBLIC_API_URL=/api/v1 NEXT_PUBLIC_PLATFORM_DOMAIN=fahimni.me npm run build` | success |
| Deploy scripts | `bash -n deploy/scripts/*.sh` | all clean |
| Brand-rollout tests | `bash deploy/tests/brand-rollout-nginx-test.sh` | all pass (incl. new T6b) |

---

## 7. Brand audit

| Surface | State |
| --- | --- |
| User-visible name | «منصة فهمني» / «فهمني» / *Fahimni* via `frontend/src/lib/brand.ts` + `app/Support/PlatformBrand.php`, which normalise any legacy value (`tadreesly|تدريسلي|تدريسلى`) on read and map legacy support mailboxes to `support@fahimni.me`. |
| Remaining literal old-brand strings | Only inside the **intentional** legacy-normalisation patterns and the deploy script’s *detection* greps (`grep -E 'Tadreesly|tadreesly'`). No user-visible string. |
| Email surfaces | Quran-teacher email fixed; `AuthController` verification link uses `app.frontend_url`; `.env.example` `MAIL_FROM_NAME="منصة فهمني"`. |
| Logo | `logo/logo.png` 1254×1254 (approved). Icons: `icon.png` 512², `apple-icon.png` 180², `icon-192/512`, `icon-maskable-512`, `og.png` 1200×630 — square ratios preserved, trimmed. |
| DB tables / API routes / internal variables | **Unchanged** (correct — brand is a presentation concern). |
| Runtime platform name | Live `/api/v1/platform-settings` still returns `Tadreesly`; the frontend normalises it, and the deploy rollout verifies the new name after deploy. |

---

## 8. Cost & pricing (findings only — see O1–O3)

- The cost engine computes from `provider_cost_rules` (now actually seeded).
- `payment_gateway` copy on the landing page was fixed earlier to stop claiming
  platform-provided Mada/PayPal; those are teacher-connected gateways, disabled by
  default.
- **No selling price was changed. No existing-customer entitlement was changed.**
  Any such change is a separate proposal.

---

## 9. What is *not* being claimed

- Not claiming commercial readiness: O1–O5 remain open and the live Google journey
  is unverified until the owner runs it.
- Not claiming full route coverage: see §5.4. Untested surfaces are labelled.
- No production data was deleted; no `migrate:fresh`; no `down -v`; no DNS/Caddy/WAF
  change; no real payment enabled.
