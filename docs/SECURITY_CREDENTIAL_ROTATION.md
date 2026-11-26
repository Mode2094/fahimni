# Credential rotation & revocation (owner actions)

Two privileged Cloudflare actions remain. They **cannot** be performed by the
application or from this repository: the production token is intentionally
zone-scoped, so Cloudflare returns `403` for every account-level endpoint
(`/user/tokens`, `/accounts/{id}/tokens`, `/accounts/{id}/r2/*`). Each needs one
short action by the account owner. This page is the runbook; the token-rotation
half also has a safe script.

Account: `47f4042417b8f04821fd5545fdd3b83f`
Zone: `fahimni.me` (`c36b9540f5d7d2f649b41d498350705d`)

---

## 1. Rotate the production Cloudflare API token

**Status:** pending owner action. The token in `/etc/fahimni/production.env` is
valid (`CLOUDFLARE_API_TOKEN`, zone-scoped) but has not been rotated.

**Required scope (do not widen it):**

| Scope | Permission |
| --- | --- |
| Zone → SSL and Certificates | Edit |
| Zone → DNS | Edit |
| Zone → Zone | Read |

Scoped to the `fahimni.me` zone only. It must **never** be an account-scoped or
account-admin token.

### Option A — rotate on the host (recommended, no secret leaves the server)

```bash
# on the production host, as root:
bash /opt/fahimni/production/current/deploy/scripts/rotate-cloudflare-token.sh
```

The script:

1. reads the new token from the terminal with echo disabled — never from argv,
   the environment, or shell history;
2. **refuses an over-privileged token** (if it can list accounts it aborts);
3. validates zone read + the Cloudflare-for-SaaS surface *before* applying;
4. keeps a `0600` timestamped backup of the previous env for rollback;
5. updates `/etc/fahimni/production.env` atomically and recreates
   `app`/`scheduler`/`horizon`;
6. re-verifies live with `php artisan fahimni:cloudflare-saas status`.

Afterwards, delete the **old** token: *My Profile → API Tokens → (previous
fahimni token) → Delete*.

### Option B — dashboard

Create a replacement token with the scope table above, place it in
`/etc/fahimni/production.env` as `CLOUDFLARE_API_TOKEN`, recreate the app
containers, then delete the old token.

---

## 2. Revoke the old R2 credential (SECURITY — do this first)

**Finding:** the R2 access key rotation on 2026-11-26 (`adfc4d…` → `1ceeee…`)
replaced the credential in the rclone config but **did not revoke the old one**.
Verified on the production host: the old key still authenticates and still has
**read _and_ write** access to `fahimni-backups` (a probe object was uploaded and
deleted successfully). Anywhere that old secret was copied is still live.

**Act on it:** Cloudflare dashboard → **R2 → Manage R2 API Tokens** → find the
token whose **Access Key ID starts `adfc4d…`** (created on/before 2026-11-26
16:12 UTC) → **Delete**. Do **not** delete the active token (`1ceeee…`).

### Verify the old key is gone

From the production host (read-only check; expected to **fail** after deletion):

```bash
BAK=/home/fahimni/.config/rclone/rclone.conf.bak.20261126T164017Z-rotate
TMP=$(mktemp); cp "$BAK" "$TMP"; sed -i 's/^\[fahimni-r2\]/[oldtest]/' "$TMP"; chmod 600 "$TMP"
RCLONE_CONFIG="$TMP" rclone lsf "oldtest:fahimni-backups/production"   # must now error
rm -f "$TMP"
```

Confirm backups still work with the **active** key:

```bash
sudo -u fahimni -H rclone lsf "fahimni-r2:fahimni-backups/production"
```

---

## 3. What is already correct

- The production **Cloudflare API token is not over-privileged** — account-level
  endpoints return `403`; only zone read + the SaaS surface succeed.
- The **active R2 key** (`1ceeee…`) is bucket-scoped and works for offsite
  backups; nightly backup + weekly remote restore drill are unaffected.
- No real secret is tracked in git; `.env` files are untracked and mode `600`.
- The backup encryption identity is held offline only
  (`/root/fahimni-age.key`, and the owner's offline copy) — never in the repo or
  in Cloudflare.
