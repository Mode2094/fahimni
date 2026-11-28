# Cloudflare for SaaS — teacher custom domains (permanent architecture)

This is the **permanent** way a teacher's own domain (`maryammath.com`,
`academy-jo.com`, …) is served by Fahimni. It replaces the old
"point your A record straight at the server" model, which cannot work on this
host because the origin only accepts traffic from Cloudflare.

## Why this design

* The origin VPS (`187.127.87.101`) runs a **strict Cloudflare-only firewall**
  (`deploy/scripts/cloudflare-origin-firewall.sh` → systemd `fahimni-cf-firewall`):
  on `:443` it RETURNs only Cloudflare's published ranges and DROPs everything
  else. Port `:80` stays open only for ACME/redirect.
* A teacher domain that resolved **directly** to the origin would therefore be
  unreachable over HTTPS, and relaxing the firewall would expose the origin to
  direct-to-IP attacks.
* So every teacher domain becomes a **Cloudflare custom hostname** on the
  platform's own zone (`fahimni.me`). Cloudflare terminates public TLS at its
  edge and forwards to the zone's **fallback origin** over the Cloudflare
  network — which the firewall already trusts.
* The teacher needs **no Cloudflare account**. The origin IP is never exposed.
  Certificates are issued **and renewed automatically** by Cloudflare.

## Topology

```
visitor ──TLS──▶ Cloudflare edge (cert for the teacher domain)
                       │  (custom hostname on the fahimni.me zone)
                       ▼
             fallback origin: proxy-fallback.fahimni.me  (proxied A → 187.127.87.101)
                       │  Cloudflare IPs only; SNI = the custom hostname
                       ▼
             origin Caddy :443  tls { on_demand }  (Let's Encrypt cert per verified domain)
                       │  SSL mode "Full (strict)" — origin cert IS validated
                       ▼
             fahimni-prod nginx → Laravel → tenant lookup by Host
```

### Why the origin still terminates a real certificate (Full (strict))

Cloudflare's [connection details](https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/reference/connection-details/)
state that for a request sent to the **fallback host**, the SNI Cloudflare sends
to the origin equals the client's `Host` header — i.e. the **custom hostname**.
Under **Full (strict)** Cloudflare therefore validates the certificate the origin
presents *against that custom hostname*: it must be unexpired, publicly trusted
(or Cloudflare Origin CA), and carry a matching CN/SAN.

The origin's `:443` catch-all already does exactly this: Caddy's **on-demand TLS**
obtains a Let's Encrypt certificate for the custom hostname, but only after the
`ask` gate (`/api/v1/public/tls/ask`) confirms the domain is a **verified** tenant
domain. So Full (strict) is preserved — the zone is **never** downgraded to
"Full", and the origin certificate is genuinely validated on every request.

Teacher DNS: a single **CNAME / ALIAS** to the platform's CNAME target
(`customers.fahimni.me`), added for **`www`** first — that is the host that
**always** works.

### Apex (`@`) limitation — read this before promising an apex

Cloudflare for SaaS can attach a custom hostname to a domain **apex** only when
the apex resolves to a **real `CNAME`** into the SaaS zone. A registrar that
**flattens** the apex (Hostinger turns a `CNAME`/`ALIAS` at `@` into plain `A`
records) defeats this: Cloudflare then reports

> `custom hostname does not CNAME to this zone.`
> `The hostname is using Cloudflare and cannot be activated with an TXT or HTTP
> validation token.`

and the apex hostname stays `pending` **forever**, even after the ownership
`TXT` matches. The only Cloudflare-native fix is the paid
[**Apex Proxying**](https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/start/advanced-settings/apex-proxying/)
add-on (Cloudflare-assigned Static IP prefixes / BYOIP, account-team gated) —
**not enabled** on this account and deliberately not purchased.

Consequences baked into the platform:

* **`www` is authoritative.** A custom domain is marked servable as soon as
  **either** its apex **or** its `www` sibling is hostname + certificate
  `active` (`syncCustomHostname` / `TenantDomainResolver`). A pending apex never
  blocks `www`, and the `ask` gate blesses a host whose **own** custom hostname
  is already active.
* **No recreate, no token churn.** A missing hostname is adopted **by name**
  (`findHostnameByName`) instead of delete/re-create, so the published
  ownership-verification token is never rotated and no DNS call-to-action
  changes under the teacher.
* Onboarding copy tells the teacher to add the `www` CNAME, then offers the apex
  `CNAME`/`ALIAS` as best-effort, plus the mandatory ownership `TXT`.

**Free, reliable apex → `www` options** (both keep the origin Cloudflare-only —
never point the apex at the origin IP):

1. **Cloudflare-managed zone redirect (recommended, fully free).** Add the
   teacher's domain as a **free Cloudflare zone** on the platform account,
   proxy the apex, and add a single **Redirect Rule**: `apex/*` → `301`
   `https://www.<domain>/$1`. Universal SSL issues a valid apex certificate, so
   `https://<apex>` works (301 → `www`). Requires a one-time nameserver change
   by the domain owner.
