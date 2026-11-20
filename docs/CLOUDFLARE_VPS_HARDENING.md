# Cloudflare + VPS Hardening (manual steps)

These steps cannot be automated from the repository — run them once on the
production host / Cloudflare dashboard after the first deploy. The app itself
is already code-hardened; this document closes the network layer.

## 1. Docker stack (origin)

- Secrets live only in `.env` (mode `600`). Never commit it, never pass it as
  `--build-arg`, never bake it into an image. `.dockerignore` now excludes
  `certs/`, `*.pem`, `*.key`, `.env.testing` and `frontend/.env.local`.
- PostgreSQL and Redis intentionally publish **no ports**; keep it that way.
  Only `nginx` exposes 80/443 (`APP_PORT`/`HTTPS_PORT`).
- `DB_PASSWORD` and `REDIS_PASSWORD` are mandatory (`:?` in compose) — the
  stack refuses to start with known defaults.

```bash
chmod 600 .env
docker compose build
docker compose up -d
docker compose ps          # every service healthy
```

## 2. TLS

- Preferred: TLS terminated at Cloudflare (Full **Strict**) and the origin
  served over HTTP on the private network.
- Direct TLS on the origin (optional): drop certs into `./certs` and copy
  `docker/nginx/optional/https.conf.example` to `docker/nginx/optional/https.conf`
  (TLS 1.2/1.3 + HSTS already configured there).
- Never use Cloudflare "Flexible" — it leaves the origin leg in plaintext and
  lets an on-path attacker strip security.

## 3. Firewall: hide the origin

