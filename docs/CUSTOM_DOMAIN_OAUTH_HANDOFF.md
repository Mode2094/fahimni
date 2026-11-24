# Google OAuth on a custom teacher domain — design (BLOCKED)

**Status: not implemented. Google sign-in is deliberately disabled on custom
domains; email + password works everywhere.**

The current Google flow is a single **central** callback on the platform domain
(`https://api.fahimni.me/api/v1/auth/google/callback`, a web route that signs
the user into the Laravel session and redirects to `FRONTEND_URL`). A teacher's
own domain is a **different site**, so the session cookie the callback sets is
host-only and never reaches `teacher.com`. The student would be signed in on
`fahimni.me` and anonymous on `teacher.com`.

Making it work without adding one redirect URI per teacher requires a secure
**handoff ticket** exchange between the platform callback and the teacher's
domain. This document records the design so it can be implemented and tested
once the blockers clear.

## Why not just add each teacher domain as a redirect URI?

- Google requires every redirect URI to be pre-registered; there is no
  programmatic per-tenant registration, and a wildcard redirect URI is not
  allowed.
- Even if the callback returned to `teacher.com` directly, the platform would
  have to trust an unverified `Host` to decide where to send a freshly minted
  session — an open-redirect / session-fixation footgun.

## Proposed flow (central callback + single-use ticket)

```
1. teacher.com                      GET /api/v1/auth/google/redirect?role=student&tenant_slug=ahmad
   └─ state = signed(tenant_slug, nonce, exp) stored server-side, bound to the
      initiating host (teacher.com)
2. Google                            → central callback on the platform domain
3. api.fahimni.me (web)              verifies state, creates the Laravel session
                                     → mints a SINGLE-USE handoff ticket:
                                       random 256-bit id, TTL ≤ 60 s, bound to
                                       {user_id, tenant_slug, host=teacher.com}
4. redirect → https://teacher.com/auth/google/complete?ticket=…
5. teacher.com (BFF)                 POST /api/v1/auth/google/redeem {ticket}
                                     → backend verifies host == ticket.host,
                                       consumes the ticket (delete-on-read),
                                       starts a session for that host, returns
                                       its own cookie. No token in the URL.
```

### Required invariants

- **No token in any URL** — only an opaque, single-use, short-TTL ticket id.
- **Ticket bound to host + tenant**; redeem rejected when the requesting host
  (verified via `TenantDomainResolver`) does not match `ticket.host`.
- **Single use** — deleted on first read (atomic `DELETE … RETURNING`, Redis
  `GETDEL`, or a DB row with `used_at`).
- **Signed `state`** with expiry + nonce, checked before the callback mints
  anything (CSRF).
- **No open redirect** — the post-login path is a `safeInternalPath()`; the
  host is never taken from a query parameter.
- **No login-ticket fixation** — a ticket minted for one host cannot be
  replayed on another, and cannot be reused after redemption.

## Blockers

- No usable Google OAuth provider credentials in the environment to run the
  round-trip.
- No owned/controllable public domain to prove DNS + certificate issuance and
  the cross-host handoff end-to-end.

Until both clear, the safe, honest behaviour is the one shipped: Google is
available on platform hosts only, and disabled with a clear message on custom
domains.
