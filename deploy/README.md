# Fahimni — staging & production deployment

This directory contains the reproducible, **additive** launch assets for the
Fahimni VPS. Nothing here replaces the existing LooksLoop Caddy site, changes
DNS, or deploys on merge. The first real staging/production run still requires
server access and DNS approval.

## 1. Topology

| | Production | Staging |
|---|---|---|
| Frontend | `https://fahimni.me` | `https://staging.fahimni.me` |
| API | `https://api.fahimni.me` | `https://api-staging.fahimni.me` |
| Compose project | `fahimni` | `fahimni-staging` |
| nginx bind | `127.0.0.1:8080` | `127.0.0.1:8081` |
| PostgreSQL | `fahimni` DB / `fahimni` role | `fahimni_staging` DB / role |
| Volumes | `fahimni_*` | `fahimni-staging_*` |
| App network | `fahimni_net` | `fahimni-staging_net` |
| DB network | `fahimni_db` (internal) | `fahimni-staging_db` (internal) |
| Proxy alias | `fahimni-prod` | `fahimni-staging` |
| Public TLS | shared LooksLoop Caddy container | same + basic auth + `noindex` |

Both stacks use the same compose file with different `COMPOSE_PROJECT_NAME`
and env files. PostgreSQL and Redis publish **no** host ports and live on an
internal network (`internal: true`, no egress). The public TLS terminator is
the **existing LooksLoop Caddy container** (see §7 and `deploy/caddy-docker.md`);
nginx joins a dedicated shared reverse-proxy network (`fahimni-proxy`) that
carries nginx only — never a database or Redis of either project.

## 2. What is automated here — and what is not

Automated in this repository:

- `docker compose` isolation (project/image/network/volume/database names).
- Env templates for both stacks, with local secret generation.
- Additive Caddy vhosts merged into the LooksLoop Caddy **container** over its
  admin API, with read-only validation and a reconcile timer (the old
  `import`/`systemctl` flow is retired).
- Deploy, rollback, health, public/local smoke, off-site backup and restore
  drill scripts.
- CI still runs PHPUnit and the Next.js typecheck/build for every PR.

**Not executed yet** (requires access this repository cannot provide):

- Real staging/production deployment, DNS changes, TLS issuance.
- Caddy integration **apply** on the host (validated locally against a simulated
  LooksLoop Caddyfile; the live container was only inspected, never modified).
- Freemius Sandbox checkout/webhook tests.
- A real restore drill against an off-site copy.

## 3. Server layout

```text
/opt/fahimni/
  production/
    releases/<sha>/          # git checkout + .env symlink
    current -> releases/<sha>
    .deployed-tag            # image tag currently serving
    .previous-release        # rollback target
    .previous-tag
  staging/
    releases/<sha>/
    current -> ...
/etc/fahimni/
  production.env             # 600, owner fahimni
  staging.env                # 600, owner fahimni
/var/backups/fahimni/        # optional local copies
```

The deploy user (`fahimni`) needs Docker access. Docker group membership is
root-equivalent; use a dedicated VPS user and do not add extra people.

## 4. First-time host setup (Ubuntu 24.04)

> The candidate host is `187.127.87.101` (`srv1985094.hstgr.cloud`). It already
> serves LooksLoop through Caddy. Do not reinstall or replace Caddy, and do not
> touch the existing LooksLoop site blocks.

### 4.1 SSH (key-only)

The repository owner already has a dedicated deploy key generated locally:
`~/.ssh/fahimni_deploy` (private key never leaves the workstation). Add the
public key **only after confirming server access**, then test in a second
session before disabling passwords.

```bash
# As root in the Hostinger console (or an existing trusted session):
adduser --disabled-password --gecos "" fahimni
usermod -aG docker fahimni
install -d -m 700 -o fahimni -g fahimni /home/fahimni/.ssh
printf '%s\n' 'ssh-ed25519 AAAA... fahimni-deploy-2026' \
  >> /home/fahimni/.ssh/authorized_keys
chown fahimni:fahimni /home/fahimni/.ssh/authorized_keys
chmod 600 /home/fahimni/.ssh/authorized_keys

cat >/etc/ssh/sshd_config.d/99-fahimni.conf <<'EOF'
PubkeyAuthentication yes
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
EOF
sshd -t && systemctl reload ssh
```

Keep the current session open, open a **new** session with
`ssh -i ~/.ssh/fahimni_deploy fahimni@187.127.87.101`, and only then close the
old one. Do not disable root login until the new key works.

### 4.2 Firewall

```bash
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable
ufw status
```

Do not open PostgreSQL/Redis ports. Do not add a rule that shadows LooksLoop.