- Allow inbound `80/443` **only from Cloudflare IP ranges**
  (https://www.cloudflare.com/ips/ — sync occasionally) and SSH from the
  admin IP. Deny everything else.

> **This host is shared.** The same Caddy container terminates TLS for **both**
> `fahimni.me` and **LooksLoop**, so restricting `80/443` to Cloudflare IPs only
> is safe **iff every vhost on the host is behind Cloudflare**. If LooksLoop is
> ever reachable without Cloudflare, a blanket deny takes it offline. Never run
> a blanket `ufw deny 22` or a catch-all `ufw deny` here.
>
> Use the committed, dry-run-by-default script instead of typing rules by hand.
> It always allows SSH from `ADMIN_SSH_IP` first, never touches port 22 with a
> deny, is idempotent, and **refuses `--apply` unless LooksLoop-behind-Cloudflare
> is explicitly confirmed**:

```bash
# 1) dry run — prints the exact rules, changes nothing
ADMIN_SSH_IP=<your.ssh.ip> ./deploy/scripts/cloudflare-ufw.sh

# 2) apply (ONLY after LooksLoop is confirmed behind Cloudflare)
sudo ADMIN_SSH_IP=<your.ssh.ip> CONFIRM_LOOKSLOOP_OK=yes \
     ./deploy/scripts/cloudflare-ufw.sh --apply

# rollback at any time, over the same SSH session:
sudo ufw disable
```

> **Docker hosts: ufw alone is not enough.** When 80/443 are published by a
> container (as here: the Caddy edge), Docker inserts its own rules in the
> `FORWARD` chain (`DOCKER-USER` → `DOCKER-FORWARD`) that run **before** ufw's
> forward chains — so a ufw rule cannot restrict a Docker-published port.
> Use the Docker-aware script instead, which locks `:443` to the Cloudflare
> ranges in the `DOCKER-USER` chain (IPv4 + IPv6), leaves `:22` and `:80`
> untouched, and is idempotent:
>
> ```bash
> # dry run / status / apply / rollback
> ./deploy/scripts/cloudflare-origin-firewall.sh
> sudo ./deploy/scripts/cloudflare-origin-firewall.sh apply
> sudo ./deploy/scripts/cloudflare-origin-firewall.sh remove
> ```
>
> Persist it across reboots with `deploy/scripts/fahimni-cf-firewall.service`
> (installs to `/usr/local/sbin/fahimni-cf-firewall`, `systemctl enable`).
> `:80` is intentionally left open so Caddy can still answer Let's Encrypt
> HTTP-01 renewals; it only serves an HTTP→HTTPS redirect and never proxies to
> nginx, so it is not a `CF-Connecting-IP` spoofing path.

- Verify from a **second** SSH session before closing the first. Keep an
  out-of-band console (Hostinger VNC) reachable in case SSH locks out.
- IPv6 is deliberately **not** in the script's allow list — add the
  `cloudflare.com/ips-v6` ranges only after confirming v6 is actually used,
  otherwise v6 clients are black-holed.
- Stronger: **Authenticated Origin Pulls** (mTLS) in Cloudflare + nginx
  `ssl_verify_client on` on the origin, so even a leaked IP cannot be used.
- Optional: Cloudflare Access on `/horizon*` as an extra gate (the app also
  requires `is_super_admin`).

## 4. Cloudflare dashboard

- SSL/TLS: **Full (Strict)**, Always Use HTTPS, Minimum TLS 1.2.
- HSTS: enable, `max-age=31536000`, `includeSubDomains` (add `preload` only
  when all subdomains are HTTPS-ready).
- WAF: enable the Cloudflare Managed Ruleset (OWASP) + Bot Fight Mode.
- Rate limiting rules (edge, in addition to Laravel's):
  - `/api/v1/auth/*` → e.g. 20 req/min per IP (login/register/reset).
  - `/api/v1/payments/*` and `/api/v1/media/webhook/*` → generous but capped.
  - `/api/v1/upload`, `/api/v1/videos/upload*` → low hourly cap per IP.
- Cache rules: **bypass** for `/api/*`, `/horizon*`, `/storage/*`;
  cache `/_next/static/*` aggressively (immutable).
- Turn off "Rocket Loader" and HTML minification if it interferes with the
  Next.js CSP/hydration; keep "Email Address Obfuscation" off for API paths.
- **Do not move `fahimni.me` to Cloudflare nameservers** as part of this
  hardening pass. That is a separate, owner-approved DNS migration, and it
  requires copying the SPF/DKIM/DMARC/Resend records first — see
  `deploy/dns-cutover.md` §3b. Enabling the **proxy** (orange cloud) is also a
  separate step: it changes the certificate challenge and the client-IP path
  (§5), so do it deliberately, not while debugging.

## 5. Real client IP (required for rate limits + audit)

### 5.1 The real hop chain

The earlier draft of this section assumed Cloudflare talks **directly** to nginx.
It does not. The verified production path is:

```text
 internet ──▶ Cloudflare edge ──▶ Caddy container ──▶ nginx ──▶ PHP-FPM
             (CF-Connecting-IP)   (LooksLoop's,      (127.0.0.1:8080
                                   publishes 80/443)   / fahimni-proxy)
```

Consequences that matter:

- nginx's peer (`$remote_addr`) is the **Caddy container's private IP**, never
  Cloudflare's and never the client's.
- Caddy passes the client-facing headers through unchanged and adds its own
  `X-Forwarded-For`/`X-Real-IP` (the address **it** saw — i.e. a Cloudflare edge
  IP). So a plain `real_ip_header X-Forwarded-For` resolves to a **Cloudflare
  edge IP**, and per-IP rate limits collapse to ~15 CDN addresses. `X-Forwarded-For`
  is therefore the **wrong** header on this host.
- `CF-Connecting-IP` survives the Caddy hop intact and always carries the true
  client IP. Use it.

### 5.2 Enable it in nginx (no Caddy / LooksLoop change)

The cleanest fix is **nginx-native** and touches nothing LooksLoop owns (its
Caddyfile is a read-only bind mount and must not be edited — see
`deploy/caddy-docker.md`):

1. Uncomment the `set_real_ip_from ...` block near the top of
   `docker/nginx/default.conf` and set the header directive to
   `CF-Connecting-IP`:
   ```nginx
   set_real_ip_from 192.168.0.0/16;          # the Caddy hop (private docker net)
   # ... keep the Cloudflare IPv4 ranges from cleanup/dns docs ...
   real_ip_header CF-Connecting-IP;
   real_ip_recursive on;
   ```
   Trust the private hop **and** the Cloudflare ranges. The private entry is
   what lets nginx read `CF-Connecting-IP` at all (the peer is Caddy); the CF
   ranges are what make a **direct-to-origin** request fall back to the
   attacker's real IP instead of a spoofed header value.
2. Keep `fastcgi_param HTTP_X_FORWARDED_FOR $remote_addr;` (already present):
   after the rewrite, Laravel receives the true client IP.
3. Reload: `docker compose restart nginx`.
4. Laravel still trusts only private-network proxies (`bootstrap/app.php`), so a
   client-sent `X-Forwarded-For` cannot spoof the app's `$request->ip()` even if
   the app port leaks.

> **Hard prerequisite.** This makes nginx trust `CF-Connecting-IP` from the
> private hop. If the origin IP is reachable **without** Cloudflare, an attacker
> can reach Caddy directly and forge `CF-Connecting-IP`. Pair §5 with §3
> (firewall: only Cloudflare can reach 80/443) or Authenticated Origin Pulls
> (mTLS). Do §3 **before** or at the same time as §5 — never §5 alone on a
> Cloudflare-proxied setup.

### 5.3 Why not fix it in Caddy?

Caddy's `trusted_proxies` / `client_ip_headers` are **global options**, and the
single global options block on this host is owned by LooksLoop's read-only
Caddyfile. The Fahimni fragment (`deploy/caddy/fahimni.caddy`) deliberately
defines no global options block, so it cannot set them without editing
LooksLoop's config — out of scope. The nginx-native fix above gives the same
result and stays inside the Fahimni stack.

### 5.4 Verify

Forge the header from **outside** Cloudflare (i.e. hitting the origin directly)
and confirm the app still logs your real IP, then check a proxied request:

```bash
# Direct-to-origin with a forged header — must NOT be trusted.
curl -sk -H 'CF-Connecting-IP: 203.0.113.9' https://<origin-ip>/api/v1/health/live \
     --resolve fahimni.me:443:<origin-ip>
docker compose logs --tail=5 nginx | grep health   # $remote_addr = your real IP

# Through Cloudflare — $remote_addr must be the real client, not a CF edge IP.
docker compose logs --tail=5 nginx | grep health
```

Also confirm rate limiting keys on the real client (repeated logins from one IP
trip the limit even while the CF edge IP changes).

### 5.5 Rollback

Re-comment the `set_real_ip_from` lines and restore `real_ip_header
X-Forwarded-For;`, then `docker compose restart nginx`. Worst case the origin
reports Cloudflare edge IPs again (rate limits coarse, audit IPs wrong) — no
outage.

## 6. Production `.env` checklist

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<domain>
FRONTEND_URL=https://<domain>
LOG_LEVEL=warning
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
CORS_ALLOWED_ORIGINS=https://<domain>        # no localhost / no *
SANCTUM_STATEFUL_DOMAINS=<domain>            # required for the web session cookie
SUPER_ADMIN_EMAIL=<only this Google account may bootstrap admin via OAuth>
```

> Web auth is an HttpOnly session cookie issued by the Next.js BFF
> (`/api/session/*`). `NEXT_PUBLIC_API_URL` must stay `/api/v1` (same-origin)
> and `SANCTUM_STATEFUL_DOMAINS` must list the public domain — see
> `docs/WEB_SESSION_BFF.md`.

## 6b. Encrypt legacy gateway secrets (one-time, idempotent)

Payment-gateway secrets in `platform_settings` are now encrypted with
`Crypt` (`enc:v1:` marker). Move any legacy plaintext values:

```bash
php artisan settings:encrypt-secrets --dry-run   # counts only, prints no values
php artisan settings:encrypt-secrets             # apply
```

Run it once per environment after deploying this version. Re-running is safe
(already-encrypted values are skipped). Keep `APP_KEY` backed up: losing it
makes encrypted secrets unrecoverable.

## 7. Rotate secrets if exposure is suspected

Rotate via the providers, then update `.env` and redeploy: `APP_KEY`
(requires re-encryption/migration care — coordinate), `DB_PASSWORD`,
`REDIS_PASSWORD`, `MAIL_PASSWORD`, `GOOGLE_CLIENT_SECRET`,
`CLOUDFLARE_API_TOKEN`, `VIDEO_SIGNING_KEY`, `REFUND_WEBHOOK_SECRET`,
`BUNNY_*`, Paddle/Lemon webhook secrets, `BACKUP_ENCRYPTION_KEY`.

## 8. Post-deploy verification

```bash
curl -sI https://<domain> | grep -Ei 'strict-transport|content-security|x-frame|nosniff|referrer|permissions'
curl -s https://<domain>/api/v1/health/ready
nmap -Pn <origin-ip> -p-            # only 22/80/443 (and 80/443 only for CF IPs)
```

> Never rename a route and call it security: every sensitive endpoint is
> authorized server-side regardless of whether its path is known.
