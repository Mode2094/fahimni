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
                       │  Cloudflare IPs only
                       ▼
             origin Caddy :443  tls internal  (SSL mode "Full")
                       ▼
             fahimni-prod nginx → Laravel → tenant lookup by Host
```

Teacher DNS: a single **CNAME / ALIAS** to the platform's CNAME target
(`customers.fahimni.me`). The apex is supported wherever the registrar offers
CNAME-flattening — **Hostinger turns a CNAME at `@` into an ALIAS automatically**.

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
5. **Set the zone SSL/TLS mode to “Full”** (dashboard → SSL/TLS → Overview).
   The origin presents a Caddy internal (self-signed) certificate on the
   Cloudflare→origin hop; “Full (strict)” would require a publicly-trusted
   certificate for every custom hostname, which is unnecessary behind the
   Cloudflare-only firewall.
6. **Reconcile existing domains**:

   ```
   php artisan fahimni:cloudflare-saas sync
   ```

## Teacher flow

1. Teacher (or admin) connects the domain — the platform instantly registers a
   Cloudflare custom hostname and returns the exact records.
2. Teacher adds a `CNAME`/`ALIAS` → `customers.fahimni.me` (apex + `www`), and a
   `TXT` if Cloudflare returned an ownership token.
3. Teacher presses **Verify** (or the daily job reconciles it). Once Cloudflare
   reports the hostname **and** certificate `active`, the domain is verified and
   served.

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
* The Caddyfile change (`tls internal` on the `:443` catch-all) can be reverted
  to `tls { on_demand }`; the `ask` gate (`/api/v1/public/tls/ask`) is retained
  for exactly this reason.
