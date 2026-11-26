# Route / API Inventory — Fahimni

Auto-extracted from source on 2026-11-26. No route was guessed: frontend entries come
from `frontend/src/app/**/page.tsx` and `frontend/src/app/**/route.ts`; backend entries
come from the literal `Route::` calls in `routes/api.php` and `routes/web.php`, with the
effective prefix and middleware resolved from the actual `Route::group` / `prefix` /
`middleware` nesting.

## Sources & method

| Layer | Source | Files |
| --- | --- | --- |
| Frontend pages | Next.js App Router `page.tsx` | `frontend/src/app/**` |
| Frontend API/BFF | Route handlers `route.ts` | `frontend/src/app/**/route.ts` |
| Host routing / rewrite | Edge middleware | `frontend/src/proxy.ts`, `frontend/src/lib/tenant-routing.ts` |
| Backend API | Laravel routes | `routes/api.php` (975 lines) |
| Backend web | Laravel routes | `routes/web.php` (15 lines) |
| Middleware aliases | Bootstrap | `bootstrap/app.php` |

### Host legend

| Code | Meaning |
| --- | --- |
| `platform` | `fahimni.me` / `www.fahimni.me` / `api.fahimni.me` |
| `teacher` | Teacher's own host: verified custom domain or `{slug}.fahimni.me` |
| `both` | Reachable / required on either host (public tenant site, domain lookup, auth) |

The Next edge proxy (`frontend/src/proxy.ts`) only rewrites these public tenant-site roots
on a teacher host into `/t/{slug}`: `''`, `courses`, `register` (and `/student`), `blog`,
`book` (see `TENANT_SITE_ROOTS` in `lib/tenant-routing.ts`). Every other path — the signed-in
student app, the teacher panel, and all `/admin/*` routes — keeps its platform path and is
served unchanged even when the request arrives on a teacher host. This is why the
inaccessible-on-teacher-host matrix below matters: those routes are **not** path-blocked by
the proxy today and must be refused by the backend / panel guard.

### Auth mechanism legend (backend)

| Mechanism | Description |
| --- | --- |
| `none` | Public. No credentials. |
| `sanctum` | `auth:sanctum` — first-party session cookie (stateful) on web/BFF, or bearer token for the mobile app. Returns 401 JSON. |
| `sanctum+admin` | `auth:sanctum` + `admin` alias (`AdminMiddleware`) → requires `is_super_admin`, else 403. |
| `signed` | HMAC-signed URL/token is the authorization; no session. |
| `hmac-body` | Provider webhook verified by signature over the raw body. |

### Middleware alias reference (`bootstrap/app.php`)

| Alias | Class | Effect |
| --- | --- | --- |
| `tenant` | `TenantMiddleware` | Resolves tenant from `X-Tenant-Id`/`tenant_id`/card URL. Super admins pass; a non-admin with no valid tenant is rejected. |
| `device` | `DeviceSessionMiddleware` | Token bound to a device must present matching `X-Device-Id`, else 401. |
| `subscription` | `RequireSubscription` | Tenant staff suspended (402) when plan/trial lapses. Students + super admins pass. Allow-listed prefixes bypass. |
| `profile` | `EnsureTeacherProfileComplete` | A teacher without a phone is blocked (403 `profile_incomplete`); students/super admins pass. |
| `admin` | `AdminMiddleware` | `is_super_admin` required (403 otherwise). **Distinct from the tenant role `admin`.** |
| `role:a,b` / `role:...,permission:x` | `EnsureRole` | Requires one of the listed tenant roles (teacher/owner/admin). `admin` here is a *tenant* role, not super admin. Super admins always pass. |
| `feature:key` | `CheckFeature` | Plan feature gate → 403 if the tenant's plan lacks the feature. Super admins pass. |

Global API group (all `/api/*`): `MaintenanceMiddleware`, `throttle:api`, `MetricsMiddleware`,
`EnsureAccountActive`, `BlockViewAsWrites`, `BlockSensitiveSupportWrites`, plus
`TrustTenantDomainsStatefully` before Sanctum's stateful check.

---

## 1. Frontend routes — pages (`page.tsx`)

Route groups appear in parentheses; dynamic segments in brackets.

### 1a. Public / platform marketing (surface: public)

| Route | Surface | Expected host | Auth | Tenant | Notes |
| --- | --- | --- | --- | --- | --- |
| `/` | public | platform | no | no | Landing page. |
| `/pricing` | public | platform | no | no | Public plans + plan recommender entry. |
| `/for-teachers` | public | platform | no | no | Marketing. |
| `/online-teaching-platform` | public | platform | no | no | Marketing. |
| `/create-online-courses` | public | platform | no | no | Marketing. |
| `/lms-for-teachers` | public | platform | no | no | Marketing. |
| `/live-online-classes` | public | platform | no | no | Marketing. |
| `/teacher-website` | public | platform | no | no | Marketing. |
| `/contact` | public | platform | no | no | Submits `POST public/contact`. |
| `/privacy` | public | platform | no | no | Legal. |
| `/terms` | public | platform | no | no | Legal. |
| `/refund-policy` | public | platform | no | no | Legal. |
| `/support` | public | platform | no | no | Support/help. |
| `/offline` | public | platform | no | no | PWA offline fallback. |
| `/blog` | teacher | platform | yes (session/BFF) | yes | Teacher blog manager (`/blog` is in `TEACHER_ROUTES`). Uses `/teacher/blog` API. |
| `/embed/question` | public | both | no | no | Interactive-question sandbox (own CSP). |
| `/manifest.webmanifest` | public | platform | no | no | Dynamic PWA manifest. |

### 1b. Auth & account flows (surface: public / shared)

| Route | Surface | Expected host | Auth | Tenant | Notes |
| --- | --- | --- | --- | --- | --- |
| `/student/login` | public | both | no | optional | Shared student login; on platform carries `?tenant_slug=`, on teacher host resolves from host. |
| `/teacher/login` | public | platform | no | no | Teacher login. |
| `/admin/login` | public | platform | no | no | Super-admin login (rendered standalone by `AdminShell`). |
| `/register/student` | public | both | no | optional | On teacher host rewritten to `/t/{slug}/register` by proxy. |
| `/register/teacher` | public | platform | no | no | Platform teacher sign-up (never rewritten). |
| `/forgot-password` | public | platform | no | no | |
| `/reset-password` | public | platform | no | no | |
| `/verify-email` | public | platform | no | no | |
| `/verify-email/[token]` | public | platform | no | no | Email verification with token. |
| `/verify/[number]` | public | both | no | no | Public certificate verification. |
| `/invite/[token]` | public | platform | no | no | Teacher staff invitation acceptance. |
| `/auth/google/callback` | public | both | no (state) | no | Google OAuth web callback handoff. |
| `/auth/google/complete` | public | both | no (state) | no | Complete Google sign-up (name/phone). |
| `/checkout` | student | platform | yes (session/BFF) | yes | Freemius subscription checkout for tenant staff. |
| `/checkout/success` | student | platform | yes (session/BFF) | yes | Post-checkout return. |
| `/book/success` | public | both | no | yes | Booking confirmation page. |
| `/card/[code]` | public | both | no | yes | Activation-card redemption landing; resolves tenant from card. |
| `/offline` | public | platform | no | no | |

### 1c. Public teacher site — tenant pages (surface: public, host-aware)

Platform host serves these under `/t/{slug}`; teacher host serves the same component at the
root via proxy rewrite. Both hosts, tenant context required, no auth.

| Route | Surface | Expected host | Auth | Tenant | Notes |
| --- | --- | --- | --- | --- | --- |
| `/t/[slug]` | public | both | no | yes | Teacher/academy homepage. |
| `/t/[slug]/courses` | public | both | no | yes | Public course catalog. |
| `/t/[slug]/courses/[courseId]` | public | both | no | yes | Public course detail (preview). |
| `/t/[slug]/blog` | public | both | no | yes | Public blog list. |
| `/t/[slug]/blog/[post]` | public | both | no | yes | Public blog post. |
| `/t/[slug]/book` | public | both | no | yes | Public booking / paid services. |
| `/t/[slug]/register` | public | both | no | yes | Student registration for the tenant. |

### 1d. Student app (surface: student)

Authenticated student/shared panel routes on the platform host. `RouteShell` treats these as
`needsAuth` and redirects unauthenticated users to `/student/login`.

