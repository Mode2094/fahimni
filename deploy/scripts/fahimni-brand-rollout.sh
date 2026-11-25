#!/usr/bin/env bash
# =============================================================================
#  Fahimni — PRODUCTION BRAND ROLLOUT  «منصة فهمني» / Fahimni
#  (Phase B: independent, fully reversible. Phase A — Google + CSP — is already
#   live on tag prod-hotfix1 and is NOT rebuilt or touched here.)
#
#  WHAT THIS DOES
#    * fetches the approved release commit from GitHub (default: the brand
#      commit dd13c63 on integration/launch-2026-11 — override with
#      FAHIMNI_RELEASE_SHA=<sha|ref> and FAHIMNI_RELEASE_BRANCH=<branch> so the
#      unified Release Candidate, which also carries the P0 Google/session fix
#      from PR #24, is what gets deployed instead of a brand-only checkout),
#    * builds images tagged prod-brand4 and replaces ONLY these four containers:
#        app  frontend  scheduler  horizon
#    * BEFORE the cutover, runs a READ-ONLY preflight that compares the pending
#      migrations reported by the new image against an explicit allowlist, then
#      (only when the owner approves it) applies exactly those approved files,
#      one `migrate --path=...` at a time, from the new image. The generic
#      `docker compose run migrate` service is never used (it would run every
#      pending migration and `plans:sync`),
#    * applies the targeted, additive-only `cards.export` permission fix
#      (the full RolePermissionSeeder uses syncPermissions() and is never run),
#    * RELOADS the stack's nginx so it re-resolves the recreated containers'
#      new addresses (nginx caches the upstream IPs; without a reload the site
#      returns 502 even though the new containers are healthy),
#    * points the platform name (APP_NAME / MAIL_FROM_NAME) at the new identity,
#    * verifies container readiness, the listening port, the public DNS name,
#      that the new identity is really served, and that the Google + CSP fixes
#      from Phase A did not regress,
#    * on ANY failure, dumps exactly WHY the health check failed and then
#      restores the previous env file and the previous images automatically
#      (reloading nginx again so the rollback is not itself left on 502).
#
#  WHAT THIS NEVER TOUCHES
#    DNS, Caddy, LooksLoop, Staging, the nginx *configuration*, redis, the
#    generic one-shot migrate service, Freemius settings, prices, subscriptions
#    and all user data. The production database is only written by the isolated,
#    allowlisted migration step (steps 4c/4d) and only when that write is
#    explicitly approved: `FAHIMNI_ALLOW_DB_WRITE=1` plus a confirmed restore
#    test `FAHIMNI_DB_BACKUP_VERIFIED=1`, and `FAHIMNI_ALLOW_PERMISSION_WRITE=1`
#    for the permission fix. With those unset (the default) the script is
#    read-only against the database. Any applied migration is nullable and
#    additive, so a rollback keeps it while the previous release keeps working.
#    nginx is only signalled to reload its existing config — its files, volumes
#    and the shared LooksLoop proxy network are untouched.
#
#  MINIMAL SURFACE
#    `docker compose up -d --no-deps` on exactly four services, a
#    `nginx -t && nginx -s reload` on the stack's own nginx service, and
#    `docker compose run --rm --no-deps app php artisan …` for the approved
#    migration / permission steps. `--no-deps` is what keeps postgres/redis and
#    the migrate service out of the operation.
#
#  Run:  sudo bash /home/fahimni/fahimni-brand-rollout.sh
#
#  RUN MODES (env vars; every database write is OFF unless explicitly enabled)
#    sudo bash …                                  # deploy, READ-ONLY on the DB
#    sudo FAHIMNI_ALLOW_PERMISSION_WRITE=1 bash … # + the cards.export fix
#    sudo FAHIMNI_ALLOW_DB_WRITE=1 \
#         FAHIMNI_DB_BACKUP_VERIFIED=1 bash …     # + the approved migration(s)
#    FAHIMNI_APPROVED_MIGRATIONS="2026_11_16_000001_add_lesson_id_to_assignments.php"
#    FAHIMNI_RELEASE_SHA=<sha> FAHIMNI_RELEASE_BRANCH=<branch>
# =============================================================================
set -Eeuo pipefail

STACK=/opt/fahimni/production
# Override to deploy the unified Release Candidate (must include PR #24's
# Google/session fix + PR #23's nginx reload). Defaults to the brand commit.
SHA="${FAHIMNI_RELEASE_SHA:-dd13c63988bea78b5b1fc232c0b259f8be49f4cf}"
REL="$STACK/releases/$SHA"
ENVF=/etc/fahimni/production.env
REPO_URL=https://github.com/Mode2094/fahimni.git
# The unified Release Candidate lives on its own branch; the clone/fetch is
# single-branch, so the branch that carries $SHA must be named here too.
# release_sha_on_branch() then refuses to deploy when $SHA is not reachable
# from this branch (see the FAHIMNI_LIB block).
BRANCH="${FAHIMNI_RELEASE_BRANCH:-integration/launch-2026-11}"
NEW_TAG=prod-brand4
PORT="${APP_PORT:-8080}"
BASE="http://127.0.0.1:$PORT"
HOSTHDR=fahimni.me
TS="$(date -u +%Y%m%dT%H%M%SZ)"
BAKDIR="$STACK/backups"
ENV_BAK="$BAKDIR/production.env.$TS.bak"
NOTE="$BAKDIR/rollback-$NEW_TAG.$TS.txt"
SERVICES=(app frontend scheduler horizon)
# nginx is a compose service in THIS stack (not a host binary); it is the only
# service that must be reloaded, never rebuilt, after the four above change.
NGINX_SVC=nginx
CSP_PAGE=/teacher/dashboard

# ── database changes (all OFF by default; owner approval is required) ───────
# The release ships additive migrations the live DB may not have yet. Only the
# files listed in FAHIMNI_APPROVED_MIGRATIONS (comma-separated) may be applied,
# and only when FAHIMNI_ALLOW_DB_WRITE=1 AND FAHIMNI_DB_BACKUP_VERIFIED=1 are
# set. The generic `docker compose run migrate` service is deliberately NOT
# used: it would run `migrate --force` (any pending migration, approved or not)
# and `plans:sync`.
APPROVED_MIGRATIONS_DEFAULT="2026_11_16_000001_add_lesson_id_to_assignments.php"

# Core surfaces: must be 200, must carry the new identity, must not carry the old.
CORE_PAGES=(/ /teacher/login /student/login /admin/login /register/student /register/teacher)
# Marketing / legal surfaces: must never leak the old name; a non-200 is only
# reported (never rolls back a working deployment).
MORE_PAGES=(/support /terms /privacy /refund-policy /contact /for-teachers
            /create-online-courses /live-online-classes /lms-for-teachers
            /online-teaching-platform /teacher-website /teacher/dashboard)

# ── FAHIMNI_LIB_BEGIN ────────────────────────────────────────────────────────
# Everything between the two markers is also extracted and unit-tested by
# deploy/tests/brand-rollout-nginx-test.sh (nginx reload, readiness gate,
# failure diagnostics, rollback). Keep it dependency-free and side-effect-free
# apart from the commands it runs; the main body below stays thin.
step(){ printf '\n=== %s ===\n' "$*"; }
log(){  printf '  %s\n' "$*"; }
warn(){ printf 'WARN: %s\n' "$*" >&2; }
die(){  printf '\n!! %s\n' "$*" >&2; exit 1; }

