# Web Session / BFF Authentication

The web app no longer stores any bearer token. Authentication is an
**HttpOnly session cookie** managed by a thin Next.js BFF; the mobile app
keeps using Sanctum bearer tokens unchanged.

## Flow

```
Browser ──POST /api/session/login──▶ Next.js BFF ──server-to-server──▶ Laravel
        ◀── Set-Cookie: lms-session (HttpOnly, Secure, SameSite=Lax)
            Set-Cookie: XSRF-TOKEN (readable — CSRF token only)

Browser ──GET/POST /api/v1/* (same origin, cookies)──▶ nginx ──▶ Laravel
```

- `src/lib/bff.ts` — server-only cookie jar + CSRF handshake.
- `src/app/api/session/{login,register,2fa,logout,me,refresh,csrf}/route.ts`
  — the BFF endpoints. They talk to Laravel over the internal network
  (`NEXT_REWRITE_API_TARGET` / `INTERNAL_API_URL`) and forward only cookies.
- `src/lib/auth-client.ts` — browser helper: same-origin credentials, CSRF
  header (`X-XSRF-TOKEN`), device id, 401 cleanup.
- `src/lib/device.ts` — one `window.fetch` patch adds the same headers to the
  ~80 existing raw `fetch` call sites (no per-file patches).
- `src/store/index.ts` — auth state is `user` + a non-credential UI hint
  (`localStorage.lms_authed = '1'`); there is no token state.

## What is (and is not) in localStorage

| Key | Purpose | Credential? |
|---|---|---|
| `lms_authed` | UI hint that a session cookie may exist | No |
| `user` | cached profile for instant hydration | No |
| `tenant_id` | selected tenant id (server re-validates on every call) | No |
| `lms_device_id` | device identity (sent as `X-Device-Id`) | No |

Legacy keys (`token`, `user_role`, `lms_remembered_password*`) are purged on
boot by `clearLegacyAuthStorage()`.

## CSRF

- Laravel's stateful pipeline (`EnsureFrontendRequestsAreStateful`) runs
  `ValidateCsrfToken` for browser requests from `SANCTUM_STATEFUL_DOMAINS`.
- Mutations require `X-XSRF-TOKEN`; missing/invalid → **419**.
- A cross-site `Origin` is not stateful → the session cookie is never even
  loaded → **401**.

## Required production configuration

1. `SANCTUM_STATEFUL_DOMAINS` must include every real frontend host
   (e.g. `app.example.com`). Add the domain before enabling the UI.
2. `NEXT_PUBLIC_API_URL` must stay same-origin: `/api/v1`. An absolute
   cross-origin API URL would drop the session cookie.
3. nginx must route `location /api/session/` to the frontend container
   **before** `/api/` (already configured in `docker/nginx/default.conf`).
4. HTTPS is required in production: the session cookie is `Secure`.
5. Session store: `SESSION_DRIVER=redis` (production compose already sets it).

## Two-factor & Google

- Password + 2FA: the pending challenge lives in the Laravel session; the
  browser sends only the 6-digit code to `/api/session/2fa`.
- Google OAuth: the callback is a **web** route
  (`routes/web.php`, same URI) that signs the user into the session and
  redirects with a non-sensitive `?step=done|2fa` marker. No token ever
  appears in a URL.
- Mobile Google (`/api/v1/mobile/auth/google`) is untouched and still returns
  a bearer token.

## Custom teacher domains

A teacher can connect their own domain (`teacher.com`) or use the free
`{slug}.fahimni.me` subdomain. Their students must be able to register and sign
in **on that same host**, so the cookie-session flow has to work there too.

### Why the static list is not enough — and what replaces it

`SANCTUM_STATEFUL_DOMAINS` is built once from env at deploy time and can only
hold the platform's own hosts. A teacher's domain is unknown then, and adding a
wildcard (`*.fahimni.me`, `*`) or pre-listing every possible domain is unsafe:
it would make Sanctum treat a spoofed `Origin` as first-party.

```
Browser (teacher.com) ──/api/session/*──▶ Next.js BFF ──▶ Laravel
                                      forwards Origin: https://teacher.com
```

Two server-side gates replace the env list, and **both verify the domain from
the database** — never from the request:

1. `resolveRequestOrigin()` (`frontend/src/lib/request-origin.ts`) forwards the
   BFF's real `Origin` only when the backend confirms the host. It asks
   `GET /api/v1/public/domain/{host}`; a `200` means a verified custom domain
   (or a live `{slug}.{platform}` subdomain), anything else falls back to the
   canonical platform origin. Host-header spoofing protection is unchanged.
2. `TrustTenantDomainsStatefully` (`app/Http/Middleware/…`) runs immediately
   **before** `EnsureFrontendRequestsAreStateful` and appends the request's
   Referer/Origin host to `config('sanctum.stateful')` — but only when
   `TenantDomainResolver::belongsToActiveTenant()` confirms it (a VERIFIED
   `TenantSetting.custom_domain` of an ACTIVE tenant, or a live teacher
   subdomain). Everything else keeps the default (stateless → 401).

The exact same predicate backs the Caddy on-demand-TLS `ask` gate
(`GET /api/v1/public/tls/ask`), so the edge, the site lookup and the session
trust can never drift apart.

### No session sharing between teacher domains

`SESSION_DOMAIN` stays empty, so the session cookie is **host-only**: a cookie
set on `teacher-a.com` is never sent to `teacher-b.com` or to `fahimni.me`.
Tenant membership is still re-validated server-side on every call (the
`X-Tenant-Id` header is a *hint*, not an authorization), so a session from one
teacher's domain can never reach another tenant.

### Google OAuth on a custom domain — deliberately unavailable

The Google callback lives on the platform domain (`fahimni.me`). A teacher's
custom domain is a different site, so the callback cannot set a session cookie
there: the student would land on the platform with no session. There is **no
new redirect URI per teacher**, and the flow is disabled on non-platform hosts
instead of silently breaking:

- `GoogleLoginButton` checks `isPlatformBrowserHost()` and renders **disabled**
  with an explanatory message on a custom domain / subdomain.
- Email + password remains fully supported on every host.

A safe central-callback handoff is designed but **BLOCKED** on provider
credentials and a real domain test (see `docs/CUSTOM_DOMAIN_OAUTH_HANDOFF.md`).