| Route | Surface | Expected host | Auth | Tenant | Notes |
| --- | --- | --- | --- | --- | --- |
| `/app` | student | platform | session-aware | optional | PWA launch entry; redirects by role via `dashboardPathFor`. |
| `/my-learning` | student | platform | yes (session/BFF) | yes | |
| `/my-notes` | student | platform | yes (session/BFF) | yes | |
| `/exams` | student | platform | yes (session/BFF) | yes | |
| `/exam/[id]` | student | platform | yes (session/BFF) | yes | Exam runner. |
| `/results` | student | platform | yes (session/BFF) | yes | |
| `/certificates` | student | platform | yes (session/BFF) | yes | |
| `/certificates/[id]` | student | platform | yes (session/BFF) | yes | |
| `/leaderboard` | student | platform | yes (session/BFF) | yes | |
| `/messages` | student | both | yes (session/BFF) | yes | Shared by student + teacher. |
| `/notifications` | student | platform | yes (session/BFF) | yes | |
| `/announcements` | teacher | platform | yes (session/BFF) | yes | Shared announcement manager (teacher nav + API role-gated). |
| `/my-bookings` | student | platform | yes (session/BFF) | yes | |
| `/profile` | student | platform | yes (session/BFF) | yes | |
| `/community` | student | platform | yes (session/BFF) | yes | Feature-gated `community_enabled`. |
| `/community/groups` | student | platform | yes (session/BFF) | yes | |
| `/community/groups/[id]` | student | platform | yes (session/BFF) | yes | |
| `/community/threads/[id]` | student | platform | yes (session/BFF) | yes | |
| `/course/[id]/learn` | student | both | yes (session/BFF) | yes | Course player; access/entitlement enforced by backend. |
| `/live/[id]` | student | both | yes (session/BFF) | yes | Live-session join. |

### 1e. Teacher workspace (surface: teacher)

Authenticated teacher/staff panel. `TeacherShell` requires the Teacher role (or super admin),
forces phone completion and plan selection.

| Route | Surface | Expected host | Auth | Tenant | Notes |
| --- | --- | --- | --- | --- | --- |
| `/teacher/login` | public | platform | no | no | (listed here for namespace completeness). |
| `/teacher/complete-profile` | teacher | platform | yes (session/BFF) | yes | Mandatory name+phone; reachable without profile gate. |
| `/teacher/dashboard` | teacher | platform | yes (session/BFF) | yes | |
| `/teacher/appointments` | teacher | platform | yes (session/BFF) | yes | Feature `appointments_enabled`. |
| `/teacher/assistant` | teacher | platform | yes (session/BFF) | yes | "Ask me" assistant. |
| `/teacher/bundles` | teacher | platform | yes (session/BFF) | yes | |
| `/teacher/cards` | teacher | platform | yes (session/BFF) | yes | Feature `cards_enabled`. |
| `/teacher/exams` | teacher | platform | yes (session/BFF) | yes | |
| `/teacher/follow-up` | teacher | platform | yes (session/BFF) | yes | Student follow-up. |
| `/teacher/live` | teacher | platform | yes (session/BFF) | yes | Feature `live_stream_enabled`. |
| `/teacher/live/[id]` / `[id]/studio` | teacher | platform | yes (session/BFF) | yes | Directories exist; `page.tsx` not present at this commit (studio may be nested). |
| `/teacher/notifications` | teacher | platform | yes (session/BFF) | yes | Platform notifications from super admin. |
| `/teacher/qa` | teacher | platform | yes (session/BFF) | yes | Student lesson questions. |
| `/teacher/questions` | teacher | platform | yes (session/BFF) | yes | Question bank. |
| `/teacher/schedule` | teacher | platform | yes (session/BFF) | yes | Feature `scheduled_lessons_enabled`. |
| `/teacher/services` | teacher | platform | yes (session/BFF) | yes | Feature `paid_services_enabled`. |
| `/teacher/settings` | teacher | platform | yes (session/BFF) | yes | |
| `/teacher/staff` | teacher | platform | yes (session/BFF) | yes | Feature `multi_teacher_enabled`. |
| `/teacher/student-app` | teacher | platform | yes (session/BFF) | yes | |
| `/teacher/support` | teacher | platform | yes (session/BFF) | yes | |
| `/teacher/terms` | teacher | platform | yes (session/BFF) | yes | Academic terms. |
| `/teacher/courses` | teacher | platform **and** teacher host | yes (session/BFF) | yes | **Canonical course builder list.** Outside the public tenant-site roots, so reachable on a teacher's own domain. |
| `/teacher/courses/[id]` | teacher | platform **and** teacher host | yes (session/BFF) | yes | **Canonical course builder detail.** |
| `/courses` | teacher | platform | yes (session/BFF) | yes | Platform alias of `/teacher/courses`; on a teacher host this path is the tenant PUBLIC catalogue (`/t/{slug}/courses`). |
| `/courses/[id]` | teacher | platform | yes (session/BFF) | yes | Platform alias of `/teacher/courses/[id]`; on a teacher host this path is the tenant PUBLIC course detail. |
| `/students` | teacher | platform | yes (session/BFF) | yes | Student management. |
| `/analytics` | teacher | platform | yes (session/BFF) | yes | Feature `export_reports`. |

### 1f. Super-admin panel (surface: super-admin)

`AdminShell` requires `is_super_admin`; a non-admin is redirected to their own dashboard.
ALL of these must be **inaccessible on a teacher host**.

| Route | Surface | Expected host | Auth | Tenant | Notes |
| --- | --- | --- | --- | --- | --- |
| `/admin/login` | public | platform | no | no | Login screen only. |
| `/admin/dashboard` | super-admin | platform | yes (super admin) | no | |
| `/admin/teachers` | super-admin | platform | yes (super admin) | no | |
| `/admin/students` | super-admin | platform | yes (super admin) | no | |
| `/admin/subscriptions` | super-admin | platform | yes (super admin) | no | |
| `/admin/plans` | super-admin | platform | yes (super admin) | no | |
| `/admin/payments` | super-admin | platform | yes (super admin) | no | |
| `/admin/invoices` | super-admin | platform | yes (super admin) | no | |
| `/admin/transactions` | super-admin | platform | yes (super admin) | no | |
| `/admin/coupons` | super-admin | platform | yes (super admin) | no | |
| `/admin/cards` | super-admin | platform | yes (super admin) | no | |
| `/admin/tickets` | super-admin | platform | yes (super admin) | no | |
| `/admin/quran-requests` | super-admin | platform | yes (super admin) | no | |
| `/admin/teacher-notifications` | super-admin | platform | yes (super admin) | no | |
| `/admin/live` | super-admin | platform | yes (super admin) | no | |
| `/admin/media` | super-admin | platform | yes (super admin) | no | |
| `/admin/bi` | super-admin | platform | yes (super admin) | no | |
| `/admin/monitoring` | super-admin | platform | yes (super admin) | no | |
| `/admin/monitoring/frontend` | super-admin | platform | yes (super admin) | no | |
| `/admin/logs` | super-admin | platform | yes (super admin) | no | |
| `/admin/settings` | super-admin | platform | yes (super admin) | no | |
| `/admin/view-as` | super-admin | platform | yes (super admin) | no | Read-only impersonation. |
| `/admin/support/enter` | super-admin | platform | yes (super admin) | no | Support-session activation. |

> Note: `frontend/src/app/admin/sales/` exists but contains no `page.tsx` (empty directory) — no route.

---

## 2. Frontend API / BFF routes (`route.ts`)

All BFF handlers run server-to-server against Laravel and exchange **HttpOnly session
cookies** (`lib/bff.ts`). The browser never receives a Sanctum bearer token. `resolveRequestOrigin`
adapts the origin so the same handler works on platform and teacher hosts.

| Route | Methods | Surface | Expected host | Auth | Tenant | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| `/api/session/csrf` | GET | public | both | none | optional | Issues CSRF/XSRF token. |
| `/api/session/login` | POST | public | both | none (issues session) | optional | Credentials → HttpOnly session; passes `x-device-id`. |
| `/api/session/register` | POST | public | both | none | optional | Student registration. |
| `/api/session/2fa` | POST | public | both | none (2FA challenge) | optional | 2FA confirm. |
| `/api/session/complete-profile` | POST | teacher | both | session (partial) | optional | Complete name/phone without the profile gate. |
| `/api/session/refresh` | GET | public | both | session cookie | optional | Refresh session. |
| `/api/session/me` | GET | public | both | session cookie | optional | Current user from session. |
| `/api/session/logout` | POST | public | both | session cookie | optional | Destroys session. |
| `/api/session/google/redirect` | GET | public | both | none | optional | Starts Google OAuth. |
| `/api/session/google/redeem` | POST | public | both | signed state | optional | Cross-host handoff redemption. |
| `/embed/question` | GET | public | both | none | no | Sandbox question embed. |
| `/manifest.webmanifest` | GET | public | platform | none | no | PWA manifest. |

---

## 3. Backend Laravel routes

Path prefix `v1` applies to everything inside `Route::prefix('v1')` → full URI `/api/v1/...`.
Web routes have no `/api` prefix except the explicitly-named `api/v1/...` callback.

### 3a. Health & probes (surface: public)

| Method + URI | Surface | Host | Auth | Tenant | Notes |
| --- | --- | --- | --- | --- | --- |
| GET `/up` | public | both | none | no | Framework health route (`withRouting health`). |
| GET `/api/health` | public | both | none | no | Liveness. |
| GET `/api/health/live` | public | both | none | no | Liveness probe. |
| GET `/api/health/ready` | public | both | none | no | Readiness probe. |
| GET `/health` (web.php) | public | both | none | no | Web health. |