http_code(){ curl -sS -o /dev/null -w '%{http_code}' --max-time "${2:-10}" -H "Host: $HOSTHDR" "$BASE$1" 2>/dev/null || printf '000'; }
snapshot(){ sha256sum "$1" 2>/dev/null | cut -d' ' -f1 || true; }

# ── nginx: the stack's own compose service ──────────────────────────────────
# nginx resolves and caches the addresses of the containers it proxies to
# (app:9000 and frontend:3000, see docker/nginx/default.conf) when it loads its
# config. Recreating app/frontend hands them NEW addresses, and nginx keeps
# proxying to the DEAD ones until it re-reads its config — that is the
# "502 after a redeploy" regression. `nginx -t` (validate) followed by
# `nginx -s reload` (re-read + re-resolve the compose DNS names) is the fix; a
# full container restart is the fallback when the config or binary is unusable.

nginx_cid(){  # $1 = release dir holding the compose file ; prints the container id
  ( cd "$1" && docker compose --env-file "$ENVF" ps -q "$NGINX_SVC" 2>/dev/null | head -1 )
}

nginx_healthy(){  # $1 = release dir ; 0 = running and not unhealthy
  local cid st health
  cid="$(nginx_cid "$1")"
  [ -n "$cid" ] || return 1
  st="$(docker inspect -f '{{.State.Running}}' "$cid" 2>/dev/null || echo false)"
  [ "$st" = "true" ] || return 1
  health="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$cid" 2>/dev/null || echo none)"
  [ "$health" = "unhealthy" ] && return 1
  return 0
}

reload_nginx(){  # $1 = release dir holding the compose file ; always leaves nginx up
  local dir="$1"
  if ! nginx_healthy "$dir"; then
    warn "nginx is not running/healthy — starting it (up -d --no-deps $NGINX_SVC)"
    ( cd "$dir" && docker compose --env-file "$ENVF" up -d --no-deps "$NGINX_SVC" ) \
      || { warn "could not start the $NGINX_SVC service"; return 1; }
    sleep 2
  fi
  if ( cd "$dir" && docker compose --env-file "$ENVF" exec -T "$NGINX_SVC" nginx -t ) >/dev/null 2>&1; then
    if ( cd "$dir" && docker compose --env-file "$ENVF" exec -T "$NGINX_SVC" nginx -s reload ) >/dev/null 2>&1; then
      log "nginx config reloaded — upstream container addresses re-resolved"
      return 0
    fi
    warn "nginx -s reload failed — falling back to a container restart"
  else
    warn "nginx -t failed — restarting the container instead of reloading"
  fi
  ( cd "$dir" && docker compose --env-file "$ENVF" restart "$NGINX_SVC" ) >/dev/null 2>&1 \
    && { log "nginx restarted"; return 0; }
  warn "could not reload OR restart nginx — the site may answer 502"
  return 1
}

# Explain WHY a deploy is failing, BEFORE the automatic rollback hides it: the
# per-endpoint codes, a probe of the upstreams that bypasses nginx (so a stale
# nginx is told apart from a dead container), the container state, an nginx
# config test and the tail of the relevant logs.
dump_diagnostics(){
  local p cid
  warn "──────── DIAGNOSTICS: why the health check failed ────────"
  warn "[endpoint codes via nginx]"
  for p in /up /api/health/live /api/health/ready /; do
    printf '  %-22s %s\n' "$p" "$(http_code "$p")" >&2
  done
  warn "[upstream probe — bypassing nginx]"
  printf '  app      (php-fpm app:9000)    %s\n' \
    "$( cd "$REL" && docker compose --env-file "$ENVF" exec -T app \
         php -r "echo @fsockopen('127.0.0.1',9000)?'pong':'fail';" 2>/dev/null || echo unavailable)" >&2
  printf '  frontend (next     frontend:3000) %s\n' \
    "$( cd "$REL" && docker compose --env-file "$ENVF" exec -T frontend \
         wget -qO- http://127.0.0.1:3000/ >/dev/null 2>&1 && echo pong || echo unavailable)" >&2
  warn "[container state]"
  ( cd "$REL" && docker compose --env-file "$ENVF" ps ) >&2 2>&1 || true
  cid="$(nginx_cid "$REL")"
  warn "[nginx container] id=${cid:-<none>} status=$(docker inspect -f '{{.State.Status}} health={{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$cid" 2>/dev/null || echo unknown)"
  warn "[nginx -t]"
  ( cd "$REL" && docker compose --env-file "$ENVF" exec -T "$NGINX_SVC" nginx -t ) >&2 2>&1 || true
  warn "[last 15 log lines: app / frontend / $NGINX_SVC]"
  local s
  for s in app frontend "$NGINX_SVC"; do
    warn "  --- $s ---"
    ( cd "$REL" && docker compose --env-file "$ENVF" logs --tail=15 "$s" ) >&2 2>&1 || true
  done
  warn "───────────────────────────────────────────────────────────"
}

# Before the deploy is accepted: every container running (and healthy, when it
# declares a healthcheck), the four replaced services actually on $NEW_TAG, the
# loopback port listening, and the public host name resolving.
verify_readiness(){
  local rc=0 i cid state health img
  log "-- containers (${SERVICES[*]} $NGINX_SVC): running, healthy, on the new tag"
  for i in "${SERVICES[@]}" "$NGINX_SVC"; do
    cid="$(docker ps -q --filter "name=^fahimni-$i-1\$" 2>/dev/null | head -1)"
    if [ -z "$cid" ]; then
      warn "fahimni-$i-1 is NOT running"; rc=1; continue
    fi
    state="$(docker inspect -f '{{.State.Status}}' "$cid" 2>/dev/null || echo '?')"
    health="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}-{{end}}' "$cid" 2>/dev/null || echo '-')"
    img="$(docker inspect -f '{{.Config.Image}}' "$cid" 2>/dev/null || echo '?')"
    printf '  %-22s %-8s health=%-10s %s\n' "fahimni-$i-1" "$state" "$health" "$img"
    [ "$state" = "running" ] || { warn "fahimni-$i-1 is '$state', not running"; rc=1; }
    [ "$health" = "unhealthy" ] && { warn "fahimni-$i-1 is unhealthy"; rc=1; }
    if [ "$i" != "$NGINX_SVC" ] && [ "$img" != "fahimni-$i:$NEW_TAG" ]; then
      warn "fahimni-$i-1 runs '$img', expected 'fahimni-$i:$NEW_TAG'"; rc=1
    fi
  done

  log "-- port $PORT is listening on the loopback"
  # Capture first, then match: `ss | grep -q` under `pipefail` can report a
  # false negative when grep exits early and ss is killed by SIGPIPE.
  local listeners
  listeners="$(ss -ltn 2>/dev/null || true)"
  if grep -qE "[:.]${PORT}[[:space:]]" <<<"$listeners"; then
    log "  $PORT listening"
  else
    warn "nothing is listening on $PORT"; rc=1
  fi

  log "-- DNS: $HOSTHDR resolves"
  if getent hosts "$HOSTHDR" >/dev/null 2>&1; then
    log "  $HOSTHDR -> $(getent hosts "$HOSTHDR" | awk '{print $1}' | tr '\n' ' ')"
  else
    # Informational only: a resolver hiccup must not roll back a working deploy,
    # and the Host-header probes above already prove nginx serves this name.
    warn "$HOSTHDR did not resolve via getent (informational)"
  fi

  return "$rc"
}

