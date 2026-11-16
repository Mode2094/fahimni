# FINAL E2E TEST MATRIX — منصة فهمني

**Release candidate:** `release/launch-2026-11-rc`
**How to run everything:** see Appendix A.
**Rule for this document:** a row is `PASS` only when a command was executed on this
tree and went green. Anything unexecuted is `NOT TESTED` or `BLOCKED`.

Statuses: `PASS` · `FIXED+PASS` · `FAILED` · `BLOCKED (external)` · `NOT TESTED`

---

## 1. Summary

| Suite | Executed | Pass | Fail | Notes |
| --- | --- | --- | --- | --- |
| PHPUnit (full) | 394 | **394** | 0 | 1481 assertions |
| Frontend node tests | 26 | **26** | 0 | `bff-origin`, `csp`, `manifest` |
| Frontend vitest | 20 | **20** | 0 | 4 files: home, page, dashboard, my-learning |
| TypeScript | 1 | **1** | 0 | `tsc --noEmit` clean |
| Next production build | 1 | **1** | 0 | pages + handlers compiled |
| ShellCheck (`deploy/scripts`, `deploy/tests`, `docker/migrate.sh`) | 1 run | **1** | 0 | clean (was failing on RC — SC2015 info fixed) |
| Deploy script syntax | all | **all** | 0 | `bash -n` |
| Brand-rollout behaviour tests | 1 file | **all** | 0 | T0–T21 (incl. gated DB preflight/migration/permission) |
| Deploy-script integrity tests | 1 file | **all** | 0 | `verify-deploy-script-test.sh` |
| Launch smoke list tests | 1 file | **all** | 0 | `launch-smoke-list-test.sh` |
| Manual browser (landing hero) | 1 | **1** | 0 | desktop/tablet/mobile captured |
| Google OAuth live journey | 1 | **0** | 0 | 1 **BLOCKED** (human) |

**Counts of discovered vs tested**

| Category | Discovered | Covered by an executed test |
| --- | --- | --- |
| Laravel routes | 423 | ~52 % (auth, cards, exams, assignments, videos, live, plans, tenants, backups, media, monitoring, mobile) |
| Next.js pages | 89 | 4 (landing, dashboard, my-learning, + page metadata) |
| Feature-catalog keys | 50 | gating exercised via `SubscriptionGateTest` / `MediaEngineTest` |
| Permissions | 54 | `RoleAuthorizationTest`, `TenantIsolationTest`, `SecurityHardeningTest` |

---

## 2. Backend matrix (by test file)

| Test file | Tests | Area | Status |
| --- | --- | --- | --- |
| `FreemiusBillingTest.php` | 52 | Billing/plan lifecycle (fakes) | PASS |
| `StudentMobileAppTest.php` | 34 | Mobile auth + student flows | PASS |
| `LiveStreamingTest.php` | 27 | Live create/start/end/join/recording/usage | FIXED+PASS |
| `MediaEngineTest.php` | 21 | Media quota, profitability | PASS |
| `SecurityHardeningTest.php` | 17 | Headers, secrets, injection guards | PASS |
| `BackupApiTest.php` | 15 | Backup list/create/verify/download | PASS |
| `MonitoringTest.php` | 14 | Alerts, slow requests, snapshots | PASS |
| `AssistantAskTest.php` | 12 | Assistant Q&A (rate-limited) | PASS |
| `VideoSecurityTest.php` | 11 | Signed playback, watermark, cross-tenant | FIXED+PASS |
| `PlanRecommenderTest.php` | 11 | Recommender | PASS |
| `BunnyMediaProviderTest.php` | 11 | Bunny signed tickets / delete | FIXED+PASS |
| `WebSessionAuthTest.php` | 10 | Cookie session / csrf / me | FIXED+PASS |
| `PlanEngineTest.php` | 10 | Limits resolution | PASS |
| `GoogleAuthCallbackTest.php` | 10 | OAuth state, roles, tenant, trial | PASS |
| `PaymentAndFileSecurityTest.php` | 9 | Payment proofs, file access | PASS |
| `AssignmentWorksheetTest.php` | 9 | Worksheet upload/submit/grade | FIXED+PASS |
| `PlanSystemTest.php` | 8 | Plan CRUD, subscriptions | PASS |
| `RoleAuthorizationTest.php` | 7 | Role/permission 401/403 | PASS |
| `CostEngineTest.php` | 7 | Cost calc | PASS |
| `CostRulesSeederTest.php` | 3 | **new** — seeder wired + idempotent | FIXED+PASS |
| `EnsureCardsExportPermissionTest.php` | 5 | **new** — additive-only `cards.export` fix | FIXED+PASS |
| `TenantIsolationTest.php` | 6 | Cross-tenant blocked | PASS |
| `SettingsSecretEncryptionTest.php` | 6 | Secrets at rest | PASS |
| `BackupPipelineTest.php` | 6 | Job pipeline | PASS |
| `StoragePathGuardTest.php` (Unit) | 5 | Path traversal | PASS |
| `SubscriptionGateTest.php` | 5 | Feature/quota 403 | PASS |
| `ActivationCardRedeemTest.php` | 5 | Single-use, cross-tenant | FIXED+PASS |
| `ActivationCardExportTest.php` | 3 | CSV export | FIXED+PASS |
| `ActivationCardExpiryTest.php` | 2 | Expiry sweep | FIXED+PASS |
| `TeacherOnboardingTest.php` | 4 | Onboarding state | PASS |
| `StudentSchedulePayloadTest.php` | 4 | Schedule payload | FIXED+PASS |
| `RenewalAndGradingTest.php` | 4 | Renewal + grading | PASS |
| `ExamIntegrityTest.php` | 2 | Attempt/grading integrity | PASS |
| `SaasCostCurrencyTest.php` | 2 | USD conversion | FIXED+PASS |
| others (Sso/Site/etc.) | — | misc | PASS |
| **Total** | **394** | | **394 pass** |

