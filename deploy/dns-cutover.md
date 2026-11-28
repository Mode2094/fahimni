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
  records or nameservers. See **§3b** for the Resend records and how to verify
  them before and after the cutover.
- Any **other** hostname's records (other A/AAAA/CNAME and their TLS/ACME TXT
  records) that are not part of the Fahimni plan. Adding `fahimni.me` records
  for a name that is already an A/CNAME pair does not affect other hostnames on
  the same IP, but do not edit or delete unrelated entries.
- Nameservers: keep the current ones unless the owner explicitly decides to
  move DNS.

## 3. Hostinger panel steps

1. Domains → `fahimni.me` → DNS / Nameservers → DNS zone editor.
2. Change the `A @` record from `2.57.91.91` to `187.127.87.101`.
3. Add `A api`, `A staging`, `A api-staging` → `187.127.87.101`.
4. Ensure `www` is `CNAME www → fahimni.me`.
5. Leave MX/TXT and any unrelated records untouched. Save.

## 3b. Email DNS (Resend) — record now, verify, then never touch

Outbound mail goes through **Resend** (`MAIL_MAILER=resend`) for
`support@fahimni.me` and transactional mail. Resend authenticates the sending
domain with three DNS records that **must survive** the cutover and any
Cloudflare migration. They are **TXT/CNAME only** — they do not move when the
apex `A` record changes, but they are easy to lose during a "clean up the zone"
pass, and losing them silently pushes mail to spam or makes it fail SPF/DKIM.

Record the current values **before** touching anything:

```bash
dig +short fahimni.me TXT                     # SPF: "v=spf1 include:amazonses.com ~all" (Resend) or similar
dig +short resend._domainkey.fahimni.me TXT   # DKIM (Resend publishes a CNAME/TXT pair)
dig +short _dmarc.fahimni.me TXT              # DMARC policy
dig +short fahimni.me MX                      # inbound (if any)
dig +short send.fahimni.me CNAME              # Resend return-path / MX subdomain records
```

Guidance:

- **SPF (TXT on the apex)**: keep a single `v=spf1` record; if both Resend and
  another sender exist, they must be `include:`-ed in **one** record, never two.
- **DKIM**: Resend's `resend._domainkey` / `send` subdomain records are added
  from the Resend dashboard (Domains → fahimni.me → DNS records). Do not edit
  their values; if Resend rotates keys, re-copy from the dashboard.
- **DMARC (`_dmarc`)**: keep the existing policy. If it is missing, adding
  `v=DMARC1; p=none; rua=mailto:support@fahimni.me` is safe (monitor-only) and
  never blocks mail.
- **MX**: only relevant if `fahimni.me` receives mail directly. Leave untouched.
- **Nameservers**: moving `fahimni.me` to Cloudflare's nameservers is a **later,
  separately-approved** step. If it ever happens, every record above must be
  **copied into Cloudflare DNS first** (Cloudflare's scanner usually imports
  them, but verify each one by hand) — a missing SPF/DKIM after the move breaks
  login e-mails, password resets and receipts.

After any change, verify deliverability on the **Staging** stack before
Production, and check Resend's dashboard for a green "Verified" domain with no
SPF/DKIM warnings.

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
2. Port 80 reachable for ACME; recreate the edge **only** after DNS resolves
   (`docker compose -p fahimni-edge -f deploy/caddy/docker-compose.caddy.yml
   --env-file /etc/fahimni/caddy.env up -d`), otherwise certificates cannot be
   issued and the host stays without TLS.
3. Deploy Staging (`deploy/scripts/deploy.sh --env staging`).
4. Production only after Staging + Freemius Sandbox pass, with new approval.

Do not enable a Cloudflare proxy before the TLS challenge method is chosen;
HTTP-01 needs port 80 reachable directly.

## 6. Rollback

- Restore `A @` to `2.57.91.91` (parking) and delete `api`, `staging`,
  `api-staging` to return to the current state.
- Then recreate the Caddy edge from the previous release's Caddyfile
  (`docker compose -p fahimni-edge -f deploy/caddy/docker-compose.caddy.yml
  --env-file /etc/fahimni/caddy.env up -d` after checking out the old
  `deploy/caddy/fahimni-standalone.caddy`). The edge is stateless; see
  `deploy/README.md` §7.
- Fahimni owns the host: there is no other project's Caddy or DNS to preserve.