# Fail closed when $2 (a commit) is not reachable from branch $3 in the checkout
# at $1. The release clone is `--single-branch`, so a SHA that only lives on a
# DIFFERENT branch is simply not fetchable: `git checkout --detach $SHA` then
# dies with a confusing "reference is not a tree" / "unknown revision" error, or
# — worse — a stale object from an earlier run is silently reused. This checks
# reachability up front and lets the caller abort with an explicit message.
#
# Returns 0 only when the commit exists AND descends from the branch tip.
release_sha_on_branch(){  # $1 = release dir ; $2 = sha ; $3 = branch
  local dir="$1" sha="$2" branch="$3" tip
  [ -n "$dir" ] && [ -n "$sha" ] && [ -n "$branch" ] || return 1
  [ -d "$dir/.git" ] || return 1
  # Fetch exactly the requested branch into its remote-tracking ref. This does
  # not depend on the clone's configured (single-branch) refspec, and it fails
  # when the branch does not exist on the remote — both are fail-closed.
  git -C "$dir" fetch --quiet origin "+refs/heads/$branch:refs/remotes/origin/$branch" 2>/dev/null || true
  # Resolve the branch tip explicitly; NO FETCH_HEAD fallback, which can be a
  # leftover from an unrelated fetch and would accept a bogus branch name.
  tip="$(git -C "$dir" rev-parse --verify --quiet "refs/remotes/origin/$branch")" || return 1
  [ -n "$tip" ] || return 1
  git -C "$dir" cat-file -e "${sha}^{commit}" 2>/dev/null || return 1
  git -C "$dir" merge-base --is-ancestor "$sha" "$tip" 2>/dev/null
}

# platform-settings returns {"settings":{"platform_name":"…"}} and the value is
# very often JSON-escaped (\u0645\u0646\u0635\u0629…). Grepping the raw body for
# the Arabic text therefore reports a FALSE failure and triggers a bogus
# rollback, so decode with a real JSON parser (jq → python3 → php) and compare
# the DECODED value. Prints ONE verdict token and returns its status:
#   ok         (rc 0)  the decoded name is exactly «منصة فهمني»
#   noparser   (rc 2)  no JSON parser is installed
#   invalid    (rc 1)  the body is not valid JSON, or has no string field
#   empty      (rc 1)  the decoded name is empty
#   legacy     (rc 1)  the decoded name is an old brand value
#   wrong:<n>  (rc 1)  any other value
platform_name_status(){  # $1 = JSON body
  local body="$1" name="" rc=0
  if command -v jq >/dev/null 2>&1; then
    name="$(jq -er '.settings.platform_name | strings' <<<"$body" 2>/dev/null)" || rc=1
  elif command -v python3 >/dev/null 2>&1; then
    name="$(python3 -c '
import json, sys
try:
    doc = json.load(sys.stdin)
except Exception:
    sys.exit(1)
node = doc.get("settings") if isinstance(doc, dict) else None
value = node.get("platform_name") if isinstance(node, dict) else None
if not isinstance(value, str):
    sys.exit(1)
sys.stdout.write(value)
' <<<"$body" 2>/dev/null)" || rc=1
  elif command -v php >/dev/null 2>&1; then
    # shellcheck disable=SC2016
    name="$(php -r '$d=json_decode(stream_get_contents(STDIN),true); $v=(is_array($d)&&isset($d["settings"])&&is_array($d["settings"]))?($d["settings"]["platform_name"]??null):null; if(!is_string($v))exit(1); echo $v;' <<<"$body" 2>/dev/null)" || rc=1
  else
    printf 'noparser\n'; return 2
  fi
  [ "$rc" = 0 ] || { printf 'invalid\n'; return 1; }
  [ -n "$name" ] || { printf 'empty\n'; return 1; }
  if [ "$name" = 'منصة فهمني' ]; then printf 'ok\n'; return 0; fi
  case "$name" in
    *Tadreesly*|*tadreesly*|*تدريسلي*|*تدريسلى*) printf 'legacy\n'; return 1 ;;
  esac
  printf 'wrong:%s\n' "$name"
  return 1
}

# Source-level brand audit. The OLD brand must not survive anywhere that reaches
# a user (a browser, the API, or a served asset). It legitimately survives in:
#   • documentation and the launch reports (docs/),
#   • the deploy tooling and its tests (deploy/),
#   • the two normalisation helpers below that MAP a legacy value onto the new
#     name (app/Support/PlatformBrand.php, frontend/src/lib/brand.ts),
#   • the detection greps themselves — a pattern that LOOKS for the old name is
#     not a user-visible string.
# None of those is a leak, so none is an error. Only the user-visible source
# trees are scanned; the LIVE surfaces are separately verified after deploy.
#
# Prints the offending files (one per line); empty output = clean.
brand_source_audit(){  # $1 = release dir ; prints user-visible offenders
  local rel="$1" d stale="" dirs=()
  for d in frontend/src frontend/public app resources public routes config; do
    [ -e "$rel/$d" ] && dirs+=("$rel/$d")
  done
  if [ "${#dirs[@]}" -gt 0 ]; then
    stale="$(grep -rIl -E 'Tadreesly|tadreesly' "${dirs[@]}" 2>/dev/null || true)"
  fi
  # The two intentional legacy-normalisation helpers may mention the old value.
  # `grep -v` exits 1 whenever it removes EVERY line — which is exactly the CLEAN
  # case, because those two helpers are the only old-name matches a good release
  # has. Under `pipefail` that made the whole function exit 1, and `set -e` then
  # aborted the deploy at `offenders="$(brand_source_audit "$REL")"` with nothing
  # printed (a silent SAFE STOP on a perfectly clean tree). `|| true` keeps a
  # clean audit at exit 0 while every real offender is still printed on stdout —
  # and the caller still aborts via its `[ -n "$offenders" ]` gate.
  printf '%s\n' "$stale" \
    | grep -v -E '/(app/Support/PlatformBrand\.php|frontend/src/lib/brand\.ts)$' \
    | sed '/^[[:space:]]*$/d' || true
}
# ── FAHIMNI_LIB_END ──────────────────────────────────────────────────────────

# ── FAHIMNI_DB_BEGIN ────────────────────────────────────────────────────────
# Gated, additive-only database work. Extracted and unit-tested by
# deploy/tests/brand-rollout-nginx-test.sh (FAHIMNI_DB_* markers).

# The migration files this deployment is allowed to apply, one per line.
approved_migrations(){
  printf '%s\n' "${FAHIMNI_APPROVED_MIGRATIONS:-$APPROVED_MIGRATIONS_DEFAULT}" \
    | sed 's/[,;]/\n/g' | sed 's/^[[:space:]]*//; s/[[:space:]]*$//' | sed '/^$/d'
}

# Run a one-off artisan command inside the NEW app image. `run --rm --no-deps`
# never starts dependencies (postgres/redis/migrate stay exactly as they are)
# and never touches the migrate service, so only the given command runs.
app_artisan(){  # $1 = release dir ; rest = artisan arguments
  local dir="$1"; shift
  ( cd "$dir" && IMAGE_TAG="$NEW_TAG" docker compose --env-file "$ENVF" \
      run --rm --no-deps app php artisan "$@" )
}