### 4.3 Docker + directories

```bash
curl -fsSL https://get.docker.com | sh
systemctl enable --now docker
install -d -m 755 -o fahimni -g fahimni /opt/fahimni/production/releases
install -d -m 755 -o fahimni -g fahimni /opt/fahimni/staging/releases
install -d -m 750 -o root -g fahimni /etc/fahimni
install -d -m 750 -o fahimni -g fahimni /var/lib/fahimni
install -d -m 755 -o fahimni -g fahimni /var/log/fahimni
```

## 5. DNS (requires owner approval; do not change silently)

Exact records, verification commands and rollback live in
`deploy/dns-cutover.md`. Summary:

| Record | Type | Value | Notes |
|---|---|---|---|
| `fahimni.me` | A | `187.127.87.101` | currently Hostinger parking `2.57.91.91` |
| `www` | CNAME | `fahimni.me` | redirect handled by Caddy |
| `api` | A | `187.127.87.101` | |
| `staging` | A | `187.127.87.101` | |
| `api-staging` | A | `187.127.87.101` | |

- Use TTL 300 during cutover, raise afterwards.
- **Do not alter MX or TXT records.**
- Verify from outside: `dig +short fahimni.me api.fahimni.me staging.fahimni.me api-staging.fahimni.me`.
- Keep Cloudflare/proxy disabled until a DNS-01 setup is chosen; HTTP-01
  issuance needs port 80 reachable directly.
- Only after DNS resolves should Caddy be reloaded, otherwise ACME retries and
  the staging/production TLS check fails (Caddy keeps running, but TLS is not
  ready).

## 6. Environment files

`deploy/env/*.env.example` are secret-free templates. `init-env.sh` merges the
shared file with the stack file and generates APP_KEY, DB/Redis passwords,
media signing keys, the first-admin password and the backup key **locally**;
values are never printed and never committed.

```bash
cd /opt/fahimni/production/releases/<sha>
sudo ./deploy/scripts/init-env.sh --env production --out /etc/fahimni/production.env
sudo ./deploy/scripts/init-env.sh --env staging    --out /etc/fahimni/staging.env

sudoedit /etc/fahimni/production.env   # fill remaining CHANGE_ME values
sudoedit /etc/fahimni/staging.env

sudo chown fahimni:fahimni /etc/fahimni/production.env /etc/fahimni/staging.env
sudo chmod 600 /etc/fahimni/production.env /etc/fahimni/staging.env
ln -sfn /etc/fahimni/production.env .env
```

Fill at minimum: `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
`HORIZON_MAIL_TO`, `SUPER_ADMIN_EMAIL`, `BACKUP_RCLONE_REMOTE`, and (only when
Sandbox is ready) the Freemius IDs/keys. `IMAGE_TAG=CHANGE_ME_RELEASE_SHA` is
intentional: `deploy.sh` overrides it with the git commit.

Keep `NEXT_PUBLIC_API_URL=/api/v1` in both stacks. The browser must talk to the
same origin so the HttpOnly BFF session cookie is sent; `api.fahimni.me` is for
webhooks/external clients, not for the browser bundle. `APP_URL` still points at
the API domain for server-generated links and Freemius callbacks.

Never reuse the same DB/Redis/admin/Freemius secrets across production and
staging. Never put staging Freemius **live** keys on staging; use Sandbox keys.

## 7. Caddy (additive, inside the LooksLoop Docker container)

The host's public TLS terminator is the **LooksLoop Caddy container**
(`caddy:2-alpine`), not a systemd unit. Its Caddyfile is a read-only bind mount
from an immutable release path and has no `import`, so the Fahimni vhosts are
merged into the running config through Caddy's admin API instead of editing the
host tree. Full rationale, topology, continuity and rollback live in
**`deploy/caddy-docker.md`**.

`deploy/caddy/fahimni.caddy` defines only the four Fahimni hosts, with upstreams
pointing at the nginx alias on the shared `fahimni-proxy` network
(`fahimni-prod:80` / `fahimni-staging:80`).

```bash
cd /opt/fahimni/production/current

# bcrypt hash for the staging password (never paste it into shell history)
docker exec looksloop-production-caddy-1 caddy hash-password

# READ-ONLY validation of the merged (LooksLoop + Fahimni) config
FAHIMNI_STAGING_BASIC_HASH='$2a$...' deploy/scripts/caddy-docker-integrate.sh --check

# apply: connect the shared network, install the merged config, reload gracefully
FAHIMNI_STAGING_BASIC_HASH='$2a$...' deploy/scripts/caddy-docker-integrate.sh --apply --yes

