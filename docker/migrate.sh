#!/bin/sh
# One-shot schema + seed for the Fahimni app image. This is the `migrate`
# compose service; it runs once per deploy and must exit non-zero on ANY
# failure so a broken deploy can never look successful.
#
# Rules:
#   • `set -e` — a failing migrate / plans:sync / seed / count probe aborts.
#   • seeding happens ONLY when the users table is empty (fresh database), so the
#     super-admin credentials are never re-applied on a normal redeploy;
#   • a count probe that fails or returns a non-number is fatal (never silently
#     treated as "fresh", which would re-seed).
set -eu

log() { printf '[migrate] %s\n' "$*"; }

log "running pending migrations"
php artisan migrate --force

log "syncing subscription plans"
php artisan plans:sync

log "checking whether this is a fresh database"
users="$(php artisan tinker --execute='echo \App\Models\User::count();')"

case "$users" in
  '' | *[!0-9]*)
    printf '[migrate] ERROR: could not read a user count (got "%s"); refusing to seed\n' "$users" >&2
    exit 1
    ;;
esac

if [ "$users" = "0" ]; then
  log "fresh database: seeding initial data"
  php artisan db:seed --force
else
  log "database already seeded ($users users): skipping seed"
fi

log "done"