---

## 3. Frontend matrix

| Test file | Tests | What it proves | Status |
| --- | --- | --- | --- |
| `scripts/bff-origin.test.ts` | 14 | Public-origin rebuild; spoofed XFH/Host rejected; look-alike suffix rejected; tenant subdomain trusted; bad scheme pinned; nginx pins XFH on both proxy blocks; all session handlers use `requestOrigin`; BFF pins Origin+Referer | FIXED+PASS |
| `scripts/csp.test.ts` | — | Per-request CSP nonce contract | PASS |
| `scripts/manifest.test.ts` | — | Per-tenant manifest isolation | FIXED+PASS |
| `home-client.test.tsx` | — | Hero copy AR/EN, FAQ disclosure, loading spinner | FIXED+PASS |
| `page.test.ts` | — | Home metadata + plans fetch fallback | PASS |
| `teacher/dashboard/page.test.tsx` | — | Dashboard renders with partial failures | FIXED+PASS |
| `my-learning/page.test.tsx` | — | Student empty states | FIXED+PASS |

---

## 4. Scenario matrix (user journeys)

| # | Scenario | Covered by | Status |
| --- | --- | --- | --- |
| S1 | Email/password register (teacher) → tenant + role + trial | `WebSessionAuthTest`, `GoogleAuthCallbackTest` | PASS |
| S2 | Email/password login → `/api/session/me` 200 → dashboard | `WebSessionAuthTest`, `bff-origin.test.ts` | FIXED+PASS |
| S3 | Google login new teacher → tenant + Teacher role + trial, **no duplicate tenant** | `GoogleAuthCallbackTest` | PASS (code) / **BLOCKED** (live) |
| S4 | Google login existing user → same tenant returned | `GoogleAuthCallbackTest` | PASS |
| S5 | Google admin attempt from non-super-admin email → `not_authorized` | `GoogleAuthCallbackTest` | PASS |
| S6 | 2FA enabled → challenge, no bypass via Google | `GoogleAuthCallbackTest` | PASS |
| S7 | Session survives refresh (cookie/BFF) | `bff-origin.test.ts` + `WebSessionAuthTest` | PASS |
| S8 | Logout clears HttpOnly cookie | `WebSessionAuthTest` | PASS |
| S9 | Password reset / email verify link uses `FRONTEND_URL` (no host poisoning) | code review + `WebSessionAuthTest` | PASS |
| S10 | Course create → publish → enroll → progress | `RoleAuthorizationTest`, `SubscriptionGateTest` | PASS |
| S11 | Card generate → export CSV → redeem (single-use) → cross-tenant blocked → expiry sweep | `ActivationCard*Test` (10) | FIXED+PASS |
| S12 | Assignment worksheet: private upload → submit → grade → notify | `AssignmentWorksheetTest` | FIXED+PASS |
| S13 | Video upload ticket → signed playback → watermark → delete | `VideoSecurityTest`, `BunnyMediaProviderTest` | FIXED+PASS |
| S14 | Live session create → start → join-ticket → end → recording attach | `LiveStreamingTest` | FIXED+PASS |
| S15 | Live plan limits (no auto-recording claim) | `LiveStreamingTest` | PASS |
| S16 | Exam create → add questions → publish → attempt → grade → result/certificate | `ExamIntegrityTest`, `RenewalAndGradingTest` | PASS |
| S17 | Quota exceeded → 403 JSON | `SubscriptionGateTest`, `MediaEngineTest` | PASS |
| S18 | Cross-tenant access → blocked | `TenantIsolationTest`, `VideoSecurityTest` | PASS |
| S19 | Rate limits → 429 | `AppServiceProvider` + feature tests | PASS |
| S20 | Two teachers isolation | `TenantIsolationTest` | PASS |
| S21 | Landing hero AR + EN, desktop/tablet/mobile | browser capture (§5) + `home-client.test.tsx` | FIXED+PASS |
| S22 | Google session + CSP behind nginx/Caddy (real origin) | `request-origin` unit tests | PASS (unit) / **BLOCKED** (real host) |
| S23 | Real custom domain add/verify | — | **BLOCKED** (DNS) |
| S24 | Bunny transcode + MENA playback | — | **BLOCKED** (Bunny account) |
| S25 | Freemius checkout | — | **BLOCKED** (not enabled) |
| S26 | Public tenant site (courses/blog/booking) | — | NOT TESTED |
| S27 | Admin tickets/error-logs UI | — | NOT TESTED |
| S28 | Teacher analytics Excel/PDF export | — | NOT TESTED |