### 3b. Auth & account (surface: public / shared) — no auth group

| Method + URI | Surface | Host | Auth | Tenant | Notes |
| --- | --- | --- | --- | --- | --- |
| POST `/api/v1/auth/register` | public | both | none | no | `throttle:register`. |
| POST `/api/v1/auth/login` | public | both | none | optional | `throttle:login`. |
| GET `/api/v1/auth/google/redirect` | public | both | none | no | Google OAuth start. |
| POST `/api/v1/auth/google/redeem` | public | both | signed state | no | `throttle:login`. Cross-host handoff. |
| POST `/api/v1/auth/forgot-password` | public | both | none | no | `throttle:forgot-password`. |
| POST `/api/v1/auth/reset-password` | public | both | none | no | `throttle:reset-password`. |
| POST `/api/v1/auth/captcha` | public | both | none | no | `throttle:captcha`. |
| POST `/api/v1/auth/2fa/confirm` | public | both | 2FA challenge | no | `throttle:two-factor`. |
| GET `/api/v1/auth/google/callback` (web.php) | public | both | signed state | no | `throttle:30,1`; issues HttpOnly session. |

### 3c. Student Mobile App API (surface: student)

| Method + URI | Surface | Host | Auth | Tenant | Notes |
| --- | --- | --- | --- | --- | --- |
| POST `/api/v1/mobile/teacher-code/validate` | public | both | none | yes | `throttle:mobile-code`. Validates teacher code. |
| POST `/api/v1/mobile/auth/login` | public | both | none | yes | `throttle:login`. Scoped student login. |
| POST `/api/v1/mobile/auth/register` | public | both | none | yes | `throttle:register`. |
| POST `/api/v1/mobile/auth/google` | public | both | none | yes | `throttle:login`. Bearer flow. |
| POST `/api/v1/mobile/auth/2fa/confirm` | public | both | 2FA challenge | yes | `throttle:two-factor`. |
| GET `/api/v1/mobile/me` | student | both | sanctum (bearer/token) | yes | Device-bound. |
| GET `/api/v1/mobile/teacher/context` | student | both | sanctum | yes | Server-enforced teacher context. |
| GET `/api/v1/mobile/site` | student | both | sanctum | yes | |
| GET `/api/v1/mobile/home` | student | both | sanctum | yes | |
| GET `/api/v1/mobile/courses` | student | both | sanctum | yes | |
| GET `/api/v1/mobile/courses/{course}` | student | both | sanctum | yes | |
| GET `/api/v1/mobile/lessons/{lesson}` | student | both | sanctum | yes | |
| POST `/api/v1/mobile/video-position` | student | both | sanctum | yes | `throttle:position`. |
| GET `/api/v1/mobile/announcements` | student | both | sanctum | yes | |

### 3d. Webhooks & billing callbacks (surface: public)

| Method + URI | Surface | Host | Auth | Tenant | Notes |
| --- | --- | --- | --- | --- | --- |
| POST `/api/v1/payments/freemius-webhook` | public | platform | hmac-body | no | `throttle:webhooks`. Sole entitlement activation signal. |
| POST `/api/v1/payments/paytabs-callback` | public | both | hmac-body | yes | `throttle:webhooks`. Booking payments only. |
| POST `/api/v1/payments/paypal-webhook` | public | both | hmac-body | yes | `throttle:webhooks`. Booking payments only. |
| GET `/api/v1/payments/paypal-capture` | public | both | provider redirect | yes | `throttle:webhooks`. |
| POST `/api/v1/media/webhook/{provider}` | public | platform | hmac-body | no | `throttle:webhooks`. Bunny events. |
| POST `/api/v1/monitoring/ingest` | public | both | none | no | `throttle:monitoring-ingest`. Client error ingest. |

### 3e. Authenticated (auth:sanctum, pre-tenant) — surface: shared / super-admin

Inside `Route::middleware('auth:sanctum')` (api.php:166), **before** the tenant group.

| Method + URI | Surface | Host | Auth | Tenant | Notes |
| --- | --- | --- | --- | --- | --- |
| POST `/api/v1/auth/logout` | shared | both | sanctum | optional | |
| GET `/api/v1/auth/me` | shared | both | sanctum | optional | |
| POST `/api/v1/auth/complete-profile` | teacher | both | sanctum | optional | `throttle:login`. Outside profile gate. |
| POST `/api/v1/auth/2fa/setup` | shared | both | sanctum | optional | |
| POST `/api/v1/auth/2fa/enable` | shared | both | sanctum | optional | |
| POST `/api/v1/auth/2fa/disable` | shared | both | sanctum | optional | |
| POST `/api/v1/auth/email/verify` | shared | both | sanctum | optional | |
| POST `/api/v1/auth/email/resend` | shared | both | sanctum | optional | `throttle:email-verification`. |
| PUT `/api/v1/user/profile` | shared | both | sanctum + `profile` | optional | |
| PUT `/api/v1/user/password` | shared | both | sanctum + `profile` | optional | |
| GET `/api/v1/videos/{video}/playback` | student | both | sanctum | no | `throttle:playback`. Students may lack tenant. |
| POST `/api/v1/videos/{video}/position` | student | both | sanctum | no | `throttle:position`. |
| GET `/api/v1/student/profile` | student | both | sanctum | no | |
| GET `/api/v1/student/stats` | student | both | sanctum | no | |
| GET `/api/v1/student/streak` | student | both | sanctum | no | |
| GET `/api/v1/student/leaderboard` | student | both | sanctum | no | |
| GET `/api/v1/student/achievements` | student | both | sanctum | no | |
| GET `/api/v1/student/points` | student | both | sanctum | no | |
| GET `/api/v1/student/activity` | student | both | sanctum | no | |
| GET `/api/v1/student/favorites` | student | both | sanctum | no | |
| POST `/api/v1/student/favorites/{course}` | student | both | sanctum | no | |
| GET `/api/v1/student/notes` | student | both | sanctum | no | `throttle:notes-read`. Owner-only. |
| GET `/api/v1/student/notes/summary` | student | both | sanctum | no | `throttle:notes-read`. |
| POST `/api/v1/student/notes` | student | both | sanctum | no | `throttle:notes-write`. |
| PUT `/api/v1/student/notes/{note}` | student | both | sanctum | no | `throttle:notes-write`. |
| DELETE `/api/v1/student/notes/{note}` | student | both | sanctum | no | `throttle:notes-write`. |
| GET `/api/v1/tenants` | shared | both | sanctum | optional | Lists user's tenants. |
| POST `/api/v1/tenants` | shared | both | sanctum | optional | |
| GET `/api/v1/tenants/current` | shared | both | sanctum | optional | |
| POST `/api/v1/tenants/switch` | shared | both | sanctum | optional | |

#### Super-admin (auth:sanctum, super-admin re-checked in controller)

| Method + URI | Surface | Host | Auth | Tenant | Notes |
| --- | --- | --- | --- | --- | --- |
| GET `/api/v1/admin/view-as` | super-admin | platform | sanctum + controller super-admin check | no | Read-only view-as state. |
| POST `/api/v1/admin/view-as/{user}` | super-admin | platform | sanctum + controller check | no | Start preview; re-checks super admin. |
| DELETE `/api/v1/admin/view-as` | super-admin | platform | sanctum + active marker | no | Stop; whitelisted while previewing. |
| POST `/api/v1/admin/support/{teacher}/begin` | super-admin | platform | sanctum + controller check | no | Write-enabled support session. |
| POST `/api/v1/admin/support/activate` | super-admin | platform | sanctum + one-time token | no | |
| GET `/api/v1/admin/support` | super-admin | platform | sanctum + controller check | no | |
| DELETE `/api/v1/admin/support` | super-admin | platform | sanctum + active marker | no | Stop; whitelisted while active. |

### 3f. Tenant workspace (surface: student / teacher)

Inside `Route::middleware(['tenant','device','subscription','profile'])` (api.php:245). Every
route below additionally requires `auth:sanctum` from the enclosing group. Unless a row says
otherwise, tenant context **required** and host = platform (teacher host may also pass tenant
via headers, but the panel is platform-only).

#### Shared / role-gated (no extra role)

