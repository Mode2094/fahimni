# Fresh install — «منصة فهمني» on a clean Ubuntu 24.04 VPS

One production stack, on its own VPS, with its **own** Caddy edge and automatic
HTTPS. No staging, no dependency on any other project, no restore of old data,
and no use of the previous go-live tooling (`fahimni-golive.sh` /
`fahimni-brand-rollout.sh`).

What gets created:

```
Laravel (PHP-FPM)  +  Next.js  +  PostgreSQL 16  +  Redis 7  +  nginx  (Docker)
        └── standalone Caddy edge (own container) ── automatic HTTPS on your domain
```

- **New, empty database.** Only `migrate` + the necessary seeders run; the first
  super-admin is created with a strong, locally generated password.
- **Prices, plans and features are unchanged** — `SubscriptionPlanSeeder` /
  `plans:sync` are the single source of truth and are applied as-is.
- **PostgreSQL and Redis are never exposed**: they publish no host ports and sit
  on an `internal: true` Docker network.
- **Branding is «منصة فهمني»** everywhere (the `platform_settings` default, the
  web/API normalization layer and the shared logo). No legacy brand is served.

---

## 1. External setup you must do BEFORE running

| # | Item | What to do |
|---|------|------------|
| 1 | **VPS** | A clean **Ubuntu 24.04** VPS, reachable over SSH as `root` (or a sudo user). |
| 2 | **DNS** | In the DNS panel create/point **A records** to the VPS public IP: `fahimni.me`, `www.fahimni.me`, `api.fahimni.me` (TTL 300). Automatic HTTPS needs these to resolve **before** the first run. |
| 3 | **Ports** | Allow inbound **22, 80, 443** (the script enables UFW; also check the Hostinger VPS firewall). Port 80 must be reachable directly — do **not** proxy the domain through Cloudflare yet (HTTP‑01 challenge). |
| 4 | **Domain** | Use a domain you control; defaults are `fahimni.me` / `api.fahimni.me` (override with `--domain` / `--api-domain`). |
| 5 | **ACME email** | An email for Let's Encrypt expiry notices (`--acme-email`). Optional but recommended. |
| 6 | **Admin** | Optional: `--admin-email` (default `admin@fahimni.me`) and `--admin-password` (default: strong random). |
| 7 | **SMTP** (optional) | Transactional mail needs real `MAIL_*` values; add them to `/etc/fahimni/production.env` after the first run. The site works without it. |

> This does **not** touch any other project's DNS records or servers. Re-pointing
> `fahimni.me` to the new VPS is the intended cutover; leave every unrelated
> record (mail/SPF/DKIM/DMARC, other hosts) untouched.

---

## 2. The one command (Hostinger Terminal)

Run **once** as root on the new VPS (replace the email):

```bash
curl -fsSL https://raw.githubusercontent.com/Mode2094/fahimni/fix/launch-blockers-rc/deploy/scripts/fresh-install.sh | sudo -E bash -s -- --acme-email admin@fahimni.me
```

That is the whole install: it bootstraps Docker, hardens the firewall, clones the
pinned release, generates every secret, builds and starts the stack, runs
migrations + seeders, creates the admin, starts the standalone Caddy edge with
automatic HTTPS, and then **verifies the live site**.

> Running as `root` already? Drop `sudo -E`:
> `curl -fsSL <same-url> | bash -s -- --acme-email admin@fahimni.me`

At the end it prints a summary and writes the admin credentials to
`/root/fahimni-admin-credentials.txt` (mode 600):

```bash
sudo cat /root/fahimni-admin-credentials.txt
```

Then sign in at `https://fahimni.me/admin/login`.

---

## 3. Useful flags

| Flag | Purpose |
|------|---------|
| `--domain` / `--api-domain` | hostnames (default `fahimni.me` / `api.fahimni.me`) |
| `--acme-email` | Let's Encrypt account email |
| `--branch` / `--release-sha` | pin exactly what is deployed (default: branch tip) |
| `--admin-email` / `--admin-password` / `--admin-name` | first super-admin |
| `--stack-dir` / `--env-file` | paths (default `/opt/fahimni/production`, `/etc/fahimni/production.env`) |
| `--skip-host-bootstrap` | host already has Docker/git/etc |
| `--skip-firewall` / `--skip-swap` | opt out of UFW / swapfile |
| `--no-caddy` | bring your own TLS terminator |
| `--verify-only` | skip install, re-run the verification suite |

---

## 4. Safe to re-run (idempotent)

`fresh-install.sh` converges. Re-running after a partial failure or a reboot:

- reuses the cloned release and the existing env file — **secrets are never
  rotated once they exist** (rotating them would disconnect the running DB/Redis);
- re-runs `docker compose up -d` so the stack reconciles;
- **never** runs `migrate:fresh`, `migrate:rollback`, `down -v` or removes
  volumes;
- re-seeds only when the `users` table is empty (the `migrate` service decides),
  so the admin is created exactly once.

Re-verify without changing anything:

```bash
sudo bash /opt/fahimni/production/current/deploy/scripts/fresh-install.sh --verify-only
```

---

## 5. What the verification checks (no launch is announced on faith)

- Docker daemon reachable; every container `running`; `migrate` exited `0`.
- Loopback health: `/up`, `/api/health`, `/api/health/live`, `/api/health/ready`,
  `/` all `200`.
- Database: migrations `Ran`, a super-admin row exists.
- Public **HTTPS**: `https://<domain>/` `200`, `https://<api>/up` `200`,
  `www` → apex redirect, HSTS present, TLS certificate valid and trusted.
- **Branding**: the API serves `platform_name = «منصة فهمني»`, no legacy brand
  string, and the served `/logo.png` hash matches the release.
- **Login/admin** pages reachable, and the login endpoint rejects bad credentials
  (`401/422`, never `5xx`).
- **Exposure**: PostgreSQL/Redis publish no public port; the env file is mode 600.

If any check fails the script exits non-zero and prints the failures — investigate
before announcing the launch.

---

## 6. Troubleshooting

```bash
cd /opt/fahimni/production/current
docker compose ps
docker compose logs --tail=100 app frontend nginx
docker compose logs --tail=100 migrate          # schema + seed output
docker compose -f deploy/caddy/docker-compose.caddy.yml logs --tail=100 caddy
```

| Symptom | Likely cause |
|---------|--------------|
| Caddy can't get a certificate | DNS not resolving to this VPS yet, or port 80 blocked |
| `migrate` exited non-zero | invalid `SUPER_ADMIN_PASSWORD`, or SMTP/DB values malformed in the env file |
| `502` from the edge | nginx not up yet, or the proxy network/alias mismatch (`PROXY_ALIAS`) |
| Login page loads but API fails | check `docker compose logs app` and `/etc/fahimni/production.env` |

---

## 7. Files

| Path | Purpose |
|------|---------|
| `deploy/scripts/fresh-install.sh` | the installer (this document) |
| `deploy/caddy/fahimni-standalone.caddy` | standalone Caddyfile (automatic HTTPS) |
| `deploy/caddy/docker-compose.caddy.yml` | standalone Caddy container |
| `deploy/caddy/caddy.env.example` | template for `/etc/fahimni/caddy.env` |
| `deploy/tests/fresh-install-test.sh` | CI test (static guards + hermetic idempotent run) |
