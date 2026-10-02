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

```bash
# ufw example (adjust the SSH source)
for net in 173.245.48.0/20 103.21.244.0/22 103.22.200.0/22 103.31.4.0/22 \
           141.101.64.0/18 108.162.192.0/18 190.93.240.0/20 188.114.96.0/20 \
           197.234.240.0/22 198.41.128.0/17 162.158.0.0/15 104.16.0.0/13 \
           104.24.0.0/14 172.64.0.0/13 131.0.72.0/22; do
  ufw allow from "$net" to any port 80,443 proto tcp
done
ufw default deny incoming
ufw allow from <ADMIN_IP> to any port 22 proto tcp
ufw enable
```

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

## 5. Real client IP (required for rate limits + audit)

1. Uncomment the `set_real_ip_from ...` block at the top of
   `docker/nginx/default.conf` and reload nginx (`docker compose restart nginx`).
2. Keep `real_ip_header CF-Connecting-IP;` — nginx then replaces
   `$remote_addr` with the true client IP and **overwrites**
   `X-Forwarded-For` for Laravel (`fastcgi_param HTTP_X_FORWARDED_FOR $remote_addr`).
3. Laravel trusts only private-network proxies (`bootstrap/app.php`), so a
   client-sent `X-Forwarded-For` cannot spoof the IP even if the app port leaks.
4. Verify: send a request with a forged header and confirm logs/rate limits use
   the real IP, not the forged one:

```bash
curl -sk -H 'X-Forwarded-For: 203.0.113.9' https://<domain>/api/v1/health/live
docker compose logs --tail=5 nginx | grep health   # $remote_addr is the real IP
```

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