2. **Registrar HTTPS redirect.** Some registrars offer a free apex → `www`
   redirect **with** TLS. Verify TLS is actually served; many do not.

> **Hostinger's own “Redirect your domain” is NOT a solution**: it forwards
> over HTTP only and does **not** provision an SSL certificate for the apex, so
> `https://<apex>` still fails. Do not rely on it.

## One-time setup

1. **Enable Cloudflare for SaaS** on the account: Cloudflare dashboard →
   `fahimni.me` → SSL/TLS → **Custom Hostnames** → **Enable**.
   (Cloudflare's Free plan includes 100 custom hostnames; enabling asks for
   payment details on non-Enterprise accounts even though the first 100 are
   free — confirm before enabling.)
2. **Create a scoped API token** (My Profile → API Tokens → Create Token →
   Custom). Permissions:
   * `Zone → SSL and Certificates → Edit`
   * `Zone → DNS → Edit`
   * `Zone → Zone → Read`
   Zone Resources: `Include → Specific zone → fahimni.me`.
   The token is a **secret** — it lives only in `/etc/fahimni/production.env`
   on the server (never in git, never in logs, never in the DB).
3. **Set the environment** (`/etc/fahimni/production.env`):

   ```
   CLOUDFLARE_SAAS_ENABLED=true
   CLOUDFLARE_ACCOUNT_ID=47f4042417b8f04821fd5545fdd3b83f
   CLOUDFLARE_ZONE_ID=<zone id of fahimni.me>
   CLOUDFLARE_ZONE_NAME=fahimni.me
   CLOUDFLARE_SAAS_ORIGIN_IP=187.127.87.101
   CLOUDFLARE_SAAS_FALLBACK_ORIGIN=proxy-fallback.fahimni.me
   CLOUDFLARE_SAAS_CNAME_TARGET=customers.fahimni.me
   CLOUDFLARE_API_TOKEN=<scoped token>
   ```

   The CNAME target and fallback origin can also be overridden live from the
   **admin panel** (`platform_settings`), so the target stays centrally
   changeable without a redeploy.
4. **Create the topology** (idempotent):

   ```
   php artisan fahimni:cloudflare-saas setup
   ```

   This creates the proxied `proxy-fallback` A record, sets it as the fallback
   origin, and creates the proxied `customers` CNAME target.
5. **Keep the zone SSL/TLS mode at “Full (strict)”** (dashboard → SSL/TLS →
   Overview). The origin obtains a real Let's Encrypt certificate per verified
   custom hostname via on-demand TLS, so Cloudflare can validate it. Do **not**
   downgrade the zone to “Full”. The `:443` catch-all and its `ask` gate already
   ship with the standalone edge — no Caddy change is required for this.
6. **Reconcile existing domains**:

   ```
   php artisan fahimni:cloudflare-saas sync
   ```

## Teacher flow

1. Teacher (or admin) connects the domain — the platform instantly registers a
   Cloudflare custom hostname for the apex **and** for `www`, and returns the
   exact records.
2. Teacher adds a `CNAME`/`ALIAS` → `customers.fahimni.me` for **`www`** (the
   host that always works), the apex `CNAME`/`ALIAS` as best-effort, and the
   ownership `TXT` if Cloudflare returned one (mandatory whenever the registrar
   flattens the apex).
3. Teacher presses **Verify** (or the daily job reconciles it). As soon as
   **either** host is hostname + certificate `active`, the domain is verified and
   served — `www` is enough; a flattened apex is expected to stay `pending` and
   does not hold the site back.

## Operations

```
php artisan fahimni:cloudflare-saas status   # config + live hostnames + cert status
php artisan fahimni:cloudflare-saas setup    # create fallback origin + CNAME target
php artisan fahimni:cloudflare-saas sync      # provision/refresh every connected domain
```

`ReverifyTenantDomainsJob` (daily) provisions any missing hostname, refreshes
status, marks a domain verified when Cloudflare reports it active, and
un-verifies a domain Cloudflare can no longer serve (moved away / deleted).

## Security invariants

* The origin firewall is **never** relaxed; custom domains reach the origin
  only from Cloudflare.
* The API token is env-only, never logged, never returned to any client.
* Single-owner rule is unchanged: one domain belongs to exactly one tenant
  (DB unique index + scoped checks), and the platform's own domain is refused.
* Tenant isolation and tenant authentication are untouched.

## Rollback

* Disable by setting `CLOUDFLARE_SAAS_ENABLED=false` (or the admin toggle). The
  platform falls back to the legacy direct-DNS verification path; existing
  Cloudflare hostnames can be listed and removed with `fahimni:cloudflare-saas`.
* No Caddy change is part of this feature: the `:443` catch-all keeps
  `tls { on_demand }` and the `ask` gate (`/api/v1/public/tls/ask`). SSL mode
  stays **Full (strict)** throughout — there is nothing to downgrade or revert
  at the TLS layer.
