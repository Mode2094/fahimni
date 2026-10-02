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
