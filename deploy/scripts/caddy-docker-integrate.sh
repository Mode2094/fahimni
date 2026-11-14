#!/usr/bin/env bash
# Integrate the additive Fahimni vhosts into the existing LooksLoop Caddy
# *container* (Docker), without replacing its Caddyfile, restarting it, or
# touching its site blocks or certificates.
#
# Why this shape:
#   • The public TLS terminator is a container (`caddy:2-alpine`), not systemd.
#   • Its Caddyfile is bind-mounted read-only from an immutable release path and
#     contains no `import`, so nothing can be added to the host tree.
#   • Caddy exposes an admin API on localhost:2019 inside the container. The only
#     supported way to add configuration without editing the bind mount is to
#     reload a merged config through that API.
#
# How it works (idempotent):
#   1. ensure the shared reverse-proxy network exists and the Caddy container is
#      attached to it (a runtime `docker network connect`, never a restart);
#   2. read the container's live /etc/caddy/Caddyfile;
#   3. append the rendered Fahimni fragment (upstreams = nginx aliases on the
#      shared network) to form a merged config;
#   4. `caddy validate` the merged config inside the container;
#   5. `caddy reload` it (graceful; LooksLoop stays up; certs are reused).
#
# Persistence across a LooksLoop update: `docker network connect` and the
# in-memory reload are lost when the release recreates the container. Run
# `--reconcile` from a timer (see deploy/README.md §7) to re-apply them, and
# re-read the NEW release's Caddyfile each time so LooksLoop additions survive.
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

PROXY_DEFAULT="fahimni-proxy"
PROD_UPSTREAM_DEFAULT="fahimni-prod:80"
STAGING_UPSTREAM_DEFAULT="fahimni-staging:80"
BASE_PATH_DEFAULT="/etc/caddy/Caddyfile"
MERGED_PATH="/tmp/fahimni-merged.Caddyfile"   # inside the Caddy container
# A syntactically valid bcrypt string used ONLY for `--check` when no real hash
# is supplied (never applied). Not a secret.
# shellcheck disable=SC2016  # the $ are literal bcrypt salt/marker characters
DUMMY_HASH='$2a$12$R9h/cIPz0gi.URNNX3kh2OPST9/PgBkqquzi.Ss7KIUgO2t0jWMUW'

usage() {
  cat <<'EOF'
Usage: caddy-docker-integrate.sh MODE [options]

Modes:
  --check       render + validate the merged config inside the container (or
                against --base-file); change nothing
  --apply       ensure network/connection, install the merged config, reload
  --reconcile   idempotent re-apply for a timer; uses the fragment saved by
                --apply (no bcrypt hash needed)
  --status      print container/network/connection and last-applied state
  --rollback    reload the container's own Caddyfile and stop managing it

Options:
  --container NAME     Caddy container (default: auto-detect the caddy image
                       publishing 0.0.0.0:443)
  --network NAME       shared reverse-proxy network (default: fahimni-proxy)
  --source PATH        repo Fahimni fragment (default: deploy/caddy/fahimni.caddy)
  --base-file PATH     local base Caddyfile to merge against (offline --check)
  --base-path PATH     Caddyfile path inside the container (default: /etc/caddy/Caddyfile)
  --prod-upstream ADDR     default: fahimni-prod:80
  --staging-upstream ADDR  default: fahimni-staging:80
  --state-dir DIR      state/lock dir (default: /var/lib/fahimni)
  --yes                confirm mutating modes (--apply/--reconcile/--rollback)
  --no-connect         do not create/attach the shared network
  --disconnect         with --rollback: also detach Caddy from the network

Required environment:
  FAHIMNI_STAGING_BASIC_HASH  bcrypt hash from `caddy hash-password`
                              (required for --apply; optional for --check)
EOF
}

mode=""
container=""
network="$PROXY_DEFAULT"
source_file="$DEPLOY_DIR/caddy/fahimni.caddy"
base_file=""
base_path="$BASE_PATH_DEFAULT"
prod_upstream="$PROD_UPSTREAM_DEFAULT"
staging_upstream="$STAGING_UPSTREAM_DEFAULT"
state_dir="${FAHIMNI_STATE_DIR:-/var/lib/fahimni}"
confirm=0
do_connect=1
do_disconnect=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --check)            mode="check"; shift ;;
    --apply)            mode="apply"; shift ;;
    --reconcile)        mode="reconcile"; shift ;;
    --status)           mode="status"; shift ;;
    --rollback)         mode="rollback"; shift ;;
    --container)        container="${2:-}"; shift 2 ;;
    --network)          network="${2:-}"; shift 2 ;;
    --source)           source_file="${2:-}"; shift 2 ;;
    --base-file)        base_file="${2:-}"; shift 2 ;;
    --base-path)        base_path="${2:-}"; shift 2 ;;
    --prod-upstream)    prod_upstream="${2:-}"; shift 2 ;;
    --staging-upstream) staging_upstream="${2:-}"; shift 2 ;;
    --state-dir)        state_dir="${2:-}"; shift 2 ;;
    --yes)              confirm=1; shift ;;
    --no-connect)       do_connect=0; shift ;;
    --disconnect)       do_disconnect=1; shift ;;
    -h|--help)          usage; exit 0 ;;
    *) die "unknown argument: $1" ;;
  esac
