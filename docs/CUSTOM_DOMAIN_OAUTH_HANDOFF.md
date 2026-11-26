# Google OAuth on a teacher's own domain — handoff design

**Status: implemented (PR `feat/google-oauth-custom-domains`). The real-provider
round-trip still needs an authorized test Google account before final
acceptance.**

Google sign-in now works on every live teacher host — a custom domain
(`teacher.com`) or a `{slug}.fahimni.me` subdomain — using one central OAuth
callback on the platform and a single-use, browser-bound handoff ticket. Email +
password keeps working everywhere; the platform's own hosts keep the original
single-host flow unchanged.

## Why not add each teacher domain as a redirect URI?

- Google requires every redirect URI to be pre-registered; there is no
  programmatic per-tenant registration, and a wildcard redirect URI is not
  allowed.
- Even if the callback returned to `teacher.com` directly, the platform would
  have to trust an unverified `Host` to decide where to send a freshly minted
  session — an open-redirect / session-fixation footgun.

## Flow (central callback + single-use ticket)

```
1. teacher.com  GET /api/session/google/redirect?role=student&tenant_slug=…
   └─ BFF rebuilds the verified origin; on a non-platform host it also mints an
      HttpOnly, host-only `oauth_handoff` binding secret and forwards its
      HMAC-SHA256 hash as `binding` to:
      GET /api/v1/auth/google/redirect?role=student&binding=…
   └─ backend signs `state` = {role, tenant_slug, redirect_to,
      handoff_host=teacher.com, bh=hash(binding), exp, nonce} (HMAC app.key)
2. Google                       → central callback on the platform web route
3. api.fahimni.me (web)         verifies the signed state, authenticates the
                                Google account, then mints a SINGLE-USE handoff
                                ticket (256-bit id, TTL 90 s) bound to
                                {user_id, tenant_id, role, redirect_to,
                                 needs_profile, host=teacher.com, bh}
4. redirect → https://teacher.com/auth/google/complete#ticket=…
5. teacher.com (BFF)            POST /api/session/google/redeem {ticket}
                                → forwards ticket + the browser's raw binding to
                                  POST /api/v1/auth/google/redeem
                                → backend verifies host == ticket.host AND
                                  hash(binding) == ticket.bh, consumes the ticket
                                  (delete-on-read), starts a session FOR THAT
                                  HOST, returns its own HttpOnly cookie.
```

## Invariants (all covered by tests)

- **No token/credential in any URL** — the ticket in step 4 is an opaque,
  single-use, 90-second id placed in the URL **fragment** (never sent to a server,
  never logged, never leaked via Referer); the page strips it from the address
  bar as soon as it is read. What crosses the wire afterwards is HttpOnly cookies
  only.
- **Bound to host + browser** — redeem is rejected unless the requesting host
  (verified by `TenantDomainResolver`) equals `ticket.host` **and** the raw
  binding matches `ticket.bh`. A ticket minted for one host cannot be redeemed on
  another, and cannot be fixed onto another browser's session (login-CSRF /
  session-fixation defence).
- **Single use** — `GoogleHandoff::consume()` is get-and-forget; a replay finds
  nothing and gets `410`.
- **Signed `state`** with expiry + nonce, checked before anything is minted.
- **No open redirect** — the post-login path is `safeInternalPath()`; the
  destination host is never taken from a query parameter, only from the verified
  request origin.
- **2FA is never bypassed** — a 2FA-enabled account is sent back to the teacher
  host's own login (the platform's session-bound confirm step is unreachable
  there), never silently signed in.
- **Tenant from the host** — on a teacher host the tenant is resolved from the
  verified hostname, so the internal slug never appears in a customer-facing URL.

## Key files

- `app/Http/Controllers/Api/Auth/GoogleAuthController.php` — `redirect`, `callback`, `redeem`
- `app/Support/GoogleHandoff.php` — ticket issue/consume + binding hash
- `app/Services/TenantDomainResolver.php` — `tenantForHost()` host→tenant trust
- `routes/api.php` — `POST auth/google/redeem` (login throttle)
- `frontend/src/app/api/session/google/{redirect,redeem}/route.ts` — BFF bridge
- `frontend/src/app/auth/google/complete/page.tsx` — tenant-host landing
- `frontend/src/components/auth/google-login-button.tsx` — button (goes via BFF)
- `tests/Feature/GoogleCustomDomainHandoffTest.php` — backend contract
- `frontend/src/app/auth/google/complete/page.test.tsx` — landing behaviour

## Remaining blocker before final acceptance

- No authorized test Google account in the environment to run the real
  provider round-trip end to end. The code path is unit/integration-tested with a
  mocked Socialite provider; the live round-trip must be executed by the owner
  (or with a supplied test account) before a PASS verdict.
