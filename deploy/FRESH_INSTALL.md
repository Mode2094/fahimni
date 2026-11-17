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

- **Two separate Docker Compose projects, on purpose.** The app stack is project
  `fahimni`; the standalone Caddy edge is project `fahimni-edge`. They share only
  the external `fahimni-proxy` network. Both bring containers up with
  `up --remove-orphans`, which only ever affects its **own** project — so the edge
  can never stop/remove the app (and vice versa). Never set
  `COMPOSE_PROJECT_NAME=fahimni` in the edge env: that would merge the two and let
  one side remove the other's containers.
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
| 6 | **Admin** | Optional: `--admin-email` (default `admin@fahimni.me`) and `--admin-password` (default: strong random). If the first admin keeps a placeholder address, change it later with `fahimni:set-super-admin-email` (§6) — never by reseeding. |
| 7 | **SMTP** (optional) | Transactional mail (verification, invitations, password reset) needs real `MAIL_*` values; add them to `/etc/fahimni/production.env` after the first run. The site works without it, but the "forgot password" page then honestly says no email could be delivered instead of promising one. Prove delivery with `fahimni:mail-test` (§6). |

> This does **not** touch any other project's DNS records or servers. Re-pointing
> `fahimni.me` to the new VPS is the intended cutover; leave every unrelated
> record (mail/SPF/DKIM/DMARC, other hosts) untouched.

---

## 2. The one command (Hostinger Terminal)

The installer is **pinned to one commit** so a later push cannot change what the
VPS runs. Run **once** as root on the new VPS (replace the email):

```bash
curl -fsSL https://raw.githubusercontent.com/Mode2094/fahimni/b1f2dee3e240705e828d7cfe1288a5f9428ccd1b/deploy/scripts/fresh-install.sh | sudo -E bash -s -- --release-sha b1f2dee3e240705e828d7cfe1288a5f9428ccd1b --acme-email admin@fahimni.me
```

That is the whole install: it bootstraps Docker, hardens the firewall, clones
**exactly** commit `b1f2dee3e240705e828d7cfe1288a5f9428ccd1b`, generates every secret, builds and starts the stack,
runs migrations + seeders, creates the admin, starts the standalone Caddy edge
with automatic HTTPS, and then **verifies the live site** (DNS, TLS, branding,
migrations, pages).

> Running as `root` already? Drop `sudo -E`:
> `curl -fsSL <same-url> | bash -s -- --release-sha b1f2dee3e240705e828d7cfe1288a5f9428ccd1b --acme-email admin@fahimni.me`

> `--release-sha` pins the deployed release to that commit. Omit it only if you
> deliberately want the tip of `--branch` (default `fix/launch-blockers-rc`).

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
- **keeps the configured admin email** unless you explicitly pass
  `--admin-email` (as well as the ACME email already on disk);
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
- **Project isolation**: the Caddy edge runs in its own compose project
  (`fahimni-edge`), separate from the app (`fahimni`).
- Loopback health: `/up`, `/api/health`, `/api/health/live`, `/api/health/ready`,
  `/` all `200`.
- Database: migrations `Ran`, a super-admin row exists.
- **DNS**: `fahimni.me`, `api.fahimni.me` (and `www`) resolve before TLS is trusted.
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

## 6. Updating an existing install (safe — one command)

The installer is idempotent, so an update is the **same** pinned command with the
new commit. It reuses the release-layout, the existing
`/etc/fahimni/production.env` (secrets are **never** rotated once they exist), the
database and **every** Docker volume:

```bash
curl -fsSL https://raw.githubusercontent.com/Mode2094/fahimni/b1f2dee3e240705e828d7cfe1288a5f9428ccd1b/deploy/scripts/fresh-install.sh \
  | sudo -E bash -s -- \
      --release-sha b1f2dee3e240705e828d7cfe1288a5f9428ccd1b \
      --skip-host-bootstrap \
      --acme-email admin@fahimni.me
```