| Method + URI | Surface | Host | Auth | Tenant | Notes |
| --- | --- | --- | --- | --- | --- |
| GET `/api/v1/audit-logs` | teacher | platform | + `role:teacher,owner,admin,permission:analytics.view` | yes | |
| GET `/api/v1/dashboard` | shared | platform | tenant group | yes | |
| GET `/api/v1/courses` | teacher | platform | tenant group | yes | |
| POST `/api/v1/courses` | teacher | platform | tenant group | yes | |
| GET `/api/v1/courses/{course}` | teacher | platform | tenant group | yes | |
| GET `/api/v1/courses/{course}/search` | teacher | platform | tenant group | yes | |
| PUT `/api/v1/courses/{course}` | teacher | platform | tenant group | yes | |
| DELETE `/api/v1/courses/{course}` | teacher | platform | tenant group | yes | |
| POST `/api/v1/courses/{course}/publish` | teacher | platform | tenant group | yes | |
| POST `/api/v1/courses/{course}/unpublish` | teacher | platform | tenant group | yes | |
| POST `/api/v1/courses/{course}/duplicate` | teacher | platform | tenant group | yes | |
| GET `/api/v1/bundles` | teacher | platform | tenant group | yes | |
| POST `/api/v1/bundles` | teacher | platform | tenant group | yes | |
| GET `/api/v1/bundles/{bundle}` | teacher | platform | tenant group | yes | |
| PUT `/api/v1/bundles/{bundle}` | teacher | platform | tenant group | yes | |
| DELETE `/api/v1/bundles/{bundle}` | teacher | platform | tenant group | yes | |
| POST `/api/v1/bundles/{bundle}/enroll` | teacher | platform | tenant group | yes | |
| GET `/api/v1/sections` | teacher | platform | tenant group | yes | |
| POST `/api/v1/sections` | teacher | platform | tenant group | yes | |
| GET `/api/v1/sections/{section}` | teacher | platform | tenant group | yes | |
| PUT `/api/v1/sections/{section}` | teacher | platform | tenant group | yes | |
| DELETE `/api/v1/sections/{section}` | teacher | platform | tenant group | yes | |
| GET `/api/v1/lessons` | teacher | platform | tenant group | yes | |
| POST `/api/v1/lessons` | teacher | platform | tenant group | yes | |
| POST `/api/v1/lessons/reorder` | teacher | platform | tenant group | yes | |
| GET `/api/v1/lessons/{lesson}` | teacher | platform | tenant group | yes | |
| PUT `/api/v1/lessons/{lesson}` | teacher | platform | tenant group | yes | |
| DELETE `/api/v1/lessons/{lesson}` | teacher | platform | tenant group | yes | |
| POST `/api/v1/lessons/{lesson}/duplicate` | teacher | platform | tenant group | yes | |
| GET `/api/v1/courses/{course}/groups` | teacher | platform | tenant group | yes | |
| POST `/api/v1/courses/{course}/groups` | teacher | platform | tenant group | yes | |
| GET `/api/v1/course-groups/{group}` | teacher | platform | tenant group | yes | |
| PUT `/api/v1/course-groups/{group}` | teacher | platform | tenant group | yes | |
| DELETE `/api/v1/course-groups/{group}` | teacher | platform | tenant group | yes | |
| PUT `/api/v1/course-groups/{group}/members` | teacher | platform | tenant group | yes | |
| PUT `/api/v1/course-groups/{group}/lessons` | teacher | platform | tenant group | yes | |
| PUT `/api/v1/course-groups/{group}/exams` | teacher | platform | tenant group | yes | |
| GET `/api/v1/course-groups/{group}/results` | teacher | platform | tenant group | yes | |
| GET `/api/v1/lessons/{lesson}/contents` | teacher | platform | tenant group | yes | |
| POST `/api/v1/lesson-contents` | teacher | platform | tenant group | yes | |
| GET `/api/v1/lesson-contents/{content}` | teacher | platform | tenant group | yes | |
| PUT `/api/v1/lesson-contents/{content}` | teacher | platform | tenant group | yes | |
| DELETE `/api/v1/lesson-contents/{content}` | teacher | platform | tenant group | yes | |
| GET `/api/v1/lesson-questions` | teacher | platform | tenant group | yes | Teacher index of lesson Q&A. |
| POST `/api/v1/lesson-questions/{question}/answer` | teacher | platform | tenant group | yes | |
| GET `/api/v1/lessons/{lesson}/questions` | student | platform | tenant group | yes | |
| POST `/api/v1/lessons/{lesson}/questions` | student | platform | tenant group | yes | |
| DELETE `/api/v1/lesson-questions/{question}` | teacher | platform | tenant group | yes | |
| GET `/api/v1/enrollments` | teacher | platform | tenant group | yes | |
| POST `/api/v1/enrollments` | teacher | platform | tenant group | yes | |
| GET `/api/v1/enrollments/{enrollment}` | teacher | platform | tenant group | yes | |
| DELETE `/api/v1/enrollments/{enrollment}` | teacher | platform | tenant group | yes | |
| GET `/api/v1/my-courses` | student | platform | tenant group | yes | |
| GET `/api/v1/enrollments/{enrollment}/progress` | student | platform | tenant group | yes | |
| PUT `/api/v1/enrollments/{enrollment}/progress/{lesson}` | student | platform | tenant group | yes | |
| POST `/api/v1/enrollments/{enrollment}/progress/{lesson}/complete` | student | platform | tenant group | yes | |
| GET `/api/v1/assignments` | teacher | platform | tenant group | yes | |
| POST `/api/v1/assignments` | teacher | platform | tenant group | yes | |
| GET `/api/v1/assignments/{assignment}` | teacher | platform | tenant group | yes | |
| PUT `/api/v1/assignments/{assignment}` | teacher | platform | tenant group | yes | |
| DELETE `/api/v1/assignments/{assignment}` | teacher | platform | tenant group | yes | |
| POST `/api/v1/assignments/{assignment}/submit` | student | platform | tenant group | yes | |
| POST `/api/v1/assignments/{assignment}/submissions/{submission}/grade` | teacher | platform | tenant group | yes | |
| POST `/api/v1/submissions/{submission}/comments` | teacher | platform | tenant group | yes | |
| GET `/api/v1/assignments/{assignment}/file` | shared | platform | + `throttle:playback` | yes | Private-file download. |
| GET `/api/v1/submissions/{submission}/file` | shared | platform | + `throttle:playback` | yes | |
| GET `/api/v1/questions` | teacher | platform | tenant group | yes | Question bank. |
| POST `/api/v1/questions` | teacher | platform | tenant group | yes | |
| GET `/api/v1/questions/{question}` | teacher | platform | tenant group | yes | |
| PUT `/api/v1/questions/{question}` | teacher | platform | tenant group | yes | |
| DELETE `/api/v1/questions/{question}` | teacher | platform | tenant group | yes | |
| GET `/api/v1/questions/{question}/options` | teacher | platform | tenant group | yes | |
| POST `/api/v1/questions/{question}/options` | teacher | platform | tenant group | yes | |
| PUT `/api/v1/questions/{question}/options/{option}` | teacher | platform | tenant group | yes | |
| DELETE `/api/v1/questions/{question}/options/{option}` | teacher | platform | tenant group | yes | |
| GET `/api/v1/exams` | teacher | platform | tenant group | yes | |
| POST `/api/v1/exams` | teacher | platform | tenant group | yes | |
| GET `/api/v1/exams/{exam}` | teacher | platform | tenant group | yes | |
| PUT `/api/v1/exams/{exam}` | teacher | platform | tenant group | yes | |
| DELETE `/api/v1/exams/{exam}` | teacher | platform | tenant group | yes | |
| POST `/api/v1/exams/{exam}/publish` | teacher | platform | tenant group | yes | |
| POST `/api/v1/exams/{exam}/duplicate` | teacher | platform | tenant group | yes | |
| POST `/api/v1/exams/{exam}/add-questions` | teacher | platform | tenant group | yes | |
| POST `/api/v1/exams/{exam}/draw-questions` | teacher | platform | tenant group | yes | |
| POST `/api/v1/exams/{exam}/questions/reorder` | teacher | platform | tenant group | yes | |
| DELETE `/api/v1/exams/{exam}/questions/{question}` | teacher | platform | tenant group | yes | |
| GET `/api/v1/exams/{exam}/sections` | teacher | platform | tenant group | yes | |
| POST `/api/v1/exams/{exam}/sections` | teacher | platform | tenant group | yes | |
| PUT `/api/v1/exams/{exam}/sections/{section}` | teacher | platform | tenant group | yes | |
| DELETE `/api/v1/exams/{exam}/sections/{section}` | teacher | platform | tenant group | yes | |
| GET `/api/v1/exams/{exam}/attempts` | teacher | platform | tenant group | yes | |
| GET `/api/v1/exams/{exam}/questions` | teacher | platform | tenant group | yes | |
| POST `/api/v1/exams/{exam}/attempt` | student | platform | tenant group | yes | |
| POST `/api/v1/exams/{exam}/preview` | teacher | platform | tenant group | yes | Preview, no attempt recorded. |
| POST `/api/v1/exams/{exam}/preview/grade` | teacher | platform | tenant group | yes | |
| POST `/api/v1/exams/{exam}/attempts/{attempt}/submit` | student | platform | tenant group | yes | |
| POST `/api/v1/exams/{exam}/attempts/{attempt}/autosave` | student | platform | tenant group | yes | |
| POST `/api/v1/exams/{exam}/attempts/{attempt}/violation` | student | platform | tenant group (graded only) | yes | added — server-held monotonic anti-cheat count + threshold decision |
| POST `/api/v1/exams/{exam}/attempts/{attempt}/complete` | student | platform | tenant group (practice only) | yes | added §2/§3 ungraded practice |
| POST `/api/v1/exams/{exam}/attempts/{attempt}/grade` | teacher | platform | + `role:teacher,owner,admin,permission:exams.manage` | yes | |
| GET `/api/v1/exam-attempts/{attempt}` | shared | platform | tenant group | yes | |
| GET `/api/v1/cards` | teacher | platform | tenant group | yes | |
| GET `/api/v1/cards/batches` | teacher | platform | + `role:...,permission:cards.manage` + `feature:cards_enabled` | yes | |
| POST `/api/v1/cards/generate` | teacher | platform | + role/permission + `feature:cards_enabled` | yes | |
| POST `/api/v1/cards/import` | teacher | platform | + role/permission + `feature:cards_enabled` | yes | |
| GET `/api/v1/cards/import-template` | teacher | platform | + role/permission + `feature:cards_enabled` | yes | |
| GET `/api/v1/cards/export` | teacher | platform | + `role:...,permission:cards.export` + feature | yes | |
| POST `/api/v1/cards/redeem` | student | platform | + `throttle:redeem` (no feature gate) | yes | Cards stay redeemable if plan changes. |
| GET `/api/v1/cards/{card}` | teacher | platform | tenant group | yes | |
| POST `/api/v1/cards/{card}/cancel` | teacher | platform | + `role:...,permission:cards.manage` | yes | |
| POST `/api/v1/cards/{card}/revoke` | teacher | platform | + `role:...,permission:cards.manage` | yes | added §6 — revoke a redeemed card |
| GET `/api/v1/cards/batch/{batchId}` | teacher | platform | + role/permission + `feature:cards_enabled` | yes | |
| POST `/api/v1/videos/upload` | teacher | platform | + `throttle:video-upload` | yes | Direct upload. |
| POST `/api/v1/subscriptions/freemius/checkout` | teacher | platform | + `role:teacher,owner,admin` + `throttle:checkout` | yes | |
| GET `/api/v1/subscriptions/freemius/complete` | teacher | platform | + `throttle:checkout` | yes | |
| POST `/api/v1/subscriptions/freemius/portal` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| POST `/api/v1/subscriptions/freemius/cancel` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| GET `/api/v1/tickets` | shared | platform | tenant group | yes | My tickets. |
| POST `/api/v1/tickets` | shared | platform | tenant group | yes | |
| GET `/api/v1/tickets/{ticket}` | shared | platform | tenant group | yes | |
| POST `/api/v1/tickets/{ticket}/messages` | shared | platform | tenant group | yes | |
| GET `/api/v1/plans` | shared | platform | tenant group | yes | Public plan catalog (any authed user). |
| GET `/api/v1/plans/{plan}` | shared | platform | tenant group | yes | |
| GET `/api/v1/subscriptions` | shared | platform | tenant group | yes | |
| GET `/api/v1/subscriptions/current` | shared | platform | tenant group | yes | |
| POST `/api/v1/subscriptions` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| GET `/api/v1/subscriptions/{subscription}` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| POST `/api/v1/subscriptions/{subscription}/cancel` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| GET `/api/v1/payments` | shared | platform | tenant group | yes | |
| GET `/api/v1/payments/{payment}` | shared | platform | tenant group | yes | |
| GET `/api/v1/invoices` | shared | platform | tenant group | yes | |
| GET `/api/v1/invoices/{invoice}` | shared | platform | tenant group | yes | |
| GET `/api/v1/transactions` | shared | platform | tenant group | yes | |
| GET `/api/v1/transactions/{transaction}` | shared | platform | tenant group | yes | |
| GET `/api/v1/settings` | teacher | platform | tenant group | yes | |
| PUT `/api/v1/settings` | teacher | platform | tenant group | yes | |
| GET `/api/v1/me/preferences` | shared | platform | tenant group | yes | |
| PUT `/api/v1/me/preferences` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| GET `/api/v1/notifications` | shared | platform | tenant group | yes | |
| POST `/api/v1/notifications/{id}/read` | shared | platform | tenant group | yes | |
| POST `/api/v1/notifications/read-all` | shared | platform | tenant group | yes | |
| GET `/api/v1/notifications/unread-count` | shared | platform | tenant group | yes | |
| GET `/api/v1/admin-notifications` | teacher | platform | + `role:teacher,owner,admin` | yes | Read-only (no delete by design). |
| GET `/api/v1/admin-notifications/unread-count` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| POST `/api/v1/admin-notifications/read-all` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| GET `/api/v1/admin-notifications/{id}` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| POST `/api/v1/admin-notifications/{id}/read` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| POST `/api/v1/admin-notifications/{id}/contact` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| GET `/api/v1/preferences` | shared | platform | tenant group | yes | Notification preferences. |
| PUT `/api/v1/preferences` | shared | platform | tenant group | yes | |
| POST `/api/v1/push-tokens` | shared | platform | tenant group | yes | |
| DELETE `/api/v1/push-tokens` | shared | platform | tenant group | yes | |
| GET `/api/v1/announcements` | shared | platform | tenant group | yes | |
| POST `/api/v1/announcements` | teacher | platform | + `role:...,permission:announcements.manage` + `feature:announcements_enabled` | yes | |
| GET `/api/v1/announcements/{announcement}` | shared | platform | tenant group | yes | |
| PUT `/api/v1/announcements/{announcement}` | teacher | platform | + role/permission + feature | yes | |
| DELETE `/api/v1/announcements/{announcement}` | teacher | platform | + role/permission + feature | yes | |
| POST `/api/v1/announcements/{announcement}/send` | teacher | platform | + role/permission + feature + `throttle:announcements` | yes | |
| GET `/api/v1/conversations/unread-total` | shared | platform | tenant group | yes | |
| GET `/api/v1/conversations/contacts` | shared | platform | tenant group | yes | |
| GET `/api/v1/conversations` | shared | platform | tenant group | yes | |
| POST `/api/v1/conversations` | shared | platform | tenant group | yes | |
| GET `/api/v1/conversations/{conversation}` | shared | platform | tenant group | yes | |
| GET `/api/v1/conversations/{conversation}/messages` | shared | platform | tenant group | yes | |
| POST `/api/v1/conversations/{conversation}/messages` | shared | platform | tenant group | yes | |
| POST `/api/v1/conversations/{conversation}/read` | shared | platform | tenant group | yes | |
| GET `/api/v1/analytics` | teacher | platform | tenant group | yes | |
| GET `/api/v1/analytics/daily/{statType}` | teacher | platform | tenant group | yes | |
| POST `/api/v1/analytics/clear-cache` | super-admin | platform | + `admin` | yes | Super admin only. |
| GET `/api/v1/search` | shared | platform | tenant group | yes | |
| GET `/api/v1/search/courses` | shared | platform | tenant group | yes | |
| GET `/api/v1/search/lessons` | shared | platform | tenant group | yes | |
| GET `/api/v1/search/students` | teacher | platform | + `role:...,permission:students.manage` | yes | |
| GET `/api/v1/community/overview` | shared | platform | tenant group | yes | |
| POST `/api/v1/live-sessions/{session}/join-ticket` | shared | both | tenant group + `throttle:join-ticket` | yes | Public join on tenant site. |
| GET `/api/v1/live-sessions/{session}` | shared | both | tenant group | yes | |
| GET `/api/v1/scheduled-lessons` | student | platform | tenant group | yes | Student upcoming. |
| GET `/api/v1/student/exam-attempts` | student | platform | tenant group | yes | |
| GET `/api/v1/student/certificates` | student | platform | tenant group | yes | |
| GET `/api/v1/student/certificates/{certificate}` | student | platform | tenant group | yes | |
| GET `/api/v1/lessons/{lesson}/file-ticket` | student | platform | tenant group | yes | Signed file-ticket mint. |