# Pending migrations, diffed from the migration FILES by our own read-only
# `migrations:pending` command — NOT parsed out of `migrate:status`.
#
# Why not `migrate:status --pending` (the old approach):
#   • Laravel's `Migrator::getMigrationName()` STRIPS `.php`, so the CLI prints
#     migration names WITHOUT the extension. A parser keyed on `…<name>.php`
#     therefore matched nothing and reported "no pending migrations" — silently
#     letting UNapproved migrations through.
#   • Its exit code is not an error signal: `handle()` returns the option value,
#     so `--pending` alone can exit 0 while pending migrations exist,
#     `--pending=1` exits 1, and a missing table also exits 1.
#
# `migrations:pending` instead compares the files on disk against the
# `migrations` table and prints the file basenames WITH `.php` — exactly the
# strings the allowlist carries, independent of the framework's CLI formatting.
#
# Prints one filename per line. Returns:
#   0  the list was produced (possibly empty — safe to compare)
#   2  the state could not be read, or a line was not a migration filename.
#      This is FAIL CLOSED: it is NEVER treated as "nothing pending".
db_pending_migrations(){  # $1 = release dir ; prints pending filenames, one per line
  local dir="$1" out rc
  out="$(app_artisan "$dir" migrations:pending --no-ansi 2>/dev/null)"; rc=$?
  [ "$rc" = "0" ] || return 2
  # Fail closed unless EVERY non-empty line is exactly a migration filename with
  # `.php`. Anything else (framework banners, a name without the extension, a
  # stray log line) means the output shape is not what we validated.
  if printf '%s\n' "$out" | sed '/^[[:space:]]*$/d' \
       | grep -qvE '^[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[A-Za-z0-9_]+\.php$'; then
    return 2
  fi
  printf '%s\n' "$out" | sed '/^[[:space:]]*$/d' | sort -u
}

# Compare what is pending against the approved allowlist. Any pending migration
# NOT on the list aborts the deploy BEFORE anything is touched. Missing approved
# files in the release also abort (nothing to apply would be a silent no-op).
db_preflight(){  # $1 = release dir ; 0 = safe, 1 = unexpected, 2 = unreadable
  local dir="$1" pending approved extra m rc
  pending="$(db_pending_migrations "$dir")"; rc=$?
  if [ "$rc" = "2" ]; then
    warn "could not read migrations:pending from the new image — aborting rather than guessing"
    return 2
  fi
  approved="$(approved_migrations)"

  if [ -n "$pending" ]; then
    printf '%s\n' "$pending" | sed 's/^/  pending: /'
  else
    log "pending: <none>"
  fi

  extra="$(comm -23 <(printf '%s\n' "$pending" | sort) <(printf '%s\n' "$approved" | sort) | sed '/^$/d')"
  if [ -n "$extra" ]; then
    printf '%s\n' "$extra" | sed 's/^/  UNAPPROVED pending: /' >&2
    warn "the release would apply migration(s) that were not approved"
    return 1
  fi

  for m in $approved; do
    if [ ! -f "$dir/database/migrations/$m" ]; then
      warn "approved migration '$m' is not present in the release tree"
      return 1
    fi
  done
  log "every pending migration is on the approved allowlist"
  return 0
}

# Apply ONLY the approved migrations, from the new image, one --path at a time.
# Runs BEFORE the cutover so the new code never starts on an incomplete schema;
# a failure here leaves the running production containers untouched.
apply_approved_migrations(){  # $1 = release dir
  local dir="$1" m
  for m in $(approved_migrations); do
    [ -f "$dir/database/migrations/$m" ] || { warn "approved migration $m missing from the release"; return 1; }
    if ! app_artisan "$dir" migrate --force --no-interaction --path="database/migrations/$m"; then
      warn "migration $m FAILED — the schema was not changed for this file"
      return 1
    fi
    log "applied $m"
  done
  return 0
}

# Additive-only permission fix ($2=1 applies, $2=0 previews). Never syncs roles.
ensure_cards_export_permission(){  # $1 = release dir ; $2 = apply?
  local dir="$1" apply="${2:-0}"
  if [ "$apply" = "1" ]; then
    app_artisan "$dir" permissions:ensure-cards-export --apply
  else
    app_artisan "$dir" permissions:ensure-cards-export
  fi
}
# ── FAHIMNI_DB_END ──────────────────────────────────────────────────────────

# Stable LooksLoop identity signature (same one the approved production tooling
# uses). It deliberately EXCLUDES the human 'Up 3 minutes' uptime text, which
# changes every minute and would flag a false positive; only fields that move
# on a REAL event are compared.
ll_snapshot(){
  docker ps -a --filter name=looksloop --format '{{.ID}}' 2>/dev/null \
    | while read -r c; do
        docker inspect -f '{{.Id}}|{{.Image}}|{{.Name}}|{{.RestartCount}}|{{.State.StartedAt}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$c" 2>/dev/null
      done | sort
}

[ "$(id -u)" -eq 0 ] || die "must run as root"
command -v docker >/dev/null 2>&1 || die "docker not found"
docker compose version >/dev/null 2>&1 || die "docker compose plugin not found"
command -v curl >/dev/null 2>&1 || die "curl not found"

# Neutralise any inherited compose context so the project is pinned.
unset DOCKER_HOST DOCKER_CONTEXT COMPOSE_FILE COMPOSE_PROFILES COMPOSE_ENV_FILES \
      COMPOSE_PATH_SEPARATOR COMPOSE_PROJECT_NAME
export COMPOSE_PROJECT_NAME=fahimni
export GIT_CONFIG_NOSYSTEM=1
export GIT_TERMINAL_PROMPT=0

# ── 0. preflight ────────────────────────────────────────────────────────────
step "preflight"
[ -f "$ENVF" ] || die "missing $ENVF"
OLD_TAG="$(grep -E '^IMAGE_TAG=' "$ENVF" | head -1 | cut -d= -f2- | tr -d '[:space:]' || true)"
[ -n "$OLD_TAG" ] || die "could not read IMAGE_TAG from $ENVF"
OLD_REL="$(readlink -f "$STACK/current" 2>/dev/null || true)"
[ -n "$OLD_REL" ] && [ -f "$OLD_REL/docker-compose.yml" ] \
  || die "could not resolve the current release from $STACK/current"
log "current tag  : $OLD_TAG"
log "current rel  : $OLD_REL"
log "brand commit : $SHA"
log "brand tag    : $NEW_TAG"

[ "$OLD_TAG" != "$NEW_TAG" ] || die "$NEW_TAG is already the pinned tag — nothing to do"