`--release-sha` is the approved release; replace it (and the URL's SHA) when a
newer commit is approved. `--skip-host-bootstrap` is safe on a host that already
has Docker — drop it if you are unsure.

What it does, in order:

1. clones the pinned commit into `/opt/fahimni/production/releases/<sha>` — a
   **new** directory, so the previous release is left in place for rollback;
2. reuses the existing env file (only missing values are generated);
3. builds images tagged with the commit;
4. runs the one-shot `migrate` service (additive migrations only) and
   `plans:sync`;
5. `docker compose up -d --remove-orphans` (this stack only);
6. waits for `migrate` to exit `0` and for loopback health;
7. only then switches `/opt/fahimni/production/current` to the new release.

**Nothing destructive happens:** no `migrate:fresh`, no `migrate:rollback`, no
`docker compose down -v`, no volume removal, no secret rotation. If step 4–6
fails, the script exits non-zero **before** switching `current`, so the old
release stays current — investigate with `docker compose logs` and re-run.

### Verify right after every update (the launch gate)

```bash
sudo bash /opt/fahimni/production/current/deploy/scripts/fresh-install.sh --verify-only
```

This re-runs the whole suite from §5 (containers, loopback health, migrations,
DNS, HTTPS, branding, login surfaces, exposure) and exits non-zero on any
failure. A green run — not the update command's exit code alone — is the gate for
announcing the release.

### Prove real email delivery and fix the admin address

```bash
cd /opt/fahimni/production/current

# (a) send a REAL message and confirm it arrives in an external inbox:
docker compose exec -T app php artisan fahimni:mail-test you@your-inbox.example

# (b) if the first super-admin still uses a placeholder address, change ONLY the
#     email — the password, roles, 2FA and all data are preserved:
docker compose exec -T app php artisan fahimni:set-super-admin-email \
  you@your-domain.example --from=<current-placeholder-address> \
  --env-file=/etc/fahimni/production.env --force
```

- `fahimni:mail-test` prints `NOT delivered` and exits **non-zero** when the
  configured mailer is `log`/`array`, a placeholder address, or a
  localhost/loopback host — it never claims a delivery that did not happen. The
  same configuration check drives the honest "forgot password" page: it promises
  an email only when the mailer can actually deliver, and never reveals whether
  an address is registered.
- `fahimni:set-super-admin-email` refuses an address already in use and asks for
  confirmation unless `--force` is passed. `--env-file` keeps
  `SUPER_ADMIN_EMAIL` (read by the Google sign-in admin check) in sync.

---

## 7. Troubleshooting

```bash
cd /opt/fahimni/production/current
docker compose ps                                   # app stack (project fahimni)
docker compose -p fahimni-edge -f deploy/caddy/docker-compose.caddy.yml ps   # edge
docker compose logs --tail=100 app frontend nginx
docker compose logs --tail=100 migrate          # schema + seed output
docker compose -f deploy/caddy/docker-compose.caddy.yml -p fahimni-edge logs --tail=100 caddy
```

| Symptom | Likely cause |
|---------|--------------|
| Caddy can't get a certificate | DNS not resolving to this VPS yet, or port 80 blocked |
| The edge removed the app containers | the edge was started in project `fahimni` instead of `fahimni-edge` (never set `COMPOSE_PROJECT_NAME=fahimni` in `caddy.env`) |
| `migrate` exited non-zero | invalid `SUPER_ADMIN_PASSWORD`, or SMTP/DB values malformed in the env file |
| `502` from the edge | nginx not up yet, or the proxy network/alias mismatch (`PROXY_ALIAS`) |
| Login page loads but API fails | check `docker compose logs app` and `/etc/fahimni/production.env` |

---

## 8. Files

| Path | Purpose |
|------|---------|
| `deploy/scripts/fresh-install.sh` | the installer + updater + verifier (this document) |
| `app/Console/Commands/MailTestCommand.php` | `fahimni:mail-test` — honest real-delivery check |
| `app/Console/Commands/SetSuperAdminEmailCommand.php` | `fahimni:set-super-admin-email` — safe admin email change |
| `deploy/caddy/fahimni-standalone.caddy` | standalone Caddyfile (automatic HTTPS) |
| `deploy/caddy/docker-compose.caddy.yml` | standalone Caddy container (project `fahimni-edge`) |
| `deploy/caddy/caddy.env.example` | template for `/etc/fahimni/caddy.env` |
| `deploy/tests/fresh-install-test.sh` | CI test (static guards + hermetic idempotent run) |
| `deploy/tests/caddy-project-isolation-test.sh` | CI test (real Docker: app vs edge never remove each other) |