> Note: the two `tickets` blocks above — the tenant-side (`AdminController::myTickets`, etc.)
> and the super-admin `/admin/tickets` — are distinct registrations with the same base path;
> `/api/v1/tickets` (no `/admin`) is the tenant/student one.

#### Teacher-only, role + feature gated

| Method + URI | Surface | Host | Auth | Tenant | Notes |
| --- | --- | --- | --- | --- | --- |
| GET `/api/v1/terms` | teacher | platform | + `role:teacher,owner,admin` | yes | Academic terms. |
| POST `/api/v1/terms` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| GET `/api/v1/terms/{term}` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| PUT `/api/v1/terms/{term}` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| DELETE `/api/v1/terms/{term}` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| POST `/api/v1/terms/{term}/activate` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| POST `/api/v1/terms/{term}/students` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| GET `/api/v1/terms/{term}/linkable-students` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| POST `/api/v1/terms/{term}/link-students` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| GET `/api/v1/terms/{term}/end-preview` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| POST `/api/v1/terms/{term}/end` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| POST `/api/v1/terms/{term}/promote` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| GET `/api/v1/community/groups` | shared | platform | + `feature:community_enabled` | yes | |
| POST `/api/v1/community/groups` | teacher | platform | + feature + `role:...,permission:community.manage` | yes | |
| GET `/api/v1/community/groups/{group}` | shared | platform | + feature | yes | |
| PUT `/api/v1/community/groups/{group}` | teacher | platform | + feature + role/permission | yes | |
| DELETE `/api/v1/community/groups/{group}` | teacher | platform | + feature + role/permission | yes | |
| POST `/api/v1/community/groups/{group}/join` | student | platform | + feature | yes | |
| POST `/api/v1/community/groups/{group}/leave` | student | platform | + feature | yes | |
| GET `/api/v1/community/groups/{group}/members` | shared | platform | + feature | yes | |
| PUT `/api/v1/community/groups/{group}/members/{member}` | teacher | platform | + feature + role/permission | yes | |
| DELETE `/api/v1/community/groups/{group}/members/{member}` | teacher | platform | + feature + role/permission | yes | |
| GET `/api/v1/community/threads` | shared | platform | + feature | yes | |
| POST `/api/v1/community/threads` | shared | platform | + feature | yes | |
| GET `/api/v1/community/threads/{thread}` | shared | platform | + feature | yes | |
| PUT `/api/v1/community/threads/{thread}` | shared | platform | + feature | yes | |
| DELETE `/api/v1/community/threads/{thread}` | shared | platform | + feature | yes | |
| POST `/api/v1/community/threads/{thread}/pin` | teacher | platform | + feature + role/permission | yes | |
| POST `/api/v1/community/threads/{thread}/status` | teacher | platform | + feature + role/permission | yes | |
| POST `/api/v1/community/threads/{thread}/like` | shared | platform | + feature | yes | |
| POST `/api/v1/community/threads/{thread}/comments` | shared | platform | + feature | yes | |
| PUT `/api/v1/community/threads/{thread}/comments/{comment}` | shared | platform | + feature | yes | |
| DELETE `/api/v1/community/threads/{thread}/comments/{comment}` | shared | platform | + feature | yes | |
| POST `/api/v1/community/threads/{thread}/comments/{comment}/answer` | shared | platform | + feature | yes | |
| POST `/api/v1/community/threads/{thread}/comments/{comment}/like` | shared | platform | + feature | yes | |
| GET `/api/v1/teacher/live-sessions/providers` | teacher | platform | + `role:...,permission:live.manage` + `feature:live_stream_enabled` | yes | |
| GET `/api/v1/teacher/live-sessions` | teacher | platform | + role/permission + feature | yes | |
| POST `/api/v1/teacher/live-sessions` | teacher | platform | + role/permission + feature + `throttle:live-create` | yes | |
| GET `/api/v1/teacher/live-sessions/{session}` | teacher | platform | + role/permission + feature | yes | |
| PUT `/api/v1/teacher/live-sessions/{session}` | teacher | platform | + role/permission + feature | yes | |
| POST `/api/v1/teacher/live-sessions/{session}/start` | teacher | platform | + role/permission + feature | yes | |
| POST `/api/v1/teacher/live-sessions/{session}/end` | teacher | platform | + role/permission + feature | yes | |
| POST `/api/v1/teacher/live-sessions/{session}/cancel` | teacher | platform | + role/permission + feature | yes | |
| POST `/api/v1/teacher/live-sessions/{session}/recording` | teacher | platform | + role/permission + feature | yes | |
| GET `/api/v1/teacher/live-sessions/by-lesson/{lesson}` | teacher | platform | + role/permission + feature | yes | |
| POST `/api/v1/teacher/live-sessions/{session}/join-ticket` | teacher | platform | + role/permission + feature + `throttle:join-ticket` | yes | |
| DELETE `/api/v1/teacher/live-sessions/{session}` | teacher | platform | + role/permission + feature | yes | |
| GET `/api/v1/teacher/scheduled-lessons` | teacher | platform | + `role:...,permission:schedule.manage` + `feature:scheduled_lessons_enabled` | yes | |
| POST `/api/v1/teacher/scheduled-lessons` | teacher | platform | + role/permission + feature | yes | |
| GET `/api/v1/teacher/scheduled-lessons/{lesson}` | teacher | platform | + role/permission + feature | yes | |
| PUT `/api/v1/teacher/scheduled-lessons/{lesson}` | teacher | platform | + role/permission + feature | yes | |
| POST `/api/v1/teacher/scheduled-lessons/{lesson}/complete` | teacher | platform | + role/permission + feature | yes | |
| POST `/api/v1/teacher/scheduled-lessons/{lesson}/cancel` | teacher | platform | + role/permission + feature | yes | |
| DELETE `/api/v1/teacher/scheduled-lessons/{lesson}` | teacher | platform | + role/permission + feature | yes | |
| GET `/api/v1/teacher/blog` | teacher | platform | + `feature:blog_enabled` + `role:...,permission:blog.manage` | yes | |
| POST `/api/v1/teacher/blog` | teacher | platform | + feature + role/permission | yes | |
| PUT `/api/v1/teacher/blog/{post}` | teacher | platform | + feature + role/permission | yes | |
| DELETE `/api/v1/teacher/blog/{post}` | teacher | platform | + feature + role/permission | yes | |
| POST `/api/v1/teacher/blog/{post}/publish` | teacher | platform | + feature + role/permission | yes | |
| POST `/api/v1/teacher/blog/{post}/unpublish` | teacher | platform | + feature + role/permission | yes | |
| GET `/api/v1/teacher/domain` | teacher | platform | + `role:...,permission:settings.manage` | yes | |
| GET `/api/v1/teacher/domain/slug-available` | teacher | platform | + role/permission + `throttle:slug-check` | yes | |
| PUT `/api/v1/teacher/domain/slug` | teacher | platform | + role/permission | yes | |
| PUT `/api/v1/teacher/domain` | teacher | platform | + role/permission | yes | |
| POST `/api/v1/teacher/domain/verify` | teacher | platform | + role/permission | yes | |
| DELETE `/api/v1/teacher/domain` | teacher | platform | + role/permission | yes | |
| POST `/api/v1/teacher/assistant/ask` | teacher | platform | + `role:teacher,owner,admin` + `throttle:assistant-ask` | yes | |
| GET `/api/v1/teacher/plan` | teacher | platform | tenant group | yes | |
| GET `/api/v1/teacher/onboarding` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| POST `/api/v1/teacher/onboarding/dismiss` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| POST `/api/v1/teacher/onboarding/restore` | teacher | platform | + `role:teacher,owner,admin` | yes | |
| GET `/api/v1/teacher/site-design` | teacher | platform | + `role:...,permission:settings.manage` | yes | |
| PUT `/api/v1/teacher/site-design` | teacher | platform | + role/permission | yes | |
| GET `/api/v1/teacher/student-app` | teacher | platform | + `role:...,permission:settings.manage` | yes | |
| POST `/api/v1/teacher/student-app/regenerate` | teacher | platform | + role/permission | yes | |
| GET `/api/v1/teacher/analytics` | teacher | platform | + `role:...,permission:analytics.view` | yes | |
| GET `/api/v1/teacher/analytics/report/pdf` | teacher | platform | + role/permission | yes | |
| GET `/api/v1/teacher/analytics/report/excel` | teacher | platform | + role/permission | yes | |
| GET `/api/v1/teacher/follow-up` | teacher | platform | + `role:...,permission:students.manage` | yes | |
| GET `/api/v1/teacher/device-policy` | teacher | platform | + role/permission | yes | |
| PUT `/api/v1/teacher/device-policy` | teacher | platform | + role/permission | yes | |
| GET `/api/v1/teacher/leaderboard-policy` | teacher | platform | + role/permission | yes | |
| PUT `/api/v1/teacher/leaderboard-policy` | teacher | platform | + role/permission | yes | |
| GET `/api/v1/teacher/students` | teacher | platform | + role/permission | yes | |
| POST `/api/v1/teacher/students/reset-all-devices` | teacher | platform | + role/permission | yes | |
| POST `/api/v1/teacher/students/reset-all-limits` | teacher | platform | + role/permission | yes | added — bulk clear of individual device grants (no devices/sessions touched) |
| GET `/api/v1/teacher/students/{student}` | teacher | platform | + role/permission | yes | |
| GET `/api/v1/teacher/students/{student}/activity` | teacher | platform | + role/permission | yes | |
| GET `/api/v1/teacher/students/{student}/video-progress` | teacher | platform | + role/permission | yes | added §11 — per-student video watch report |
| POST `/api/v1/teacher/students/{student}/device-limit` | teacher | platform | + role/permission | yes | |
| DELETE `/api/v1/teacher/students/{student}/device-limit` | teacher | platform | + role/permission | yes | |
| POST `/api/v1/teacher/students/{student}/reset-devices` | teacher | platform | + role/permission | yes | |
| GET `/api/v1/teacher/file-policy` | teacher | platform | + role/permission | yes | |
| PUT `/api/v1/teacher/file-policy` | teacher | platform | + role/permission | yes | |
| GET `/api/v1/teacher/availabilities` | teacher | platform | + `feature:appointments_enabled` + `role:...,permission:settings.manage` | yes | |
| POST `/api/v1/teacher/availabilities` | teacher | platform | + feature + role/permission | yes | |
| PUT `/api/v1/teacher/availabilities/{availability}` | teacher | platform | + feature + role/permission | yes | |
| DELETE `/api/v1/teacher/availabilities/{availability}` | teacher | platform | + feature + role/permission | yes | |
| GET `/api/v1/teacher/services` | teacher | platform | + `feature:paid_services_enabled` + `role:...,permission:settings.manage` | yes | |
| POST `/api/v1/teacher/services` | teacher | platform | + feature + role/permission | yes | |
| GET `/api/v1/teacher/services/{service}` | teacher | platform | + feature + role/permission | yes | |
| PUT `/api/v1/teacher/services/{service}` | teacher | platform | + feature + role/permission | yes | |
| DELETE `/api/v1/teacher/services/{service}` | teacher | platform | + feature + role/permission | yes | |
| GET `/api/v1/teacher/bookings` | teacher | platform | + `role:...,permission:settings.manage` | yes | |
| GET `/api/v1/teacher/bookings/{booking}` | teacher | platform | + role/permission | yes | |
| PUT `/api/v1/teacher/bookings/{booking}/status` | teacher | platform | + role/permission | yes | |
| GET `/api/v1/teacher/staff` | teacher | platform | tenant group | yes | Multi-teacher; controller-enforced. |
| POST `/api/v1/teacher/staff/invitations` | teacher | platform | tenant group | yes | |
| DELETE `/api/v1/teacher/staff/invitations/{invitation}` | teacher | platform | tenant group | yes | |
| PUT `/api/v1/teacher/staff/members/{member}` | teacher | platform | tenant group | yes | |
| DELETE `/api/v1/teacher/staff/members/{member}` | teacher | platform | tenant group | yes | |
| GET `/api/v1/teacher/media` | teacher | platform | + `role:...,permission:media.manage` | yes | |
| GET `/api/v1/teacher/media/usage` | teacher | platform | + role/permission | yes | |
| POST `/api/v1/teacher/media/upload-ticket` | teacher | platform | + role/permission | yes | |
| POST `/api/v1/teacher/media/resumable-ticket` | teacher | platform | + role/permission + `throttle:media-ticket` | yes | |
| POST `/api/v1/teacher/media/resumable-complete` | teacher | platform | + role/permission + `throttle:media-ticket` | yes | |
| DELETE `/api/v1/teacher/media/assets/{asset}` | teacher | platform | + role/permission + `throttle:media-ticket` | yes | |
| GET `/api/v1/teacher/video-security` | teacher | platform | + `role:...,permission:media.manage` | yes | |
| PUT `/api/v1/teacher/video-security` | teacher | platform | + role/permission | yes | |
| GET `/api/v1/media/assets/{asset}/playback` | shared | platform | tenant group | yes | |

