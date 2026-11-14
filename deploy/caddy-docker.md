# Caddy-in-Docker integration (LooksLoop-safe)

The VPS already terminates TLS for LooksLoop with a **Caddy container**
(`caddy:2-alpine`), not a systemd `caddy` unit. This document explains how the
Fahimni vhosts are added to that container **without** editing its Caddyfile,
restarting it, or touching its site blocks, certificates or DNS.

## 1. What the old (systemd) approach could not do

The previous flow (`caddy-activate.sh`) assumed:

- a host `caddy` service → `systemctl reload caddy`;
- a writable `/etc/caddy/Caddyfile` we could append `import …` to;
- loopback upstreams `127.0.0.1:8080/8081`.

On this host none of that holds:

| Reality (verified) | Consequence |
|---|---|
| Caddy runs in container `looksloop-production-caddy-1` | No `systemctl`; reload goes through the container |
| `/etc/caddy/Caddyfile` is a **read-only bind mount** from an immutable release path | We cannot append an `import` line |
| The real Caddyfile has **no `import`** and owns the single global options block | Everything must merge into one config |
| Caddy publishes `80/443`; nothing else can bind them | A second edge on 80/443 is impossible |
| Its admin API is on `127.0.0.1:2019` **inside** the container | The only in-place reload path is via that API |

`deploy/scripts/caddy-activate.sh` is now a one-line forwarder to the Docker
integrator; the `import`/`systemctl` logic is gone.

## 2. Topology

```text
                internet
                   │  80/443
        ┌──────────▼───────────┐        looksloop-production_default
        │ looksloop-…-caddy-1  │◀───────  LooksLoop api/store/web/control/mirror
        │  caddy:2-alpine      │
        │  (LooksLoop Caddyfile│
        │   + merged Fahimni)  │
        └──────────┬───────────┘
                   │  fahimni-proxy  (shared reverse-proxy network)
        ┌──────────▼───────────┐
        │  <alias>:80  nginx   │   fahimni-prod  /  fahimni-staging
        └──────────┬───────────┘
                   │  fahimni-net  (app network, egress allowed)
        ┌──────────▼───────────┐
        │ Next.js + PHP-FPM    │
        └──────────┬───────────┘
                   │  fahimni-db  (INTERNAL, no egress)
        ┌──────────▼───────────┐
        │ PostgreSQL + Redis   │
        └──────────────────────┘
```

- The **only** bridge between the two projects is `fahimni-proxy`, and the only
  service on it is Fahimni **nginx**. No database or Redis of either project is
  ever attached to it.
- Each stack advertises nginx under a **unique** alias (`PROXY_ALIAS`:
  `fahimni-prod`, `fahimni-staging`) so production and staging never collide.
- `fahimni-db` is `internal: true`: postgres/redis have no published ports and
  no route to the internet.

## 3. The mechanism: merged config over the admin API

`deploy/scripts/caddy-docker-integrate.sh` does, in order:

1. **network** — create `fahimni-proxy` if missing and
   `docker network connect` the Caddy container to it (a runtime attach; the
   container is never restarted, recreated or reconfigured);
2. **read** — `docker exec <caddy> cat /etc/caddy/Caddyfile` (the container's
   live, read-only file);
3. **merge** — base config + the Fahimni fragment
   (`deploy/caddy/fahimni.caddy`), with the staging bcrypt hash substituted and
   upstreams resolved to the nginx aliases;
4. **validate** — pipe the merged config into the container and run
   `caddy validate` (inside the container, with its own env);
5. **reload** — `caddy reload` over the admin API (graceful; LooksLoop and its
   certificates stay up).

The fragment is deliberately narrow: no global options block, no `:80`/`:443`
catch-all, only concrete hostnames — so it wins over LooksLoop's catch-alls for
its own SNI/Host and never conflicts with them (proven by
`deploy/tests/caddy-merge-validate.sh`).

## 4. Commands

Run as the deploy user (Docker access; no root needed if the state dir is
writable):

```bash
cd /opt/fahimni/production/current         # or staging/current

# 1. hash for the staging password (do not paste into shell history)
docker exec looksloop-production-caddy-1 caddy hash-password

# 2. validate the merged config — READ ONLY. Uses a throwaway caddy container
#    with the real base + non-secret env; the LooksLoop container is untouched.
FAHIMNI_STAGING_BASIC_HASH='$2a$…' \
  deploy/scripts/caddy-docker-integrate.sh --check

# 3. apply (requires --yes): connect network, install merged config, reload
FAHIMNI_STAGING_BASIC_HASH='$2a$…' \
  deploy/scripts/caddy-docker-integrate.sh --apply --yes

# status
deploy/scripts/caddy-docker-integrate.sh --status

# rollback: reload the container's own Caddyfile and detach the network
deploy/scripts/caddy-docker-integrate.sh --rollback --yes --disconnect
```

`--apply` stores the rendered fragment (mode 600) in `FAHIMNI_STATE_DIR`
(default `/var/lib/fahimni`) so a timer can reconcile without the hash.

## 5. Continuity across a LooksLoop update (the important part)

`docker network connect` and the in-memory reload are **runtime** state. When
LooksLoop deploys a new release it recreates its Caddy container from the new
release path, which:

- starts the container from the **Caddyfile of the new release** (Fahimni vhosts
  gone, until re-applied);