# Guarantee a rollback image exists for EVERY service we are about to replace.
# The services do not necessarily all run $OLD_TAG (the Google/CSP hotfix only
# re-tagged app+frontend, so scheduler/horizon still run the base release tag).
# If the tag is absent we tag whatever the running container actually uses, so
# the rollback path can always start all four services again.
step "rollback images (must exist for all ${#SERVICES[@]} services)"
for i in "${SERVICES[@]}"; do
  if docker image inspect "fahimni-$i:$OLD_TAG" >/dev/null 2>&1; then
    log "fahimni-$i:$OLD_TAG present"
    continue
  fi
  cid="$(docker ps -a --filter "name=^fahimni-$i-1\$" --format '{{.ID}}' 2>/dev/null | head -1)"
  if [ -n "$cid" ]; then
    img="$(docker inspect -f '{{.Config.Image}}' "$cid" 2>/dev/null || true)"
    if [ -n "$img" ] && docker image inspect "$img" >/dev/null 2>&1; then
      docker tag "$img" "fahimni-$i:$OLD_TAG" >/dev/null 2>&1 || true
      log "fahimni-$i:$OLD_TAG <- $img (from running container)"
    fi
  fi
  docker image inspect "fahimni-$i:$OLD_TAG" >/dev/null 2>&1 \
    || die "no rollback image for service '$i' (tag $OLD_TAG missing and no running container) — abort"
done
log "rollback images ready for all ${#SERVICES[@]} services"

avail="$(awk '/MemAvailable/{print int($2/1024)}' /proc/meminfo)"
log "MemAvailable : ${avail} MiB"
# Staging is never stopped (hard constraint), so the same bar the approved
# production tooling applies while Staging runs is used here.
[ "$avail" -ge 2500 ] || die "only ${avail} MiB RAM free — free memory first (Staging is not allowed to be stopped by this script)"
log "disk         : $(df -h / | awk 'NR==2{print $4" free ("$5" used)"}')"

step "LooksLoop baseline (read-only; must be identical afterwards)"
LL_BEFORE="$(ll_snapshot)"
if [ -n "$LL_BEFORE" ]; then
  printf '%s\n' "$LL_BEFORE" | sed 's/^/  /'
else
  # This host is the standalone Fahimni VPS: no LooksLoop containers exist, so
  # there is nothing to protect and the before/after comparison degrades to a
  # recorded no-op. Absence is RECORDED (not assumed), so a real appearance or
  # disappearance would still show up as a diff in step 10.
  log "  none on this host (standalone Fahimni — nothing to protect)"
fi

# ── 1. backup ───────────────────────────────────────────────────────────────
step "backing up the environment file"
mkdir -p "$BAKDIR"; chmod 700 "$BAKDIR" 2>/dev/null || true
chown fahimni:fahimni "$BAKDIR" 2>/dev/null || true
cp -p "$ENVF" "$ENV_BAK"
chmod 600 "$ENV_BAK"; chown fahimni:fahimni "$ENV_BAK" 2>/dev/null || true
log "$ENV_BAK"
{
  echo "# Fahimni brand rollout rollback note — $TS"
  echo "rollback_tag=$OLD_TAG"
  echo "rollback_release=$OLD_REL"
  echo "new_tag=$NEW_TAG"
  echo "new_release=$REL"
  echo "# DB note: the additive migration (assignments.lesson_id, NULLABLE) is NOT"
  echo "# reverted by a rollback. It is compatible with the previous release, whose"
  echo "# code never reads the column, so the rollback image keeps working."
  echo "# Approve/applied via FAHIMNI_ALLOW_DB_WRITE; reverted only manually + deliberately."
  echo "env_backup=$ENV_BAK"
  echo "# exact revert:"
  echo "cp $ENV_BAK $ENVF && chown fahimni:fahimni $ENVF && chmod 600 $ENVF"
  echo "cd $OLD_REL && IMAGE_TAG=$OLD_TAG docker compose --env-file $ENVF up -d --no-deps ${SERVICES[*]}"
  echo "# then reload nginx so it re-resolves the restored container addresses:"
  echo "cd $OLD_REL && docker compose --env-file $ENVF exec -T $NGINX_SVC nginx -t && docker compose --env-file $ENVF exec -T $NGINX_SVC nginx -s reload"
  echo "# images running before the change:"
  docker ps --format '{{.Names}}|{{.Image}}' 2>/dev/null | sort | sed 's/^/#   /' || true
  echo "# LooksLoop baseline:"
  printf '%s\n' "$LL_BEFORE" | sed 's/^/#   /'
} > "$NOTE"
chmod 600 "$NOTE"; chown fahimni:fahimni "$NOTE" 2>/dev/null || true
log "$NOTE"

# ── 2. fetch the approved release ───────────────────────────────────────────
step "fetching the approved brand commit from git"
# A failed or older run leaves the release tree owned by root (it is created by
# root), which would block a later non-root deploy and confuse git. Rebuild it
# from scratch in that case.
if [ -e "$REL" ]; then
  owner="$(stat -c '%U' "$REL" 2>/dev/null || echo '?')"
  if [ "$owner" != "fahimni" ]; then
    warn "leftover release $REL is owned by '$owner' — removing it and re-cloning"
    rm -rf "$REL"
  fi
fi
# git must trust the release tree regardless of who owns it: on a re-run the
# directory already exists and is owned by `fahimni`, while this script runs as
# root — without this, git aborts with "detected dubious ownership".
export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0='*'
if [ -d "$REL/.git" ]; then
  git -C "$REL" fetch --quiet origin \
    || die "git fetch failed — production is untouched"
else
  mkdir -p "$STACK/releases"
  git clone --branch "$BRANCH" --single-branch --quiet "$REPO_URL" "$REL" \
    || die "git clone failed — production is untouched"
fi
git -C "$REL" config core.fileMode false
# P0 guard: the clone/fetch is single-branch, so $SHA must be reachable from
# $BRANCH. A mismatch (e.g. $SHA lives on a sibling branch) must abort here with
# a clear message instead of a confusing checkout failure — or a silently stale
# object from a previous run.
release_sha_on_branch "$REL" "$SHA" "$BRANCH" \
  || die "$SHA is not reachable from branch '$BRANCH' — refusing to deploy. Set FAHIMNI_RELEASE_BRANCH to the branch that actually contains $SHA (and remove $REL so it re-clones if the current clone is pinned to another branch)."
git -C "$REL" checkout --detach --quiet "$SHA" || die "could not check out $SHA"
[ "$(git -C "$REL" rev-parse HEAD)" = "$SHA" ] || die "release HEAD is not $SHA"
git -C "$REL" merge-base --is-ancestor a85544e6d097572e23e7f42123746eb848431df2 HEAD \
  || die "$SHA does not descend from the live release (Phase A would be lost)"
[ -f "$REL/app/Support/PlatformBrand.php" ] && [ -f "$REL/frontend/src/lib/brand.ts" ] \
  || die "the checkout does not contain the brand sources — wrong commit"
# The release MUST carry the P0 Google/session BFF fix (PR #24). Rebuilding the
# frontend from a brand-only checkout would drop the public-origin fix and
# bring back «تعذر إنشاء الجلسة» after the Google callback.
if [ -f "$REL/frontend/src/lib/request-origin.ts" ] \
   && grep -q 'requestOrigin' "$REL/frontend/src/lib/request-origin.ts"; then
  :
else
  die "the release lacks the Google/session BFF fix (request-origin.ts) — refusing to deploy an older checkout"
fi
[ -z "$(git -C "$REL" -c core.fileMode=false status --porcelain)" ] \
  || die "release worktree is dirty — refusing to build (inspect it first)"
log "checked out $SHA (clean)"

# The compose file declares `env_file: .env` for every service, so `.env` must
# exist BEFORE any `docker compose` call or compose aborts on the missing file.
ln -sfn "$ENVF" "$REL/.env"
mkdir -p "$REL/certs"
[ "$ENVF" -ef "$REL/.env" ] || die ".env symlink is not the shared env file"