#### Super-admin — inside the tenant group (api.php:431, 512, 533, 541, 553)

All of the following carry `auth:sanctum` + (`tenant`,`device`,`subscription`,`profile`) + `admin`.
On a teacher host they MUST return 403 (with a valid non-admin session) or 401 (guest).

| Method + URI | Surface | Host | Auth | Tenant | Notes |
| --- | --- | --- | --- | --- | --- |
| GET `/api/v1/admin/subscriptions` | super-admin | platform | + `admin` | yes(may be null for super admin) | |
| POST `/api/v1/admin/subscriptions/{subscription}/status` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/subscriptions/{subscription}/extend` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/subscriptions/{subscription}/extend-trial` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/subscriptions/{subscription}/plan` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/teachers/{teacher}/subscription/history` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/teachers/{teacher}/subscription/preview` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/teachers/{teacher}/subscription/manual` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/manual-subscription-operations/{operation}/proof` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/teachers/{teacher}/domain` | super-admin | platform | + `admin` | — | |
| PUT `/api/v1/admin/teachers/{teacher}/domain` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/teachers/{teacher}/domain/verify` | super-admin | platform | + `admin` | — | |
| DELETE `/api/v1/admin/teachers/{teacher}/domain` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/students` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/students` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/students/{student}` | super-admin | platform | + `admin` | — | |
| PUT `/api/v1/admin/students/{student}/status` | super-admin | platform | + `admin` | — | |
| DELETE `/api/v1/admin/students/{student}` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/tenants/options` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/teachers` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/teachers` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/teachers/{teacher}` | super-admin | platform | + `admin` | — | |
| PUT `/api/v1/admin/teachers/{teacher}/status` | super-admin | platform | + `admin` | — | |
| DELETE `/api/v1/admin/teachers/{teacher}` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/payments` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/payments/{payment}` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/payments/{payment}/proof` | super-admin | platform | + `admin` | — | |
| PUT `/api/v1/admin/payments/{payment}/status` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/payment-gateway-test` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/coupons` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/coupons` | super-admin | platform | + `admin` | — | |
| PUT `/api/v1/admin/coupons/{coupon}` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/coupons/{coupon}/toggle` | super-admin | platform | + `admin` | — | |
| DELETE `/api/v1/admin/coupons/{coupon}` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/tickets` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/tickets/{ticket}` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/tickets` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/tickets/{ticket}/messages` | super-admin | platform | + `admin` | — | |
| PUT `/api/v1/admin/tickets/{ticket}/status` | super-admin | platform | + `admin` | — | |
| PUT `/api/v1/admin/tickets/{ticket}/assign` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/teacher-notifications` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/teacher-notifications` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/teacher-notifications/teachers` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/teacher-notifications/recipients` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/teacher-notifications/contact` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/teacher-notifications/{id}` | super-admin | platform | + `admin` | — | |
| PUT `/api/v1/admin/teacher-notifications/{id}` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/teacher-notifications/{id}/send` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/teacher-notifications/{id}/retract` | super-admin | platform | + `admin` | — | |
| DELETE `/api/v1/admin/teacher-notifications/{id}` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/error-logs` | super-admin | platform | + `admin` | — | |
| DELETE `/api/v1/admin/error-logs/{log}` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/error-logs/clear` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/quran-teacher-requests` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/quran-teacher-requests/stats` | super-admin | platform | + `admin` | — | |
| PUT `/api/v1/admin/quran-teacher-requests/{request}` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/feature-catalog` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/plans` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/plans` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/plans/{plan}` | super-admin | platform | + `admin` | — | |
| PUT `/api/v1/admin/plans/{plan}` | super-admin | platform | + `admin` | — | |
| DELETE `/api/v1/admin/plans/{plan}` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/plans/{plan}/features` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/plans/{plan}/features` | super-admin | platform | + `admin` | — | |
| PUT `/api/v1/admin/plans/{plan}/features/{feature}` | super-admin | platform | + `admin` | — | |
| DELETE `/api/v1/admin/plans/{plan}/features/{feature}` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/plans/{plan}/freemius-sync` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/plans/{plan}/publish-price` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/plans/{plan}/apply-pending-price` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/bi` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/bi/recalculate` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/bi/cost-rules` | super-admin | platform | + `admin` | — | |
| PUT `/api/v1/admin/bi/cost-rules/{id}` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/bi/plans/{plan}` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/media` | super-admin | platform | + `admin` | — | |
| PUT `/api/v1/admin/media/providers/{provider}` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/media/providers/{provider}/test` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/media/providers/{provider}/sync` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/media/profitability` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/media/pricing` | super-admin | platform | + `admin` | — | |
| POST `/api/v1/admin/media/pricing` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/media/webhook-events` | super-admin | platform | + `admin` | — | |
| GET `/api/v1/admin/live/providers` | super-admin | platform | + `admin` | — | |