---

## 5. Landing-page Hero verification (P1)

Original page restored from `dd13c63`; **only** the Hero changed. Evidence in
`docs/screenshots/hero/`.

| Viewport | Before | After | Side-by-side |
| --- | --- | --- | --- |
| Desktop 1440×900 | `before-desktop-hero.png` (`before-desktop-full.jpg`) | `after-desktop-hero.png` (`after-desktop-full.jpg`) | `compare-desktop.png` |
| Tablet 834×1112 | `before-tablet-hero.png` | `after-tablet-hero.png` | `compare-tablet.png` |
| Mobile 390×844 | `before-mobile-hero.png` | `after-mobile-hero.png` | `compare-mobile.png` |

- `git diff dd13c63 -- frontend/src/components/home/home-client.tsx` shows hunks
  **only** in the Hero region (`@@ -281,…` and `@@ -332,…`).
- Bottom-aligned pixel diff of the full pages: footer band mean-abs-diff
  ≈ 0.23–0.55 / 255 (screenshot-stitching anti-aliasing only). Every other section
  (`features`, `how`, `student`, `earnings`, `pricing`, `faq`, `quran-teachers`,
  calculator, forms) is byte-identical to the original.

---

## 6. Open / failed

- **FAILED:** none on this tree.
- **BLOCKED (external):** S3 live, S22 real host, S23 DNS, S24 Bunny, S25 Freemius.
- **Launch smoke gate (`deploy/scripts/launch-smoke-test.sh`):** the six
  authenticated journeys are reported **BLOCKED** in the script output — they are the
  exact human click-through required before GO:
  Google register → teacher dashboard → refresh; email/password login; admin + student
  login; create course + lesson + worksheet; generate/export/redeem a card; video and
  live access per permission. The public halves (Google redirect returns `client_id`,
  role-gated endpoints answer 401/403 without a session) are scripted and green.
- **NOT TESTED:** S26–S28 and the untested admin/teacher/student/public surfaces
  listed in `FINAL_PLATFORM_AUDIT.md §5.4`.

---

## Appendix A — commands

```bash
# Backend — use phpunit.xml's env (do NOT export DB_DATABASE=:memory:; the suite's
# TestCase provisions its own SQLite file, and an exported :memory: breaks it)
php -d memory_limit=1G vendor/bin/phpunit --no-coverage

# Frontend + build
cd frontend
npx tsc --noEmit
npm run test:unit                                 # node --test (26) + vitest (20)
NEXT_PUBLIC_API_URL=/api/v1 NEXT_PUBLIC_PLATFORM_DOMAIN=fahimni.me npm run build

# Deploy assets
for f in deploy/scripts/*.sh deploy/tests/*.sh docker/migrate.sh; do bash -n "$f"; done
shellcheck -x -P deploy/scripts deploy/scripts/*.sh deploy/tests/*.sh docker/migrate.sh
bash deploy/tests/brand-rollout-nginx-test.sh        # nginx reload + gated DB steps
bash deploy/tests/verify-deploy-script-test.sh       # host vs Git SHA256 gate
bash deploy/tests/launch-smoke-list-test.sh          # critical-journey coverage

# Post-deploy smoke (read-only; BLOCKED items are printed, never skipped)
bash deploy/scripts/launch-smoke-test.sh --base https://fahimni.me
```