done

[[ -n "$mode" ]] || { usage; exit 1; }
# reconcile reuses the fragment saved by --apply, so it needs no release tree.
if [[ "$mode" != "reconcile" ]]; then
  [[ -f "$source_file" ]] || die "source Caddy fragment not found: $source_file"
fi

# Offline check (--check --base-file) validates with a local caddy binary (or a
# throwaway caddy container) and needs no Docker daemon; every other mode talks
# to the Docker daemon.
if [[ "$mode" == "check" && -n "$base_file" ]]; then
  command -v caddy >/dev/null 2>&1 || require_cmd docker
else
  require_cmd docker
  docker info >/dev/null 2>&1 || die "Docker daemon is not reachable"
fi

for upstream in "$prod_upstream" "$staging_upstream"; do
  [[ "$upstream" =~ ^[A-Za-z0-9._-]+:[0-9]+$ ]] \
    || die "invalid upstream address: $upstream (expected host:port)"
done

rendered_fragment="$state_dir/fahimni.caddy.rendered"
state_file="$state_dir/caddy-integrate.state"

# ── helpers ──────────────────────────────────────────────────────────────────
detect_container() {
  docker ps --format '{{.Image}}\t{{.Names}}\t{{.Ports}}' \
    | awk -F'\t' '$1 ~ /(^|\/)caddy(:|@)/ && $3 ~ /0\.0\.0\.0:443->/ {print $2; exit}'
}

container_running() {
  [[ -n "$1" ]] && docker inspect -f '{{.State.Running}}' "$1" 2>/dev/null | grep -q true
}

network_exists() {
  docker network inspect "$1" >/dev/null 2>&1
}

container_on_network() {
  docker inspect -f '{{range $k,$v := .NetworkSettings.Networks}}{{$k}}{{"\n"}}{{end}}' \
    "$1" 2>/dev/null | grep -Fxq "$2"
}

resolve_container() {
  if [[ -z "$container" ]]; then
    container="$(detect_container || true)"
  fi
  [[ -n "$container" ]] \
    || die "could not auto-detect the Caddy container; pass --container NAME"
  container_running "$container" \
    || die "container '$container' is not running"
}

# docker exec must see the container's own env (ROOT_DOMAIN …); we only add the
# two Fahimni upstreams so the fragment's {$VAR:default} resolves deterministically.
env_args=(-e "FAHIMNI_PROD_UPSTREAM=$prod_upstream" -e "FAHIMNI_STAGING_UPSTREAM=$staging_upstream")

in_caddy() { docker exec -i "${env_args[@]}" "$container" "$@"; }

sha256_file() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | awk '{print $1}'
  else
    shasum -a 256 "$1" | awk '{print $1}'
  fi
}

read_base_config() {
  if [[ -n "$base_file" ]]; then
    cat "$base_file"
  else
    docker exec "$container" cat "$base_path"
  fi
}

container_image() {
  docker inspect -f '{{.Config.Image}}' "$container" 2>/dev/null
}

container_id() {
  docker inspect -f '{{.Id}}' "$container" 2>/dev/null
}

# The marker only ever appears because of our fragment (LooksLoop never uses it),
# so it tells us whether the *currently running* config still carries the merge.
live_config_has_marker() {
  docker exec "$container" wget -qO- http://127.0.0.1:2019/config/ 2>/dev/null \
    | grep -q 'fahimni\.me'
}

# Non-secret Caddy variables only — never dump the container's full env (it holds
# credentials). Used for offline validation in a throwaway container.
container_base_env_args() {
  local name val
  for name in ROOT_DOMAIN CONTROL_DOMAIN MIRROR_DOMAIN ACME_EMAIL \
              TRUSTED_PROXY_RANGES API_INTERNAL_URL; do
    val="$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$container" 2>/dev/null \
      | sed -n "s/^${name}=//p" | head -n1)"
    if [[ -n "$val" ]]; then
      printf -- '-e\n%s=%s\n' "$name" "$val"
    fi
  done
}

guard_no_duplicate_hosts() {
  local base="$1"
  if grep -Eq '^[^#]*(fahimni\.me|www\.fahimni\.me|api\.fahimni\.me|staging\.fahimni\.me|api-staging\.fahimni\.me)[[:space:],{]' <<<"$base"; then
    die "the base Caddyfile already defines a Fahimni host; resolve it manually before applying"
  fi
}