- **loses** the `fahimni-proxy` attachment.

`--reconcile` fixes both, idempotently. It re-reads the container's **current**
Caddyfile every run (so LooksLoop additions are preserved) and re-applies
whenever any of these is true:

1. the container is no longer attached to `fahimni-proxy` (network detached);
2. the container's **id** differs from the one recorded by the last `--apply`
   (LooksLoop recreated it — even if the Caddyfile bytes, and therefore the
   merged hash, are identical);
3. the **live** running config no longer carries the Fahimni vhosts (e.g.
   LooksLoop reloaded its own Caddyfile after a container-internal restart),
   detected by reading Caddy's own admin `config/` endpoint;
4. the merged config hash changed (a real Caddyfile/fragment change).

Triggers 2 and 3 exist precisely because comparing only the merged hash is not
enough: a redeploy that keeps the same Caddyfile still loses the runtime
network attachment and the in-memory reload. `deploy/tests/caddy-reconcile-recreate.sh`
proves all of these against a real (throwaway) Caddy container.

Enable the timer so recovery happens within ~1 minute:

```bash
sudo install -m 700 -o fahimni -g fahimni -d /var/lib/fahimni
sudo install -m 644 deploy/systemd/fahimni-caddy-reconcile.service /etc/systemd/system/
sudo install -m 644 deploy/systemd/fahimni-caddy-reconcile.timer   /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now fahimni-caddy-reconcile.timer
systemctl list-timers fahimni-caddy-reconcile.timer
```

The unit runs the script from whichever release exists — `production/current`
first, falling back to `staging/current` — so it works on a **staging-only**
host too (no `production/current` required).

Cron alternative (every 2 minutes):

```cron
*/2 * * * * fahimni sh -c 'for d in /opt/fahimni/production/current /opt/fahimni/staging/current; do [ -x "$d/deploy/scripts/caddy-docker-integrate.sh" ] && exec "$d/deploy/scripts/caddy-docker-integrate.sh" --reconcile --yes; done' >> /var/log/fahimni/caddy-reconcile.log 2>&1
```

Notes:

- The staging bcrypt hash lives in the stored rendered fragment; rotating the
  staging password means re-running `--apply` with the new hash.
- Certificates are stored in LooksLoop's `caddy_data` volume, so reloads and
  re-applies reuse them — no re-issuance.
- If LooksLoop ever *removes* its container (down), reconcile simply waits; it
  never creates or restarts anything.

## 6. Zero-touch alternative (no LooksLoop modification at all)

If the owner prefers not to touch the LooksLoop container even operationally:

1. Run Fahimni's own Caddy (or nginx TLS) on an **alternate port**, e.g. `8443`,
   publishing `0.0.0.0:8443`, with a Cloudflare Origin CA certificate.
2. Put `fahimni.me` / `api.fahimni.me` / `staging.fahimni.me` /
   `api-staging.fahimni.me` behind Cloudflare with origin port `8443`.

Trade-offs: requires a DNS/Cloudflare change, adds a Cloudflare dependency, and
does **not** use port 80/443 for ACME. Kept as a fallback; not the default.

## 7. Backup / validation / rollback plan

**Before any change**

```bash
# capture the current state (read-only) for the report / diffs
docker exec looksloop-production-caddy-1 cat /etc/caddy/Caddyfile > /root/caddy.base.$(date -u +%Y%m%dT%H%M%SZ)
docker inspect -f '{{json .NetworkSettings.Networks}}' looksloop-production-caddy-1
docker ps --format '{{.Names}}\t{{.Status}}'
openssl s_client -connect fahimni.me:443 -servername fahimni.me </dev/null 2>/dev/null | openssl x509 -noout -subject -dates || true
```

**Validation order** (do not skip)

1. DNS resolves to `187.127.87.101` (§ `deploy/dns-cutover.md`) — TLS needs it.
2. `caddy-docker-integrate.sh --check` passes.
3. `--apply --yes`; then `--status` shows `connected: yes`.
4. LooksLoop unchanged: its public host re-validates, `docker ps` shows the same
   containers, no restarts.
5. Fahimni TLS issued: `curl -sSI https://staging.fahimni.me` → `401` (basic
   auth) and `https://api-staging.fahimni.me/up` → `200`.

**Rollback** (either)

- `caddy-docker-integrate.sh --rollback --yes --disconnect` (fast, in place), or
- `docker restart looksloop-production-caddy-1` — the container restarts from its
  own Caddyfile and drops the merge (last resort; restart the timer reconcile
  after). Never `docker compose down` on LooksLoop.

## 8. Security notes

- The merge only ever **adds** hostnames; LooksLoop blocks are copied verbatim.
- `--check` never writes into the LooksLoop container: it validates in a
  throwaway `caddy:2-alpine` using only non-secret env (`ROOT_DOMAIN`,
  `CONTROL_DOMAIN`, `MIRROR_DOMAIN`, `ACME_EMAIL`, `TRUSTED_PROXY_RANGES`,
  `API_INTERNAL_URL`) — the container's full env (which holds secrets) is never
  dumped.
- Staging stays behind Caddy basic auth + `X-Robots-Tag: noindex`; the Freemius
  webhook path is signature-authenticated, not basic-auth.
- `fahimni-db` is internal; only nginx joins `fahimni-proxy`.