# The compose project must resolve to `fahimni` no matter what the environment
# or the env file carry — otherwise `up` could create a second, parallel stack.
eff="$(cd "$REL" && docker compose --env-file "$ENVF" config 2>/dev/null | sed -n 's/^name: //p' | head -n1)"
[ "$eff" = "fahimni" ] || die "effective compose project is '${eff:-<none>}' (expected fahimni)"
log "effective compose project: $eff"

# Ownership: the release is created by root, but the stack belongs to fahimni.
# Leaving root-owned files behind is what blocks the next non-root deploy.
chown -R fahimni:fahimni "$REL"
chown fahimni:fahimni "$STACK/releases" 2>/dev/null || true
log "release ownership set to fahimni:fahimni"

step "brand audit of user-visible release sources (the old name must be gone)"
# Scan only the trees that reach a user. The old name legitimately survives in
# docs/, in the deploy tooling/tests and in the two normalisation helpers — a
# detection grep is not a user-visible string and is never a failure.
offenders="$(brand_source_audit "$REL")"
if [ -n "$offenders" ]; then
  printf '%s\n' "$offenders" | sed 's/^/    /' >&2
  die "the old brand still appears in a user-visible release source listed above"
fi
log "old brand appears only inside the two intentional remapping helpers (docs/deploy/tests are not user-visible)"

# ── 3. build ────────────────────────────────────────────────────────────────
step "building images for $NEW_TAG (several minutes; production keeps running)"
( cd "$REL" && COMPOSE_PARALLEL_LIMIT=1 IMAGE_TAG="$NEW_TAG" \
    docker compose --env-file "$ENVF" build "${SERVICES[@]}" ) \
  || die "image build failed — production is untouched"
for i in "${SERVICES[@]}"; do
  docker image inspect "fahimni-$i:$NEW_TAG" >/dev/null 2>&1 || die "image fahimni-$i:$NEW_TAG missing after build"
done
log "4 images built"

# ── 4. verify the built artifacts ───────────────────────────────────────────
step "verifying the brand inside the built images"
docker run --rm --entrypoint php "fahimni-app:$NEW_TAG" -l /var/www/html/app/Support/PlatformBrand.php >/dev/null 2>&1 \
  || die "PlatformBrand.php fails php -l inside the image"
expect="$(snapshot "$REL/app/Support/PlatformBrand.php")"
got="$(docker run --rm --entrypoint sha256sum "fahimni-app:$NEW_TAG" /var/www/html/app/Support/PlatformBrand.php 2>/dev/null | cut -d' ' -f1 || true)"
[ "$expect" = "$got" ] || die "app image does not carry the brand helper (want $expect, got ${got:-none})"
log "app image carries the brand helper ($got)"