render_fragment() {
  local hash="$1"
  awk -v h="$hash" '{ gsub(/__STAGING_BCRYPT_HASH__/, h); print }' "$source_file"
}

hash_for_mode() {
  local hash="${FAHIMNI_STAGING_BASIC_HASH:-}"
  if [[ -z "$hash" ]]; then
    if [[ "$mode" == "check" ]]; then
      warn "FAHIMNI_STAGING_BASIC_HASH is not set; using a dummy hash for syntax-only validation"
      printf '%s' "$DUMMY_HASH"
      return 0
    fi
    die "FAHIMNI_STAGING_BASIC_HASH is not set (generate it with: caddy hash-password)"
  fi
  [[ "$hash" == \$2* && ${#hash} -ge 59 ]] \
    || die "FAHIMNI_STAGING_BASIC_HASH is not a bcrypt hash"
  printf '%s' "$hash"
}

ensure_network() {
  if [[ "$do_connect" -eq 0 ]]; then
    return 0
  fi
  if ! network_exists "$network"; then
    log "creating shared reverse-proxy network: $network"
    docker network create "$network" >/dev/null
  fi
  if ! container_on_network "$container" "$network"; then
    log "attaching $container to $network (runtime connect; no restart)"
    docker network connect "$network" "$container"
  fi
}

write_state() {
  local applied_hash="$1"
  install -d -m 700 "$state_dir"
  umask 077
  {
    printf 'container=%s\n' "$container"
    printf 'container_id=%s\n' "$(container_id)"
    printf 'network=%s\n' "$network"
    printf 'prod_upstream=%s\n' "$prod_upstream"
    printf 'staging_upstream=%s\n' "$staging_upstream"
    printf 'applied_at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf 'applied_hash=%s\n' "$applied_hash"
  } > "$state_file"
  chmod 600 "$state_file"
}

stored_field() {
  [[ -f "$state_file" ]] || return 1
  sed -n "s/^$1=//p" "$state_file"
}

stored_hash() { stored_field applied_hash; }

# ── mode: status ─────────────────────────────────────────────────────────────
if [[ "$mode" == "status" ]]; then
  resolve_container
  printf 'container:   %s (%s)\n' "$container" "$(docker inspect -f '{{.Config.Image}}' "$container" 2>/dev/null)"
  printf 'network:     %s (%s)\n' "$network" "$(network_exists "$network" && echo exists || echo missing)"
  if container_on_network "$container" "$network"; then
    printf 'connected:   yes\n'
  else
    printf 'connected:   no\n'
  fi
  printf 'container id:%s\n' "$(container_id)"
  if live_config_has_marker 2>/dev/null; then
    printf 'live vhosts: present\n'
  else
    printf 'live vhosts: absent\n'
  fi
  if [[ -f "$state_file" ]]; then
    printf 'state:       %s\n' "$state_file"
    sed 's/^/             /' "$state_file"
    printf 'fragment:    %s (%s)\n' "$rendered_fragment" \
      "$([[ -f "$rendered_fragment" ]] && echo present || echo missing)"
  else
    printf 'state:       not applied (run --apply)\n'
  fi
  exit 0
fi

# ── mode: rollback ───────────────────────────────────────────────────────────
if [[ "$mode" == "rollback" ]]; then
  [[ "$confirm" -eq 1 ]] || die "--rollback changes the running Caddy config; re-run with --yes"
  resolve_container
  log "reloading $container from its own $base_path (Fahimni vhosts removed)"
  in_caddy caddy reload --config "$base_path" --adapter caddyfile
  if [[ "$do_disconnect" -eq 1 ]] && container_on_network "$container" "$network"; then
    log "detaching $container from $network"
    docker network disconnect "$network" "$container" || warn "disconnect failed; detach manually"
  fi
  rm -f "$rendered_fragment" "$state_file"
  log "rollback done; LooksLoop serving its own config"
  exit 0
fi

# ── modes: check / apply / reconcile ─────────────────────────────────────────
if [[ "$mode" != "check" || -z "$base_file" ]]; then
  resolve_container
fi

if [[ "$mode" != "check" ]]; then
  [[ "$confirm" -eq 1 ]] || die "--$mode changes LooksLoop's Caddy and the network; re-run with --yes"
  install -d -m 700 "$state_dir" 2>/dev/null \
    || die "cannot create state dir $state_dir (run as root or set FAHIMNI_STATE_DIR)"
  acquire_lock "$state_dir/.caddy-integrate.lock"
  trap 'release_lock' EXIT
fi

work="$(mktemp -d "${TMPDIR:-/tmp}/fahimni-caddy.XXXXXX")"
trap 'rm -rf "$work"; release_lock 2>/dev/null || true' EXIT

# Determine the fragment to use.
if [[ "$mode" == "reconcile" ]]; then
  [[ -f "$rendered_fragment" ]] \
    || die "no saved fragment; run --apply once first (needs FAHIMNI_STAGING_BASIC_HASH)"
  fragment="$rendered_fragment"
else
  hash="$(hash_for_mode)"
  fragment="$work/fahimni.caddy"
  umask 077
  render_fragment "$hash" > "$fragment"
fi

if [[ "$mode" == "check" ]]; then
  base="$(read_base_config)" || die "could not read base Caddyfile"
  guard_no_duplicate_hosts "$base"
  merged="$work/merged.Caddyfile"
  { printf '%s\n\n' "$base"; printf '# ── Fahimni additive vhosts (merged preview) ──\n'; cat "$fragment"; } > "$merged"

  if [[ -n "$base_file" ]]; then
    if command -v caddy >/dev/null 2>&1; then
      caddy validate --config "$merged" --adapter caddyfile \
        || die "merged config is invalid (offline check)"
      log "merged config validates (offline, local caddy, base=$base_file)"
    else
      image="${CADDY_IMAGE:-caddy:2-alpine}"
      docker run --rm -v "$work:/w:ro" --entrypoint caddy "$image" \
        validate --config /w/merged.Caddyfile --adapter caddyfile \
        || die "merged config is invalid (offline check with $image)"
      log "merged config validates (offline, $image, base=$base_file)"
    fi
  else
    # Validate in a throwaway container using the exact same image and the
    # non-secret base variables — the running LooksLoop container is never
    # touched (read-only check).
    image="$(container_image)"
    [[ -n "$image" ]] || die "could not determine the Caddy image"
    base_env=()
    while IFS= read -r line; do base_env+=("$line"); done < <(container_base_env_args)
    docker run --rm -i "${base_env[@]}" "${env_args[@]}" -v "$work:/w:ro" \
      --entrypoint caddy "$image" validate --config /w/merged.Caddyfile --adapter caddyfile \
      || die "merged config is invalid (validated with $image)"
    log "merged config validates with $image (offline; LooksLoop container untouched)"
  fi
  log "check mode: nothing was changed"
  exit 0
fi

# apply / reconcile
# Capture the state BEFORE ensure_network attaches the container: a LooksLoop
# redeploy recreates the container (new id) and detaches it from the network, so
# we must compare against the pre-attach state, not the freshly attached one.
pre_connected=0
container_on_network "$container" "$network" && pre_connected=1
pre_id="$(container_id)"

ensure_network

base="$(read_base_config)" || die "could not read base Caddyfile from $container:$base_path"
guard_no_duplicate_hosts "$base"

merged="$work/merged.Caddyfile"
{
  printf '%s\n\n' "$base"
  printf '# ── Fahimni additive vhosts — managed by caddy-docker-integrate.sh ──\n'
  printf '# LooksLoop blocks above are preserved verbatim; regenerate, do not hand-edit.\n'
  cat "$fragment"
} > "$merged"

new_hash="$(sha256_file "$merged")"

if [[ "$mode" == "reconcile" ]]; then
  reason=""
  [[ "$pre_connected" -eq 1 ]] || reason="network was detached"
  if [[ -z "$reason" && "$pre_id" != "$(stored_field container_id 2>/dev/null || true)" ]]; then
    reason="LooksLoop container was recreated"
  fi
  if [[ -z "$reason" ]] && ! live_config_has_marker; then
    reason="running config no longer carries the Fahimni vhosts"
  fi
  if [[ -z "$reason" && "$(stored_hash 2>/dev/null || true)" == "$new_hash" ]]; then
    log "reconcile: network, container and config are current; nothing to do"
    exit 0
  fi
  log "reconcile: re-applying (${reason:-merged config changed})"
fi

docker exec -i "$container" sh -c "cat > $MERGED_PATH" < "$merged"
in_caddy caddy validate --config "$MERGED_PATH" --adapter caddyfile \
  || die "merged config is invalid; existing Caddy config left untouched"

log "reloading $container (graceful; LooksLoop and its certificates stay up)"
if ! in_caddy caddy reload --config "$MERGED_PATH" --adapter caddyfile; then
  warn "reload failed; restoring $container's own Caddyfile"
  in_caddy caddy reload --config "$base_path" --adapter caddyfile \
    || warn "manual Caddy recovery required: docker exec $container caddy reload --config $base_path"
  die "reload failed; previous configuration restored"
fi

# Persist the rendered fragment so a timer can reconcile without the hash.
if [[ "$mode" == "apply" ]]; then
  install -d -m 700 "$state_dir"
  install -m 600 "$fragment" "$rendered_fragment"
fi
write_state "$new_hash"

log "Fahimni vhosts are live in $container (merged $new_hash)"
log "verify LooksLoop and Fahimni HTTPS, then confirm certificates issued"