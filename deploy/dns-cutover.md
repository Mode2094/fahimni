# DNS cutover — fahimni.me

Exact, reviewable DNS change for launching Staging (and later Production) on the
Hostinger KVM 2 host `187.127.87.101`. Nothing here is applied by this
repository; the owner applies it in the Hostinger DNS panel.

## 1. Records to create / change

| Type | Name | Value | TTL | Purpose |
|---|---|---|---|---|
| A | `@` (fahimni.me) | `187.127.87.101` | 300 | frontend (Production) |
| CNAME | `www` | `fahimni.me` | 300 | redirect to apex |
| A | `api` | `187.127.87.101` | 300 | Laravel API host (webhooks/external) |
| A | `staging` | `187.127.87.101` | 300 | staging frontend |
| A | `api-staging` | `187.127.87.101` | 300 | staging API / Freemius webhook |

- Current state (verified): `fahimni.me` → Hostinger parking `2.57.91.91`;
  `www` CNAME → apex; `api`, `staging`, `api-staging` do **not** exist.
- Use short TTL (300) during cutover, then raise to 3600 after verification.

## 2. Records that MUST NOT change

- **MX** and any mail-related TXT (SPF `v=spf1`, DKIM `*._domainkey`, DMARC
  `_dmarc`). If mail is delegated to another provider, do not touch those
  records or nameservers.
- Any record belonging to **LooksLoop** (its own A/AAAA/CNAME and TLS-related
  TXT/ACME records). Adding `fahimni.me` A records for a name that is already
  an A/CNAME pair does not affect other hostnames on the same IP, but do not
  edit or delete LooksLoop entries.
- Nameservers: keep the current ones unless the owner explicitly decides to
  move DNS.

## 3. Hostinger panel steps

1. Domains → `fahimni.me` → DNS / Nameservers → DNS zone editor.
2. Change the `A @` record from `2.57.91.91` to `187.127.87.101`.
3. Add `A api`, `A staging`, `A api-staging` → `187.127.87.101`.
4. Ensure `www` is `CNAME www → fahimni.me`.
5. Leave MX/TXT/LooksLoop entries untouched. Save.

## 4. Verification (run before touching Caddy)

```bash
dig +short fahimni.me A
dig +short www.fahimni.me
dig +short api.fahimni.me
dig +short staging.fahimni.me
dig +short api-staging.fahimni.me

# Public resolvers (propagation)
dig +short @1.1.1.1 staging.fahimni.me
dig +short @8.8.8.8 staging.fahimni.me

# Mail must still resolve as before
dig +short fahimni.me MX
dig +short fahimni.me TXT
```

Expected: the four Fahimni names return `187.127.87.101`; MX/TXT unchanged.

## 5. Ordering (important)

1. DNS applied and verified (this file).
2. Port 80 reachable for ACME; apply the Fahimni vhosts **only** after DNS
   resolves (`deploy/scripts/caddy-docker-integrate.sh --apply --yes`), otherwise
   certificates cannot be issued and staging stays without TLS.
3. Deploy Staging (`deploy/scripts/deploy.sh --env staging`).
4. Production only after Staging + Freemius Sandbox pass, with new approval.

Do not enable a Cloudflare proxy before the TLS challenge method is chosen;
HTTP-01 needs port 80 reachable directly.

## 6. Rollback

- Restore `A @` to `2.57.91.91` (parking) and delete `api`, `staging`,
  `api-staging` to return to the current state.
- Then remove the Fahimni vhosts from the LooksLoop Caddy **container** (in place,
  no restart, LooksLoop keeps serving):
  `deploy/scripts/caddy-docker-integrate.sh --rollback --yes --disconnect`
  (details in `deploy/caddy-docker.md` §7–8).
- Do not edit, restart or recreate the LooksLoop container, and do not touch its
  DNS records.