### 3g. Routes outside the auth/tenant groups (api.php after line 867)

| Method + URI | Surface | Host | Auth | Tenant | Notes |
| --- | --- | --- | --- | --- | --- |
| GET `/api/v1/admin/backups` | super-admin | platform | `auth:sanctum` + `admin` | no | No tenant context required. |
| POST `/api/v1/admin/backups` | super-admin | platform | + `admin` + `throttle:backups` | no | |
| GET `/api/v1/admin/backups/settings` | super-admin | platform | + `admin` | no | |
| PUT `/api/v1/admin/backups/settings` | super-admin | platform | + `admin` | no | |
| GET `/api/v1/admin/backups/{backup}` | super-admin | platform | + `admin` | no | |
| POST `/api/v1/admin/backups/{backup}/verify` | super-admin | platform | + `admin` | no | |
| GET `/api/v1/admin/backups/{backup}/download/{artifact?}` | super-admin | platform | + `admin` | no | artifact pattern `[\w.\-]+`. |
| DELETE `/api/v1/admin/backups/{backup}` | super-admin | platform | + `admin` | no | |
| GET `/api/v1/admin/backups/{backupId}/signed-download` | super-admin | platform | `signed` (HMAC) | no | No bearer token; signature is the auth. |
| POST `/api/v1/monitoring/ingest` | public | both | none | no | (listed in 3d) |
| GET `/api/v1/admin/monitoring/summary` | super-admin | platform | `auth:sanctum` + `admin` + `throttle:monitoring` | no | |
| GET `/api/v1/admin/monitoring/snapshots` | super-admin | platform | + `admin` + throttle | no | |
| GET `/api/v1/admin/monitoring/alerts` | super-admin | platform | + `admin` + throttle | no | |
| POST `/api/v1/admin/monitoring/alerts/{alert}/resolve` | super-admin | platform | + `admin` + throttle | no | |
| GET `/api/v1/admin/monitoring/slow-requests` | super-admin | platform | + `admin` + throttle | no | |
| GET `/api/v1/admin/monitoring/frontend-errors` | super-admin | platform | + `admin` + throttle | no | |
| GET `/api/v1/teacher/plan-recommender/questions` | public | both | none | optional | Plan recommender. |
| POST `/api/v1/teacher/plan-recommender/recommend` | public | both | none | optional | `throttle:recommend`. |