deploy/scripts/caddy-docker-integrate.sh --status
```

It never replaces the Caddyfile, restarts the container, or edits LooksLoop
blocks. `caddy-activate.sh` is now a thin forwarder to the integrator.

**Continuity across LooksLoop updates.** A LooksLoop redeploy recreates its Caddy
container, which drops the runtime network attachment and the merged config. The
`fahimni-caddy-reconcile` systemd timer (every ~60s, `--reconcile --yes`)
re-reads the new release's Caddyfile, re-attaches the network and re-applies —
preserving LooksLoop's own changes. Install per `deploy/caddy-docker.md` §5.

**Rollback** (fast, in place):

```bash
deploy/scripts/caddy-docker-integrate.sh --rollback --yes --disconnect
```

After reload, verify LooksLoop still responds and that Fahimni gets certificates.
The staging password is required by both staging hosts and is not stored in
git. The one exception is the Freemius webhook path
(`/api/v1/payments/freemius-webhook`): Freemius cannot send basic-auth
credentials, so that path bypasses Caddy basic auth and is authenticated by the
HMAC signature in `FreemiusWebhookController`. Every other staging/API path
remains closed.

## 8. Deploy

Create a release directory for the exact commit:

```bash
sudo -u fahimni git clone https://github.com/Mode2094/fahimni.git \
  /opt/fahimni/production/releases/<sha>
cd /opt/fahimni/production/releases/<sha>
sudo -u fahimni git checkout <sha>
sudo -u fahimni ln -sfn /etc/fahimni/production.env .env
```

Then:

```bash
cd /opt/fahimni/production/releases/<sha>
sudo -u fahimni ./deploy/scripts/deploy.sh --env production
```

The script validates compose, builds images tagged with the commit, runs the
one-shot `migrate` service, starts everything, waits for container health and
the Laravel readiness endpoint, runs local smoke checks, and only then switches
`current`. If a previous tag exists, a failed health/smoke check attempts to
restore it automatically.

Useful options:

```bash
deploy.sh --env staging --wait 240
deploy.sh --env production --skip-build   # image already built for this tag
```

First deployment has no automatic rollback target; if it fails, inspect with
`docker compose ps` and `docker compose logs --tail=100 app` (logs may contain
sensitive request data — do not paste them publicly).

## 9. Rollback

```bash
cd /opt/fahimni/production/current
sudo -u fahimni ./deploy/scripts/rollback.sh --env production
```

It restores the release/image recorded in `.previous-release` / `.previous-tag`,
waits for health and smoke checks, then switches `current`. A second rollback
moves forward again.

**Code rollback is not a database rollback.** If the failed release ran a
destructive/incompatible migration, fix forward or restore a verified backup
(see §11). Do not run `migrate:rollback` blindly.

## 10. Health and smoke checks

```bash
./deploy/scripts/healthcheck.sh --env production
./deploy/scripts/smoke-test.sh --env production          # public HTTPS
./deploy/scripts/smoke-test.sh --env production --local  # loopback only
```

Production public checks: frontend, `/up`, API liveness/readiness, www redirect,
HSTS/nosniff, API host not serving the UI, TLS chain and ≥14-day validity.
Staging public checks: 401 without credentials, `noindex` with credentials,
authenticated health when `SMOKE_USER`/`SMOKE_PASSWORD` are exported.

Suggested external monitors:

- `https://api.fahimni.me/up` (liveness, no dependencies)
- `https://api.fahimni.me/api/health/ready` (DB/Redis ready)
- `https://fahimni.me/` (frontend)
- TLS expiry and backup age alerts from the application monitoring page.

## 11. Off-site backup and restore drill

The application’s Backup Center writes to the `backups_data` volume. That is
**not** off-site. The host-side script adds an independent encrypted copy:

```bash
# One-time host tools + rclone remote (least-privilege bucket keys).
sudo apt-get install -y rclone age
sudo -u fahimni rclone config

# Encrypt with age (recommended): keep the private key OFF this host.
age-keygen -o /root/fahimni-age.key       # copy the private key offline
# put the public age1... value in BACKUP_AGE_RECIPIENT

# Or use a root-only passphrase file referenced by
# BACKUP_ENCRYPTION_PASSPHRASE_FILE.

sudo -u fahimni ./deploy/scripts/backup-offsite.sh --env production
```

The script dumps PostgreSQL in custom format, archives uploaded media, encrypts
before upload, copies with `rclone`, verifies local/remote hashes, and prunes
archives older than `--keep-days` (default 14). It refuses to upload plaintext.

Weekly restore drill (never touches production). Run it with `sudo` so it can
read the offline age identity or root-only passphrase file:

```bash
sudo BACKUP_AGE_IDENTITY=/root/fahimni-age.key \
  ./deploy/scripts/restore-drill.sh --env production --from-remote --yes
```

It downloads the newest DB archive, decrypts it, starts a disposable
PostgreSQL container with `--network none`, restores with `pg_restore`, and
prints row counts (`migrations`, `users`, `tenants`) plus database size. The
production stack is not used.

Cron examples:

```cron
# fahimni crontab — daily encrypted upload (public age recipient only)
15 3 * * * cd /opt/fahimni/production/current && ./deploy/scripts/backup-offsite.sh --env production >> /var/log/fahimni/backup-production.log 2>&1

# root crontab — weekly restore drill (needs the offline private key)
30 3 * * 0 cd /opt/fahimni/production/current && BACKUP_AGE_IDENTITY=/root/fahimni-age.key ./deploy/scripts/restore-drill.sh --env production --from-remote --yes >> /var/log/fahimni/restore-drill.log 2>&1
```

Store the age/passphrase recovery material somewhere other than the VPS.
Without it, encrypted backups are unrecoverable.

## 12. Freemius Sandbox (not completed yet)

Use **staging only** for Sandbox. Set these in `/etc/fahimni/staging.env`:

```dotenv
FREEMIUS_ENABLED=true
FREEMIUS_SANDBOX=true
FREEMIUS_PRODUCT_ID=<sandbox product id>
FREEMIUS_API_TOKEN=<sandbox token>
FREEMIUS_PRODUCT_SECRET_KEY=<sandbox secret>
FREEMIUS_PUBLIC_KEY=<sandbox public key>
```

Freemius dashboard values:

- Webhook URL:
  `https://api-staging.fahimni.me/api/v1/payments/freemius-webhook`
- Signed redirect URL:
  `https://staging.fahimni.me/checkout/success`
- Enable the `license.*` events required by `FREEMIUS_SETUP.md`.

After editing staging env, recreate the containers that read it (the frontend’s
`NEXT_PUBLIC_*` values are build-time and do not change at runtime):

```bash
cd /opt/fahimni/staging/current
docker compose up -d --force-recreate app horizon scheduler
```

Then run the checklist in `FREEMIUS_SETUP.md` (Sandbox checkout, signed
redirect, webhook recorded as `processed`, renewal, refund, multi-tenant
licenses). **Mocks and local tests are not proof of live integration.** Do not
switch production to live keys until every Sandbox item is checked and
documented.

## 13. Merge / rebuild order

This infrastructure branch is stacked on `feat/freemius-only-billing`, not on
`main`, and does not include the security dependency PR. Before building the
first real images:

1. Merge `security/deps-2026-11` (PR #2) into `feat/freemius-only-billing`.
2. Merge `feat/freemius-only-billing` (PR #1) according to the normal review.
3. Rebase/merge this infra branch on the result (or rebuild from a commit that
   contains both) so the image contains the patched Laravel/Next/axios versions.
4. Only then run `deploy.sh` on staging.

Merging any PR does **not** deploy: there is no deploy workflow on push.

## 14. Security guardrails

- `BIND_ADDR=127.0.0.1`: only Caddy listens publicly.
- PostgreSQL/Redis have no published ports, separate volumes per stack, and live
  on an `internal: true` network (no egress).
- The shared `fahimni-proxy` network carries nginx only — no database or Redis of
  either project is attached to it.
- Staging is basic-auth protected and sends `X-Robots-Tag: noindex`.
- No fixed `container_name`; projects cannot collide.
- Env files are 600 and never baked into images.
- Backups are encrypted before leaving the host.
- `docker compose config` can print secrets — use `--quiet` in logs and never
  paste its output.
- **Never run `docker compose down -v`** on a real stack; it deletes the
  database and backup volumes.
- Do not modify the LooksLoop Caddy site, DNS MX/TXT records, or production
  secrets without explicit owner approval.

## 15. Known limitations of this repository work

Executed during preparation (2026-11-14):

- `docker compose config` validated for both stacks; project/image/volume
  isolation, the internal `*_db` network, the shared **external** `fahimni-proxy`
  network (nginx-only, unique per-stack alias) and the env-driven memory limits
  confirmed.
- The additive Fahimni Caddy fragment validates against a simulated LooksLoop
  Caddyfile (global options block + `:443` on-demand catch-all + `:80` redirect)
  under real Caddy 2: `deploy/tests/caddy-merge-validate.sh` passes and its
  duplicate-host guard is exercised.
- `caddy-docker-integrate.sh` was run end-to-end against a local fake LooksLoop
  Caddy container: `--apply` merged + reloaded while preserving LooksLoop sites;
  `--reconcile` was a no-op when unchanged; a simulated LooksLoop container
  recreation was re-attached and re-merged (the new LooksLoop host preserved);
  `--rollback` removed the Fahimni vhosts with LooksLoop still serving.
- `deploy/scripts/*.sh` and `deploy/tests/*.sh`: `bash -n` and `shellcheck -x`
  clean.
- Production images `fahimni-{app,frontend,scheduler,horizon}` built
  successfully (no build errors).
- A disposable local staging stack was started from the generated env:
  migrations + seeders exited 0, all seven containers reached `healthy`,
  `/up`, `/api/health/live`, `/api/health/ready` and `/` returned 200;
  `healthcheck.sh` and `smoke-test.sh --local` passed.
- `backup-offsite.sh --no-upload` produced a PostgreSQL custom dump and media
  archive; `restore-drill.sh` restored it into a `--network none` disposable
  PostgreSQL and reported `migrations=115 users=1`. The local stack was then
  removed with `docker compose down -v` (disposable only).
- `shellcheck -x` (Docker image) and `bash -n` pass for every deployment
  script. Caddy v2.11.9 validated the rendered vhosts and an additive
  Caddyfile containing a simulated LooksLoop site; `caddy-activate.sh --check`
  passed.
- The images above were built before the security dependency PR was merged, so
  they still contain the old Next/axios/Laravel versions. Rebuild from a commit
  that includes both PRs (see §13).

Still blocked (requires access or approval this repository cannot provide):

- No `fahimni` deploy user/key is authorized on the server yet; only the
  LooksLoop deploy user was available, and it was used **read-only** (inspect,
  `docker ps`/`stats`, `cat`) — real deploy, Caddy integration apply and TLS
  issuance are pending owner approval.
- `fahimni.me` still resolves to Hostinger parking; the A records in §5 are a
  proposal, not an executed change.
- The local drill did not use Caddy, public DNS or TLS; Caddy was syntax-checked
  only, and staging basic auth was tested at the configuration level.
- Freemius Sandbox credentials/account were not available; the 25-item checklist
  in `FREEMIUS_SETUP.md` remains unchecked.
- No real off-site upload was performed (no rclone remote/age key); only a local
  plaintext drill with `BACKUP_ALLOW_UNENCRYPTED=1` on disposable data.

## 16. KVM 2 resource budget

Measured on the host (read-only, 2026-11-14): **2 vCPU**, **7940 MB RAM**
(~1231 MB used, ~6709 MB available), **swap 0**, **96 GB disk** (18 GB used),
with LooksLoop already running:

| LooksLoop container | RSS (approx.) |
|---|---|
| api | ~166 MB |
| store | ~176 MB |
| control | ~126 MB |
| web | ~88 MB |
| caddy | ~62 MB |
| mirror | ~60 MB |
| postgres | ~60 MB |
| redis | ~17 MB |
| scheduler / queue | ~tens of MB |
| **total** | **~0.8 GB** |

Fahimni caps are hard memory limits set per service (env-driven, see
`deploy/env/common.env.example`):

| Service | Cap | Notes |
|---|---|---|
| postgres | `MEM_POSTGRES` = 768M | |
| redis | `MEM_REDIS` = 256M | |
| app (php-fpm) | `MEM_APP` = 384M | |
| frontend (Next) | `MEM_FRONTEND` = 384M | |
| scheduler | `MEM_SCHEDULER` = 256M | |
| horizon | `MEM_HORIZON` = 512M | keep worker counts consistent |
| nginx | `MEM_NGINX` = 64M | |
| migrate (one-shot) | `MEM_MIGRATE` = 512M | only during a deploy |

**Steady state ≈ 2.6 GiB per stack; peak (with `migrate`) ≈ 3.1 GiB.** Staging +
production ≈ 5.1 GiB, which fits next to LooksLoop (~6 GiB total of 7940 MB) with
~1.9 GiB for the OS and page cache. Constraints and rules:

- **Run one stack at a time while building images.** A Laravel `composer install`
  plus `next build` can spike; keep `COMPOSE_PARALLEL_LIMIT=1` and never build
  both stacks at once.
- **Add ~2 GB swap before running both stacks together** (the host currently has
  none) — a server change requiring owner approval.
- 2 vCPU is fine for staging + a light production; Horizon worker counts and any
  k6 load test must stay within these caps.
- Increase a cap only with evidence.

Measurement commands (run during/after an approved deploy):

```bash
docker stats --no-stream --format 'table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}'
docker system df
free -m
df -h /
```