for f in frontend/public/logo.png frontend/public/og.png frontend/public/icon-maskable-512.png; do
  expect="$(snapshot "$REL/$f")"
  got="$(docker run --rm --entrypoint sha256sum "fahimni-frontend:$NEW_TAG" "/app/${f#frontend/}" 2>/dev/null | cut -d' ' -f1 || true)"
  [ -n "$expect" ] && [ "$expect" = "$got" ] \
    || die "frontend image asset ${f#frontend/} does not match the release (want $expect, got ${got:-none})"
  log "frontend ${f#frontend/} verified ($got)"
done

# ── 4b. database preflight (read-only, always runs) ────────────────────────
step "DB preflight (read-only): pending migrations vs the approved allowlist"
db_preflight "$REL"; pf=$?
[ "$pf" = 0 ] || die "DB preflight failed (unreadable status or an unapproved pending migration) — refusing to deploy; production is untouched"

# ── 4c. apply the approved migration(s) from the new image, BEFORE cutover ──
# The new code reads assignments.lesson_id unconditionally, so the column must
# exist before the new containers serve traffic. Applying it here — against the
# still-live old release — is safe: the column is nullable and the old code
# never references it (see the rollback note; a rollback keeps the column).
if [ "${FAHIMNI_ALLOW_DB_WRITE:-0}" = "1" ]; then
  [ "${FAHIMNI_DB_BACKUP_VERIFIED:-0}" = "1" ] \
    || die "DB writes are approved but no verified fresh backup was confirmed — set FAHIMNI_DB_BACKUP_VERIFIED=1 only after restoring a recent backup onto an isolated copy"
  step "applying the approved migration(s) from the new image (before cutover)"
  apply_approved_migrations "$REL" \
    || die "an approved migration failed BEFORE cutover — production containers and traffic are untouched"
else
  warn "DB writes not approved (FAHIMNI_ALLOW_DB_WRITE != 1) — the approved migration was NOT applied"
fi

# ── 4d. targeted permission change (additive only) ──────────────────────────
step "permission preflight (read-only): the expected cards.export diff"
ensure_cards_export_permission "$REL" 0 || warn "permission preflight could not run (continuing)"
if [ "${FAHIMNI_ALLOW_PERMISSION_WRITE:-0}" = "1" ]; then
  step "applying the targeted permission change (grant cards.export to Teacher)"
  ensure_cards_export_permission "$REL" 1 \
    || die "the targeted permission change failed (no permission was removed; re-run after fixing)"
else
  warn "permission writes not approved (FAHIMNI_ALLOW_PERMISSION_WRITE != 1) — no permission change applied"
fi

# ── 5. rollback helper ──────────────────────────────────────────────────────
# ── FAHIMNI_ROLLBACK_BEGIN ──────────────────────────────────────────────────
# (also extracted by deploy/tests/brand-rollout-nginx-test.sh)
rollback(){
  ROLLBACK_REQUIRED=0
  warn "ROLLING BACK to $OLD_TAG"
  install -m 600 -o fahimni -g fahimni "$ENV_BAK" "$ENVF" || warn "could not restore $ENVF from backup"
  ( cd "$OLD_REL" && IMAGE_TAG="$OLD_TAG" docker compose --env-file "$ENVF" \
      up -d --no-deps "${SERVICES[@]}" ) || warn "rollback 'up' failed"
  # The rollback re-creates the containers with new addresses too: reload nginx
  # or the restored build is served behind a stale upstream (still 502).
  reload_nginx "$OLD_REL" || warn "could not reload nginx during the rollback"
  local i
  for i in 1 2 3 4 5 6 7 8 9 10 11 12; do
    if [ "$(http_code /api/health/ready)" = "200" ] && [ "$(http_code /)" = "200" ]; then
      warn "rollback health: OK"; return 0
    fi
    sleep 5
  done
  warn "rollback health still failing — escalate: sudo bash /home/fahimni/production-deploy.sh"
  return 1
}
# ── FAHIMNI_ROLLBACK_END ────────────────────────────────────────────────────
ROLLBACK_REQUIRED=0
on_exit(){
  local rc=$?
  if [ "$ROLLBACK_REQUIRED" = 1 ]; then
    warn "unexpected exit with $NEW_TAG live — rolling back automatically"
    rollback || true
  fi
  # Any file root created inside the stack must not outlive this script.
  chown -R fahimni:fahimni "$REL" 2>/dev/null || true
  chown fahimni:fahimni "$STACK/releases" "$ENVF" 2>/dev/null || true
  exit "$rc"
}
trap on_exit EXIT

# ── 6. point the platform at the new identity ───────────────────────────────
step "switching the platform identity in $ENVF"
tmp="$(mktemp)"
sed -E \
  -e "s/^IMAGE_TAG=.*/IMAGE_TAG=$NEW_TAG/" \
  -e "s/^APP_NAME=.*/APP_NAME=\"منصة فهمني\"/" \
  -e "s/^MAIL_FROM_NAME=.*/MAIL_FROM_NAME=\"منصة فهمني\"/" \
  "$ENVF" > "$tmp"
grep -qE "^IMAGE_TAG=${NEW_TAG}$" "$tmp" || die "could not set IMAGE_TAG"
grep -qE '^APP_NAME="منصة فهمني"$' "$tmp" || die "could not set APP_NAME"
grep -qE '^MAIL_FROM_NAME="منصة فهمني"$' "$tmp" || die "could not set MAIL_FROM_NAME"
chmod 600 "$tmp"; chown fahimni:fahimni "$tmp" 2>/dev/null || true
install -m 600 -o fahimni -g fahimni "$tmp" "$ENVF"; rm -f "$tmp"
log "$(grep -E '^(IMAGE_TAG|APP_NAME|MAIL_FROM_NAME)=' "$ENVF" | tr '\n' ' ')"
ROLLBACK_REQUIRED=1

# ── 7. replace only the four application containers ─────────────────────────
step "replacing ONLY: ${SERVICES[*]}  ($NEW_TAG)"
( cd "$REL" && IMAGE_TAG="$NEW_TAG" docker compose --env-file "$ENVF" \
    up -d --no-deps "${SERVICES[@]}" ) \
  || { dump_diagnostics; rollback; die "docker compose up failed"; }

# The containers above were just re-created with NEW IPs. nginx still points at
# the old ones until it re-reads its config, so reload FIRST — otherwise every
# health probe below hits a stale upstream and a HEALTHY deploy would be rolled
# back on a phantom 502.
step "reloading nginx so it re-resolves the new container addresses"
reload_nginx "$REL" || warn "nginx reload failed — verifying with the health probe anyway"

step "waiting for core health (budget 180s)"
healthy=0
deadline=$(( SECONDS + 180 ))
while (( SECONDS < deadline )); do
  ok=1
  for p in /up /api/health/live /api/health/ready /; do
    [ "$(http_code "$p")" = "200" ] || ok=0
  done
  [ "$ok" = 1 ] && { healthy=1; break; }
  sleep 5
done
if [ "$healthy" != 1 ]; then
  dump_diagnostics
  rollback
  die "health checks did not pass within 180s"
fi
for p in /up /api/health/live /api/health/ready /; do
  log "$(printf '%-18s %s' "$p" "$(http_code "$p")")"
done

# ── 7b. readiness gate: containers, port, DNS ───────────────────────────────
step "container readiness, port and DNS (must pass before the deploy is accepted)"
if ! verify_readiness; then
  dump_diagnostics
  rollback
  die "the stack is not fully ready after the rollout"
fi

# ── 8. Phase A regression guard (Google + CSP must still be fixed) ──────────
step "CSP re-verification on $CSP_PAGE (Phase A must not regress)"
csp_ok=0; attempt=0
while [ "$attempt" -lt 6 ]; do
  attempt=$(( attempt + 1 ))
  # Headers and body MUST come from ONE request: the nonce is per-request.
  rh="$(mktemp)"; rb="$(mktemp)"
  curl -sS --max-time 20 -H "Host: $HOSTHDR" -D "$rh" -o "$rb" "$BASE$CSP_PAGE" 2>/dev/null || true
  hdr="$(tr -d '\r' < "$rh" 2>/dev/null || true)"
  body="$(cat "$rb" 2>/dev/null || true)"
  rm -f "$rh" "$rb"
  nonce="$(printf '%s' "$hdr" | grep -i '^content-security-policy:' | grep -oE "'nonce-[^']+'" | head -1 | sed "s/'nonce-//; s/'//" || true)"
  scripts="$(printf '%s' "$body" | grep -oE '<script' | wc -l | tr -d ' ' || true)"
  nonced="$(printf '%s' "$body" | grep -oE '<script[^>]*nonce="[^"]+"' | wc -l | tr -d ' ' || true)"
  hnonce="$(printf '%s' "$body" | grep -oE 'nonce="[^"]+"' | head -1 | sed 's/nonce="//; s/"//' || true)"
  prerender="$(printf '%s' "$hdr" | grep -ci '^x-nextjs-prerender:' || true)"
  log "attempt $attempt: nonce=${nonce:-<none>} scripts=$scripts nonced=$nonced html=${hnonce:-<none>} prerender=$prerender"
  if [ -n "$nonce" ] && [ "$scripts" -gt 0 ] && [ "$nonced" = "$scripts" ] \
     && [ "$hnonce" = "$nonce" ] && [ "$prerender" = "0" ]; then
    csp_ok=1; break
  fi
  sleep 5
done
[ "$csp_ok" = 1 ] || { dump_diagnostics; rollback; die "the CSP nonce fix regressed"; }
log "CSP still fixed: every script carries the exact header nonce, page is dynamic"

step "Google OAuth re-verification"
gjson="$(curl -sS --max-time 15 -H "Host: $HOSTHDR" "$BASE/api/v1/auth/google/redirect?role=teacher" 2>/dev/null || true)"
case "$gjson" in
  *'client_id='*) log "redirect endpoint returns a URL carrying client_id" ;;
  *) rollback; die "Google redirect did not return a client_id" ;;
esac

# ── 9. the actual deliverable: the new identity is served ───────────────────
step "brand verification on the live stack"
brand_fail=0
R_CODE=; R_OLD=; R_NEW=
check_page(){  # $1 = path ; sets R_CODE R_OLD R_NEW
  local tmp; tmp="$(mktemp)"
  R_CODE="$(curl -sS -o "$tmp" -w '%{http_code}' --max-time 20 -H "Host: $HOSTHDR" "$BASE$1" 2>/dev/null || printf '000')"
  R_OLD="$(grep -c -E 'Tadreesly|tadreesly' "$tmp" 2>/dev/null || true)"
  R_NEW="$(grep -c -E 'فهمني|Fahimni' "$tmp" 2>/dev/null || true)"
  rm -f "$tmp"
  log "$(printf '%-30s %s  new=%-3s old=%-3s' "$1" "$R_CODE" "$R_NEW" "$R_OLD")"
}

log "-- core surfaces (must be 200, branded, clean)"
for p in "${CORE_PAGES[@]}"; do
  check_page "$p"
  [ "$R_CODE" = "200" ] || { warn "$p returned $R_CODE"; brand_fail=1; }
  [ "$R_OLD" = "0" ]    || { warn "$p still serves the old brand"; brand_fail=1; }
  [ "$R_NEW" != "0" ]   || { warn "$p does not carry the new brand"; brand_fail=1; }
done

log "-- remaining public surfaces (must never leak the old brand)"
for p in "${MORE_PAGES[@]}"; do
  check_page "$p"
  [ "$R_OLD" = "0" ] || { warn "$p still serves the old brand"; brand_fail=1; }
  case "$R_CODE" in 5*) warn "$p returned $R_CODE"; brand_fail=1 ;; esac
done

