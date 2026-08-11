#!/usr/bin/env bash
#
# SAKA — deploy a new release.
#
#   ./deploy/deploy.sh
#
# Ordering here is not cosmetic. Two rules drive it:
#
#   1. Take maintenance mode BEFORE migrating, and drop it only once the caches
#      are rebuilt. A request served between "schema changed" and "code cached"
#      hits new columns with old cached config.
#
#   2. Restart the queue AFTER caching config. Horizon workers hold their
#      config in memory for the life of the process; restarting first means the
#      new workers boot on the OLD cached config.
#
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/saka}"
PHP="${PHP:-php}"

cd "$APP_DIR"

log() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
fail() { printf '\n\033[1;31m!!  %s\033[0m\n' "$*" >&2; exit 1; }

# `artisan down` renders a 503 with a Retry-After. The secret lets you keep
# testing the new release yourself while everyone else sees the maintenance
# page.
cleanup() {
  if [ -f storage/framework/down ]; then
    log "Deploy failed — leaving maintenance mode ON deliberately."
    printf 'Investigate, then run: %s artisan up\n' "$PHP"
  fi
}
trap cleanup ERR

# ---------------------------------------------------------------- preflight
log "Preflight"

[ -f .env ] || fail ".env is missing."
grep -q '^APP_KEY=base64:' .env || fail "APP_KEY is not set. Run: $PHP artisan key:generate"
grep -q '^APP_DEBUG=false' .env || fail "APP_DEBUG must be false in production."
grep -q '^APP_ENV=production' .env || fail "APP_ENV must be production."

# Laravel's stock fallback in config/mail.php is hello@example.com. Shipped
# unchanged, every password reset and inquiry notification is sent From an
# address nobody controls: the mail is dropped by SPF/DKIM at most providers,
# and the few that deliver it put an obvious forgery in the customer's inbox.
# Checked here rather than at runtime because the failure is silent — the queue
# reports the job as sent.
#
# `if`, not `grep ... && fail`: under `set -e` the && form exits the script on
# the SUCCESS path, because a grep that finds nothing returns 1.
grep -q '^MAIL_FROM_ADDRESS=' .env \
  || fail "MAIL_FROM_ADDRESS is not set; it would fall back to hello@example.com."
if grep -q '^MAIL_FROM_ADDRESS=.*example\.com' .env; then
  fail "MAIL_FROM_ADDRESS is still the example default. Set a real sending address."
fi

$PHP -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' \
  || fail "PHP 8.3+ required, found $($PHP -r 'echo PHP_VERSION;')"

for ext in bcmath gd intl mbstring pdo_mysql redis; do
  $PHP -m | grep -qi "^${ext}$" || fail "Missing PHP extension: ${ext}"
done

# Fail now rather than half way through, while the site is still up.
# migrate:status, not db:monitor — db:monitor reports the CONNECTION COUNT and
# succeeds against an unreachable host in some configurations.
$PHP artisan migrate:status >/dev/null 2>&1 || fail "Cannot reach the database."

# Bootstrapped inline rather than through `artisan tinker`, so the preflight
# does not depend on a package that a future `composer remove laravel/tinker`
# or a --no-dev layout could take away.
$PHP -r '
  require "vendor/autoload.php";
  $app = require "bootstrap/app.php";
  $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  Illuminate\Support\Facades\Redis::connection()->ping();
' >/dev/null 2>&1 \
  || fail "Cannot reach Redis. Cache, queue, sessions and rate limiting all depend on it."

log "Maintenance mode on"
# No --render: there is no custom errors/503 view in this repo, and passing a
# view name that does not exist makes `down` itself fail. DEPLOY_SECRET, when
# set, gives you a bypass URL (${APP_URL}/${DEPLOY_SECRET}) so you can smoke-test
# the new release before the public sees it.
if [ -n "${DEPLOY_SECRET:-}" ]; then
  $PHP artisan down --retry=60 --secret="${DEPLOY_SECRET}"
else
  $PHP artisan down --retry=60
fi

# ---------------------------------------------------------------- release
log "Installing dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

log "Running migrations"
# --force is required non-interactively; --step so a rollback can undo this
# deploy's migrations only, not the previous one's as well.
$PHP artisan migrate --force --step

log "Rebuilding caches"
# clear before cache: a stale cached file survives `config:cache` if the config
# directory has not changed in a way the cacher notices.
$PHP artisan optimize:clear
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan event:cache
$PHP artisan view:cache

# Application caches keyed by taxonomy/listing data. Cheap to rebuild, and
# stale entries here are exactly what makes a deploy "work on my machine".
log "Flushing application cache"
$PHP artisan cache:clear

log "Restarting queue workers"
# Horizon finishes the job in flight, then exits; supervisor starts it again
# on the new code. `queue:restart` alone is not enough for Horizon.
$PHP artisan horizon:terminate

log "Maintenance mode off"
$PHP artisan up

# ---------------------------------------------------------------- verify
log "Post-deploy checks"

$PHP artisan about --only=environment

if $PHP artisan migrate:status | grep -q 'Pending'; then
  fail "Pending migrations remain after deploy."
fi

# A readiness probe that checks the dependencies, not just that PHP responds.
APP_URL=$(grep -E '^APP_URL=' .env | cut -d= -f2-)
if ! curl -fsS --max-time 10 "${APP_URL}/api/v1/health/ready" >/dev/null; then
  fail "Readiness probe failed at ${APP_URL}/api/v1/health/ready"
fi

log "Deployed successfully."
