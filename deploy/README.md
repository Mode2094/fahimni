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
| Network | `fahimni_net` | `fahimni-staging_net` |
| Public TLS | Caddy | Caddy + basic auth + `noindex` |

Both stacks use the same compose file with different `COMPOSE_PROJECT_NAME`
and env files. PostgreSQL and Redis publish **no** host ports. Only Caddy is
bound to 80/443; nginx is loopback-only.

## 2. What is automated here — and what is not

Automated in this repository:

- `docker compose` isolation (project/image/network/volume/database names).
- Env templates for both stacks, with local secret generation.
- Additive Caddy vhosts with validation and automatic restore on failure.
- Deploy, rollback, health, public/local smoke, off-site backup and restore
  drill scripts.
- CI still runs PHPUnit and the Next.js typecheck/build for every PR.

**Not executed yet** (requires access this repository cannot provide):

- Real staging/production deployment, DNS changes, TLS issuance.
- Caddy reload on the host (LooksLoop must be checked first).
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

## 7. Caddy (additive, LooksLoop-safe)

The repo file `deploy/caddy/fahimni.caddy` only defines the four Fahimni hosts.
The installer appends this single line to the existing Caddyfile:

```caddyfile
import /etc/caddy/fahimni/*.caddy
```

It never replaces the Caddyfile or edits LooksLoop blocks.

```bash
# Generate a bcrypt hash interactively; do not put the password in shell history.
caddy hash-password
export FAHIMNI_STAGING_BASIC_HASH='$2a$...'

# Validate everything first (no changes).
sudo env FAHIMNI_STAGING_BASIC_HASH="$FAHIMNI_STAGING_BASIC_HASH" \
  deploy/scripts/caddy-activate.sh --check

# Install, validate again, graceful reload; auto-restores on failure.
sudo env FAHIMNI_STAGING_BASIC_HASH="$FAHIMNI_STAGING_BASIC_HASH" \
  deploy/scripts/caddy-activate.sh --apply
```

The script:

1. renders the staging hash into a private temp copy;
2. validates the Fahimni vhosts;
3. refuses if a Fahimni host is already defined elsewhere;
4. backs up `/etc/caddy/Caddyfile`;
5. installs `/etc/caddy/fahimni/fahimni.caddy` (mode 600);
6. validates the full Caddyfile and reloads gracefully;
7. restores the backup and reloads if anything fails.

Manual rollback if ever needed:

```bash
sudo cp /etc/caddy/Caddyfile.bak.<timestamp> /etc/caddy/Caddyfile
sudo systemctl reload caddy
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
- PostgreSQL/Redis have no published ports and separate volumes per stack.
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

- `docker compose config` validated for both stacks; volume/network/port
  isolation confirmed.
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

- No SSH key is authorized on the candidate server yet; real deploy, Caddy
  reload and TLS issuance are pending.
- `fahimni.me` still resolves to Hostinger parking; the A records in §5 are a
  proposal, not an executed change.
- The local drill did not use Caddy, public DNS or TLS; Caddy was syntax-checked
  only, and staging basic auth was tested at the configuration level.
- Freemius Sandbox credentials/account were not available; the 25-item checklist
  in `FREEMIUS_SETUP.md` remains unchecked.
- No real off-site upload was performed (no rclone remote/age key); only a local
  plaintext drill with `BACKUP_ALLOW_UNENCRYPTED=1` on disposable data.