log "-- the DB-driven platform name (the value the live settings row still holds)"
ps_json="$(curl -sS --max-time 15 -H "Host: $HOSTHDR" "$BASE/api/v1/platform-settings" 2>/dev/null || true)"
ps_name="$(platform_name_status "$ps_json")" || true
case "$ps_name" in
  ok)       log "platform-settings serves the new name (decoded JSON)" ;;
  legacy)   warn "platform-settings still exposes the old name"; brand_fail=1 ;;
  invalid)  warn "platform-settings returned a body that is not valid JSON or has no string name"; brand_fail=1 ;;
  empty)    warn "platform-settings returned an empty platform name"; brand_fail=1 ;;
  noparser) warn "no JSON parser (jq/python3/php) is available to verify the platform name"; brand_fail=1 ;;
  wrong:*)  warn "platform-settings serves a wrong name: ${ps_name#wrong:}"; brand_fail=1 ;;
  *)        warn "platform-settings verification did not produce a verdict"; brand_fail=1 ;;
esac

log "-- manifest, social card and the served logo"
check_page /manifest.webmanifest
[ "$R_CODE" = "200" ] || { warn "manifest returned $R_CODE"; brand_fail=1; }
[ "$R_OLD" = "0" ] || { warn "manifest still serves the old brand"; brand_fail=1; }
[ "$R_NEW" != "0" ] || { warn "manifest does not carry the new brand"; brand_fail=1; }

expect="$(snapshot "$REL/frontend/public/logo.png")"
served="$(mktemp)"
curl -sS --max-time 20 -H "Host: $HOSTHDR" -o "$served" "$BASE/logo.png" 2>/dev/null || true
got="$(snapshot "$served")"; rm -f "$served"
log "/logo.png expected=$expect served=$got"
[ -n "$expect" ] && [ "$expect" = "$got" ] \
  || { warn "the served /logo.png is not the approved logo from the release"; brand_fail=1; }
for p in /og.png /icon-192.png /icon-512.png /icon-maskable-512.png /apple-icon.png; do
  c="$(http_code "$p")"; log "$(printf '%-30s %s' "$p" "$c")"
  [ "$c" = "200" ] || { warn "$p returned $c"; brand_fail=1; }
done

log "-- browser tab title"
title="$(curl -sS --max-time 20 -H "Host: $HOSTHDR" "$BASE$CSP_PAGE" 2>/dev/null | grep -oE '<title>[^<]*</title>' | head -1 || true)"
log "${title:-<none>}"
case "$title" in *فهمني*) : ;; *) warn "the tab title does not carry the new name"; brand_fail=1 ;; esac

if [ "$brand_fail" != 0 ]; then
  dump_diagnostics
  rollback
  die "the new identity is not fully served — rolled back"
fi
log "BRAND OK — «منصة فهمني» is served everywhere checked"

# ── 10. LooksLoop must be untouched ─────────────────────────────────────────
step "LooksLoop after (must equal baseline)"
LL_AFTER="$(ll_snapshot)"
if [ "$LL_BEFORE" = "$LL_AFTER" ]; then
  if [ -z "$LL_BEFORE" ]; then
    log "none before and none now (standalone host — nothing to protect)"
  else
    log "unchanged"
  fi
else
  diff <(printf '%s\n' "$LL_BEFORE") <(printf '%s\n' "$LL_AFTER") || true
  warn "LooksLoop state changed — investigate"
fi

# ── 11. bookkeeping so the standard rollback tooling stays coherent ─────────
step "bookkeeping (current symlink + deployed tag)"
printf '%s\n' "$OLD_REL" > "$STACK/.previous-release"
printf '%s\n' "$OLD_TAG" > "$STACK/.previous-tag"
printf '%s\n' "$NEW_TAG" > "$STACK/.deployed-tag.new"
mv "$STACK/.deployed-tag.new" "$STACK/.deployed-tag"
ln -sfn "$REL" "$STACK/current"
chown fahimni:fahimni "$REL" "$STACK/.deployed-tag" "$STACK/.previous-tag" "$STACK/.previous-release" \
  "$STACK/releases" "$ENVF" 2>/dev/null || true
log "$(grep -E '^IMAGE_TAG=' "$ENVF")"
log "current -> $REL"
[ "$(readlink -f "$STACK/current")" = "$REL" ] || die "current symlink did not switch"

ROLLBACK_REQUIRED=0

# ── 12. no root-owned leftovers ─────────────────────────────────────────────
step "ownership check"
# Anything this script created as root inside the stack is handed back to the
# fahimni user, so a later non-root deploy or editor is never blocked by a
# root-owned file. `-h` also fixes the symlinks (chown follows them otherwise).
chown -R fahimni:fahimni "$STACK/releases" "$BAKDIR" 2>/dev/null || true
chown -h fahimni:fahimni "$STACK/current" "$STACK/.deployed-tag" \
      "$STACK/.previous-tag" "$STACK/.previous-release" 2>/dev/null || true
chown fahimni:fahimni "$ENVF" 2>/dev/null || true
rootfiles="$(find "$STACK" -user root -print 2>/dev/null | head -20 || true)"
if [ -n "$rootfiles" ]; then
  printf '%s\n' "$rootfiles" | sed 's/^/  still root-owned: /'
  warn "the paths above predate this run and are NOT part of the release tree"
else
  log "no root-owned files under $STACK"
fi
log "env file: $(stat -c '%U:%G %a' "$ENVF")"
log "release tree: $(stat -c '%U:%G' "$REL")  ($(find "$REL" -user root | wc -l | tr -d ' ') root-owned files inside)"

# ── 13. summary ─────────────────────────────────────────────────────────────
step "done"
cat <<EOF

  ✅ BRAND LIVE — «منصة فهمني» / Fahimni   (image tag: $NEW_TAG)

  Replaced exactly: ${SERVICES[*]}
  Reloaded: $NGINX_SVC (existing config; upstream addresses re-resolved — no 502)
  Untouched: DNS, Caddy, LooksLoop, Staging, the nginx configuration, redis,
             the generic migrate service, Freemius, prices, subscriptions and
             user data.

  Database: only allowlisted, additive migration(s) may be applied, from the
            new image, before the cutover, gated on FAHIMNI_ALLOW_DB_WRITE=1 +
            FAHIMNI_DB_BACKUP_VERIFIED=1. The cards.export permission fix is
            additive-only, gated on FAHIMNI_ALLOW_PERMISSION_WRITE=1. A rollback
            keeps the nullable column (compatible with the previous release).

  Verified live: ${#CORE_PAGES[@]} core pages + ${#MORE_PAGES[@]} public pages,
  the settings API, the web manifest, the served logo hash, the tab title,
  container readiness + port + DNS, the CSP nonce fix and the Google redirect.

  REVERT (restores the previous build and the previous env exactly):
    sudo bash -c 'cp $ENV_BAK $ENVF && chown fahimni:fahimni $ENVF && chmod 600 $ENVF'
    sudo bash -c 'cd $OLD_REL && IMAGE_TAG=$OLD_TAG docker compose --env-file $ENVF up -d --no-deps ${SERVICES[*]}'
    sudo bash -c 'cd $OLD_REL && docker compose --env-file $ENVF exec -T $NGINX_SVC nginx -t && docker compose --env-file $ENVF exec -T $NGINX_SVC nginx -s reload'

  Rollback note: $NOTE
EOF