### 3h. Public site & tenant discovery (surface: public) — api.php:898–947

| Method + URI | Surface | Host | Auth | Tenant | Notes |
| --- | --- | --- | --- | --- | --- |
| GET `/api/v1/public/plans` | public | both | none | no | |
| GET `/api/v1/public/sitemap` | public | both | none | no | `throttle:60,1`. |
| GET `/api/v1/public/cards/{code}` | public | both | none | yes | Activation card info. |
| POST `/api/v1/public/contact` | public | both | none | no | `throttle:contact`. |
| GET `/api/v1/public/certificates/{number}` | public | both | none | no | Certificate verification. |
| GET `/api/v1/public/courses/{course}/sections` | public | both | none | yes | |
| GET `/api/v1/public/courses/{course}/lessons/{lesson}` | public | both | none | yes | |
| GET `/api/v1/public/{slug}` | public | both | none | yes | Tenant homepage data. |
| GET `/api/v1/public/{slug}/courses` | public | both | none | yes | |
| GET `/api/v1/public/{slug}/courses/{course}` | public | both | none | yes | |
| GET `/api/v1/public/{slug}/bundles` | public | both | none | yes | |
| GET `/api/v1/public/{slug}/posts` | public | both | none | yes | |
| GET `/api/v1/public/{slug}/posts/{post}` | public | both | none | yes | |
| GET `/api/v1/public/{slug}/book/teachers` | public | both | none | yes | Booking. |
| GET `/api/v1/public/{slug}/book/services` | public | both | none | yes | |
| GET `/api/v1/public/{slug}/book/slots` | public | both | none | yes | |
| POST `/api/v1/public/{slug}/book` | student | both | `auth:sanctum` + `throttle:bookings` | yes | |
| GET `/api/v1/my-bookings` | student | both | `auth:sanctum` | yes | |
| POST `/api/v1/my-bookings/{booking}/cancel` | student | both | `auth:sanctum` | yes | |
| POST `/api/v1/teacher/staff/invitations/{token}/accept` | teacher | both | `auth:sanctum` | yes | Invitation acceptance. |
| GET `/api/v1/payment-gateways` | public | both | none | no | Checkout UI gateway status. |
| GET `/api/v1/platform-settings` | public | both | none | no | |
| GET `/api/v1/public/domain/{domain}` | public | both | none | yes | Host→tenant resolution (proxy calls this). |
| GET `/api/v1/public/tls/ask` | public | both | none | no | Caddy on-demand TLS gate. |
| POST `/api/v1/quran-teacher-requests` | public | both | none | no | Free-access request. |
| PUT `/api/v1/platform-settings` | super-admin | platform | `auth:sanctum` + `admin` | no | |
| POST `/api/v1/upload` | shared | both | `auth:sanctum` + `throttle:uploads` | no | |
| POST `/api/v1/videos/upload-ticket` | teacher | both | `auth:sanctum` + `throttle:video-upload` | no | |
| POST `/api/v1/assignment-upload` | shared | both | `auth:sanctum` + `throttle:uploads` | no | |
| GET `/api/v1/stream/{video}` | shared | both | `signed` (HMAC token) + `throttle:stream` | token-scoped | No header; signed URL is the auth. |
| GET `/api/v1/lessons/{lesson}/file` | student | both | enrollment check (no session middleware) | token/enrollment | Download policy enforced in controller. |

---

## 4. Teacher-custom-domain accessibility matrix

A teacher host must serve only the public tenant site and the signed-in student/teacher app.
The proxy does **not** path-block admin routes, so these must be refused by middleware.
Expected results for a request on `{slug}.fahimni.me` or a verified custom domain.

### 4a. Super-admin / platform-admin routes — MUST be inaccessible (expect 403 with a valid non-admin session, 401 as guest, or 404 if host-gated)

| Route group | Method + path |
| --- | --- |
| Frontend super-admin pages | `/admin/login` (except login), `/admin/dashboard`, `/admin/teachers`, `/admin/students`, `/admin/subscriptions`, `/admin/plans`, `/admin/payments`, `/admin/invoices`, `/admin/transactions`, `/admin/coupons`, `/admin/cards`, `/admin/tickets`, `/admin/quran-requests`, `/admin/teacher-notifications`, `/admin/live`, `/admin/media`, `/admin/bi`, `/admin/monitoring`, `/admin/monitoring/frontend`, `/admin/logs`, `/admin/settings`, `/admin/view-as`, `/admin/support/enter` |
| View-as API | `GET /api/v1/admin/view-as`, `POST /api/v1/admin/view-as/{user}`, `DELETE /api/v1/admin/view-as` |
| Support-session API | `POST /api/v1/admin/support/{teacher}/begin`, `POST /api/v1/admin/support/activate`, `GET /api/v1/admin/support`, `DELETE /api/v1/admin/support` |
| Backups API | `/api/v1/admin/backups*` (incl. `signed-download`, `settings`, `{backup}/verify`, `{backup}/download`) |
| Monitoring API | `/api/v1/admin/monitoring/*` |
| Full `/api/v1/admin/*` surface | all routes in section 3f and 3g (teachers, students, subscriptions, plans, payments, coupons, tickets, teacher-notifications, error-logs, quran-teacher-requests, feature-catalog, bi, media, live) |
| Platform settings (write) | `PUT /api/v1/platform-settings` |
| Analytics cache | `POST /api/v1/analytics/clear-cache` |

### 4b. Tenant-staff (teacher) management routes — platform-preferred, should not resolve tenant from a teacher host's public paths

These are authenticated by `auth:sanctum` + `tenant` (from `X-Tenant-Id`, not the Host). They
are not Host-blocked; a signed-in staff member on a teacher host would still pass. Recommend
host-gating these to the platform host as defense-in-depth:

- `/api/v1/teacher/*` (all teacher-scoped endpoints in section 3f)
- `/api/v1/settings`, `/api/v1/teacher/domain*`, `/api/v1/teacher/site-design*`
- `/api/v1/courses*`, `/api/v1/lessons*`, `/api/v1/sections*`, `/api/v1/exams*`, `/api/v1/questions*`
- `/api/v1/subscriptions*` (staff billing actions), `/api/v1/videos/upload*`
- Frontend `/teacher/*` (including the canonical `/teacher/courses*` builder), `/students`, `/analytics`, `/blog`. From the frontend, `/courses*` on a teacher host is the tenant's PUBLIC catalogue, not the builder.

### 4c. Allowed on a teacher host (must NOT 403/404)

| Route group | Notes |
| --- | --- |
| Public tenant site pages `/t/*` (rewritten to root) | home, courses, blog, book, register. |
| `/api/v1/public/*` | plans, sitemap, cards, contact, certificates, courses, `{slug}/*`, domain lookup, tls ask. |
| `/api/v1/auth/*` (login, register, google, forgot/reset, 2fa, captcha) | login flows. |
| `/api/session/*` BFF | session/BFF cookies. |
| Student app routes `/my-learning`, `/my-notes`, `/exams`, `/exam/[id]`, `/results`, `/certificates`, `/leaderboard`, `/messages`, `/notifications`, `/announcements`, `/my-bookings`, `/profile`, `/community*`, `/course/[id]/learn`, `/live/[id]`, `/card/[code]`, `/checkout*`, `/book/success`, `/verify/[number]` | signed-in app + public utility. |
| Student/booking APIs | `/api/v1/student/*`, `/api/v1/mobile/*`, `/api/v1/my-bookings*`, `/api/v1/public/{slug}/book*`, `/api/v1/live-sessions/*`. |
| Webhooks/callbacks | freemius/paytabs/paypal, media webhook, monitoring ingest. |

---

## 5. Counts

| Category | Count |
| --- | --- |
| Frontend pages (`page.tsx`) | 104 |
| Frontend API/BFF handlers (`route.ts`) | 12 |
| Backend `api.php` HTTP route definitions | 492 (incl. 53 `Route::group`/`prefix`/`middleware` wrappers) |
| Backend `web.php` HTTP route definitions | 2 |
| Super-admin-only backend endpoints (`admin` middleware or controller check) | ~105 |
| Public (no-auth) backend endpoints | ~50 |

> Counts are `grep`-derived from the literal `Route::get|post|put|patch|delete` lines.
