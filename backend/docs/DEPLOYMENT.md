# SAKA Marketplace API — production deployment

Everything needed to run this API in production, and the reasoning behind the
choices that are not obvious. If something here contradicts a config file, the
config file is authoritative — please fix this document.

---

## 1. What this application requires

| Component | Version | Required? | Why |
|---|---|---|---|
| PHP | 8.3+ | yes | Typed enums, readonly promotion, `never` return type |
| MySQL | 8.0+ (9.3 tested) | yes | `FULLTEXT`, `CHECK` constraints, generated columns |
| Redis | 6+ | **yes** | cache, queue, sessions, rate limiting, permission cache, buffered counters |
| Supervisor | any | yes | keeps Horizon and the scheduler alive |
| Nginx/Caddy | any | yes | TLS termination and static media |
| S3-compatible object storage | — | for >1 web node | the `public` disk is per-node |

PHP extensions: `bcmath`, `gd`, `intl`, `mbstring`, `pdo_mysql`, `redis`, `zip`.

**Redis is not optional.** Six subsystems depend on it. The application
degrades rather than crashes when Redis is briefly unavailable —
`CounterService` falls back to writing counters directly to MySQL — but rate
limiting, sessions and the permission cache all stop working, and the
degradation is measurably slower under load. Treat a Redis outage as an
incident, not as a warning.

**SQLite is not supported, including for tests.** The schema uses `FULLTEXT`
indexes, `CHECK` constraints and generated columns; SQLite silently ignores or
rejects all three, so a green SQLite suite would prove nothing. CI runs against
MySQL 9.3 for exactly this reason.

---

## 2. First deployment

```bash
git clone <repo> /var/www/saka && cd /var/www/saka

cp .env.production.example .env
php artisan key:generate
$EDITOR .env                       # fill in every "CHANGE ME"

composer install --no-dev --optimize-autoloader

php artisan migrate --force
php artisan db:seed --force        # roles, permissions, taxonomy, locations

php artisan storage:link           # only when MEDIA_DISK=public

php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache

sudo cp deploy/supervisor/*.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
```

### The super-admin account

`UserSeeder` refuses to create the super-admin outside `local` unless
`SEED_ADMIN_PASSWORD` is set — it will not fall back to a guessable default.

Set it for the first deploy, sign in, change the password through the app, then
**remove the value from `.env` and re-run `php artisan config:cache`**. Leaving a
plaintext admin password in an environment file is the most common way a
correctly-built system gets compromised.

The `super_admin` role can never be granted through the API — not by an admin,
not by another super-admin. It is assignable only by the seeder or directly in
the database. This is deliberate: it is the one privilege escalation an
attacker with a compromised admin token cannot perform.

---

## 3. Routine deployments

```bash
DEPLOY_SECRET=$(openssl rand -hex 16) ./deploy/deploy.sh
```

The script's ordering is load-bearing:

1. **Preflight before maintenance mode.** PHP version, extensions, `APP_DEBUG`,
   database and Redis reachability are all checked while the site is still up,
   so a misconfigured box fails without an outage.
2. **`down` before `migrate`.** A request served between "schema changed" and
   "config re-cached" hits new columns with old cached config.
3. **`config:cache` before `horizon:terminate`.** Workers hold configuration in
   memory for the life of the process. Restart them first and the new workers
   boot on the *old* cached config.
4. **`up` only after the caches are warm.**

If the script fails it deliberately leaves maintenance mode **on**. Investigate,
then `php artisan up`.

### Rolling back

```bash
php artisan down
git checkout <previous-tag>
composer install --no-dev --optimize-autoloader
php artisan migrate:rollback --step=1        # only if this deploy migrated
php artisan optimize:clear && php artisan config:cache && php artisan route:cache
php artisan horizon:terminate
php artisan up
```

Every migration in this repository has a working `down()`, and CI verifies the
full `migrate → seed → rollback → migrate` cycle on every push. A rollback
leaves only the `migrations` table behind.

---

## 4. Queues

Three queues, deliberately separated by priority and resource profile:

| Queue | Jobs | Why it is separate |
|---|---|---|
| `default` | `NewInquiryNotification` | latency matters — a seller must not wait behind an image resize |
| `media` | `GenerateImageVariants` | CPU- and memory-hungry (512 MB, 180 s timeout) |
| `analytics` | `RecordListingView` | highest volume, lowest value per job; a backlog is harmless |

Horizon supervises all three (`config/horizon.php`). This is worth stating
explicitly because the framework's stock scaffold supervises only `default` —
under that config, `media` and `analytics` jobs are pushed to Redis
successfully and then never processed, with no error anywhere. A test
(`QueueReliabilityTest`) now asserts every queue a job targets has a supervisor.

**Run exactly one `horizon` process per host.** Horizon is itself a supervisor
that forks and scales workers; a second master doubles the worker count. Never
run `queue:work` alongside it — a stray worker consumes jobs Horizon cannot see
and its metrics silently under-report.

The production worker command, exactly as supervisor runs it
(`deploy/supervisor/saka-horizon.conf`, `numprocs=1`, `user=www-data`,
`stopwaitsecs=300`):

```
php /var/www/saka/artisan horizon
```

and the scheduler, on **one node only**
(`deploy/supervisor/saka-scheduler.conf`, `numprocs=1`):

```
php /var/www/saka/artisan schedule:work
```

Every scheduled task also carries `onOneServer()` and `withoutOverlapping()`, so
a second scheduler node cannot double-run a task — but that guard needs a
**shared** lock store. `CACHE_STORE=redis` (as in `.env.production.example`) is
what makes it work; leave it on the `database` default with more than one node
and the locks are per-node and useless.

### `retry_after` must exceed the longest worker timeout

`config/queue.php` sets `retry_after` to **210 s** against `supervisor-media`'s
**180 s** timeout. That ordering is load-bearing, not arbitrary: `retry_after` is
when Redis decides a reserved job was abandoned and re-queues it. Set below the
worker timeout — it was 90 s — Redis hands a slow 5 MB image resize to a second
worker while the first is still running it, and the same variant is generated
twice concurrently. Raise `retry_after` (`REDIS_QUEUE_RETRY_AFTER`) whenever a
supervisor timeout is raised.

### Retries and failure recovery

Both queued jobs are **idempotent by construction**, which is what makes
automatic retry safe:

- `RecordListingView` — the unique key `(listing_id, ip_hash, viewed_on)`
  enforces one counted view per client per day *in the database*. A replay
  violates the constraint and is absorbed. Doing this in application code would
  race under concurrency.
- `GenerateImageVariants` — overwrites its own output. A replay produces the
  same variants.

`saka:queue:retry` runs every 30 minutes and replays **only** these two classes,
and only jobs that failed within the last 6 hours. Everything else is left for a
human and logged as `queue.failed_jobs_need_review`. `queue:retry all` is a
blunt instrument: it replays poison messages that will fail again and jobs so
old their referenced rows are gone.

```bash
php artisan queue:failed                  # inspect
php artisan saka:queue:retry --dry-run    # what automation would replay
php artisan queue:retry <uuid>            # replay one by hand
```

### Monitoring

Horizon is at `/horizon`, gated on the `settings.manage` permission — held only
by `super_admin`. That is stricter than the rest of the admin surface on
purpose: Horizon renders raw job payloads, which carry inquiry bodies, seller
emails and phone numbers.

Horizon can alert when a queue's wait time exceeds the thresholds in
`config/horizon.waits`. Routing is intentionally not hard-coded — add it to
`app/Providers/HorizonServiceProvider::boot()`:

```php
Horizon::routeMailNotificationsTo('ops@saka.co.tz');
Horizon::routeSlackNotificationsTo(env('HORIZON_SLACK_WEBHOOK'), '#alerts');
```

---

## 5. The scheduler

One process (`deploy/supervisor/saka-scheduler.conf`) running
`php artisan schedule:work`. Supervisor is used instead of crontab so the
scheduler is restarted like everything else; the cron equivalent is in the
config file's header if you prefer it.

| Task | Cadence | Notes |
|---|---|---|
| `saka:counters:flush` | every minute | folds Redis-buffered view/favourite/inquiry counts into MySQL |
| `saka:listings:expire` | hourly | hourly, not nightly, so nothing stays live most of a day past expiry |
| `saka:views:rollup` | hourly | raw views → daily aggregate |
| `saka:listings:popularity` | 02:15 | depends on the rollup, so it runs after it |
| `saka:views:prune` | 03:30 | drops `listing_views` older than 90 days, batched |
| `saka:queue:retry --hours=6` | every 30 min | selective failed-job recovery |
| `horizon:snapshot` | every 5 min | without it Horizon's Metrics tab is permanently empty |
| `queue:prune-failed --hours=336` | daily | 14-day failed-job retention |
| `sanctum:prune-expired --hours=24` | daily | |
| `auth:clear-resets` | daily | |

Every task is wrapped in `withoutOverlapping()` and `onOneServer()`.

**Multi-host:** `onOneServer()` uses the shared Redis cache to elect one host
per task per minute, so run the scheduler on **every** app node. That is what
gives you failover. Do not designate a single "cron box" — it is a single point
of failure the framework already solved.

### Counters are eventually consistent

View, favourite and inquiry counts are buffered in a Redis hash and folded into
MySQL once a minute. A direct `UPDATE` on every view would serialise every
request for a popular listing behind a row lock on the hottest rows in the
table.

The practical consequence: a listing's view count can lag by up to 60 seconds.
This is a deliberate trade and the tests make it visible — they call
`flushCounters()` explicitly rather than asserting an immediate write.

---

## 6. Web server

```nginx
server {
    listen 443 ssl http2;
    server_name api.saka.co.tz;

    root /var/www/saka/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/api.saka.co.tz/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.saka.co.tz/privkey.pem;

    # Must be >= MEDIA_MAX_IMAGE_MB with headroom for multipart overhead, or
    # nginx rejects the upload with a 413 before PHP ever sees it — and the
    # application's own validation message never reaches the client.
    client_max_body_size 12M;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;

        # Longer than PHP's max_execution_time so a slow request produces a PHP
        # error you can read rather than an opaque 504.
        fastcgi_read_timeout 60s;
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

CORS is handled by the application (`config/cors.php`), driven by
`SAKA_FRONTEND_URL` and `SAKA_ADMIN_URL`. Do not add CORS headers in nginx as
well — duplicated `Access-Control-Allow-Origin` headers make browsers reject
the response entirely.

---

## 7. Health, metrics and logs

| Endpoint | Purpose | Use for |
|---|---|---|
| `GET /api/v1/health/live` | process is up | container liveness probe |
| `GET /api/v1/health/ready` | dependencies reachable | load-balancer readiness, deploy gate |
| `GET /api/v1/health` | detailed component status | humans |
| `GET /api/v1/metrics` | Prometheus exposition | scraping |

Use `live` for liveness and `ready` for readiness — pointing liveness at `ready`
means a brief database blip restarts every healthy container and turns a small
outage into a large one.

`/api/v1/metrics` requires a matching `X-Metrics-Token` header and returns
**404** — not 401 — when the token is absent or wrong, so the endpoint is
indistinguishable from a route that does not exist.

Logs go to `stderr` (`LOG_STACK=stderr`) so the container runtime or systemd
journal owns shipping and rotation. Every log line and every error response
carries a `request_id`, which is also returned as the `X-Request-Id` header —
that is the join key between a user's bug report and the logs.

Slow queries (>`SLOW_QUERY_MS`) and slow requests (>`SLOW_REQUEST_MS`) are
logged with their SQL and route.

---

## 8. Security checklist before going live

- [ ] `APP_DEBUG=false` and `APP_ENV=production` (the deploy script enforces both)
- [ ] `APP_KEY` generated, and never rotated without re-encrypting existing data
- [ ] Database user has DML rights only; migrations run under a separate DDL account
- [ ] Redis requires a password and is not bound to a public interface
- [ ] `SESSION_SECURE_COOKIE=true` and TLS enforced
- [ ] `METRICS_TOKEN` set to 32+ random bytes
- [ ] `SEED_ADMIN_PASSWORD` removed from `.env` after first sign-in
- [ ] `SAKA_FRONTEND_URL` / `SAKA_ADMIN_URL` are exact origins — no wildcards
- [ ] Media bucket is private; files are served through signed URLs or a CDN
- [ ] Identity documents live on a private disk (they do — signed 10-minute URLs)
- [ ] `TRUSTED_PROXIES` set when behind an ALB, Cloudflare or nginx — unset, every
      request appears to come from the proxy, which collapses the per-IP rate
      limiters into one shared budget and makes every `ip_hash` identical
- [ ] Backups: nightly `mysqldump`, tested by restoring, not just by running
- [ ] Point-in-time recovery: binary logging on with `binlog_expire_logs_seconds`
      at least as long as the gap between full dumps. A nightly dump alone means
      the worst case is losing a day of listings, bookings and inquiries
- [ ] `storage/app/private` is backed up **separately from the database and from
      the media bucket** — see below

### Identity documents do not go to S3

`MediaUploadService` pins the `Document` collection to the `local` disk
regardless of `FILESYSTEM_DISK`, so NIDA scans land in `storage/app/private` on
the API node. That is deliberate — the bucket policy is one mistake away from
public, and this removes the class of mistake entirely — but it has two
consequences the operator owns:

1. **It is single-node.** With two API nodes behind a load balancer, a document
   uploaded to node A is not on node B, and review 404s roughly half the time.
   Either keep document handling on one node (sticky routing for
   `/api/v1/seller/verifications` and `/api/v1/admin/verifications`) or mount
   shared storage at `storage/app/private`.
2. **It is outside the media bucket's backup.** `mysqldump` captures the
   verification rows; the scans themselves need their own encrypted backup.

Everything else — listing photos, avatars, logos and their WebP variants —
follows `MEDIA_DISK` and goes to S3 as normal.

### Design decisions worth knowing before you change something

- **404 over 403.** A resource the caller may not see returns 404, so existence
  is never disclosed. If you "fix" this to return 403, you reintroduce an
  enumeration oracle.
- **Publishing requires a verified phone.** Enforced at both the route and the
  domain layer. Browsing stays open to guests.
- **Admins cannot act on themselves.** No self-ban, no self-demotion — that is
  how an organisation locks itself out of its own platform.
- **`is_public` on settings is not writable through the API.** Exposing it
  would let an admin leak a private key by accident.
- **Attribute `code` is immutable.** It is a public filter key
  (`?attributes[beds]=3`); changing it breaks every saved search and bookmark.

---

## 9. Scaling, in the order the constraints actually bite

1. **Move media to S3.** The `public` disk is per-node, so a second web node
   serves 404s for images uploaded to the first. Set `MEDIA_DISK=s3`; no code
   changes.
2. **Separate the queue host.** Horizon and PHP-FPM competing for CPU shows up
   as request latency long before either saturates on its own.
3. **Add a read replica.** Browse and search are overwhelmingly read-heavy.
4. **Move search to Meilisearch.** `SearchDriver` is already an interface with a
   `MySqlFullTextDriver` implementation, and `search_document` is maintained on
   every write. Adding a driver is the whole job — MySQL `FULLTEXT` becomes the
   bottleneck somewhere around a few hundred thousand listings with heavy
   faceting.
5. **Shard `listing_views`.** Pruning at 90 days holds this for a long time,
   but it is the table that grows fastest.

---

## 10. Troubleshooting

**Jobs are queued but never run.** Check `php artisan horizon:status` first,
then that a supervisor exists for the queue in `config/horizon.php` — a queue
with no supervisor accepts jobs silently and never runs them.

**A job vanishes with no error and no failed_jobs row.** Look for a `queue()`
method on the job class. Laravel's dispatcher treats it as *custom queueing
logic* and calls it instead of pushing. Use `$this->onQueue()` in the
constructor. `QueueReliabilityTest` asserts this.

**Config changes have no effect.** `php artisan config:cache` was not re-run, or
the workers were not restarted. Cached config is read once at boot.

**Permission changes have no effect.** The spatie permission cache is keyed by
guard: `php artisan permission:cache-reset`.

**Search returns nothing for a keyword that obviously matches.** InnoDB
maintains `FULLTEXT` indexes at `COMMIT`. Inside an uncommitted transaction the
index has not been updated yet — this is why the test suite uses
`DatabaseTruncation` rather than `RefreshDatabase` for search tests.

**View counts look stale.** They are buffered in Redis and flushed once a
minute. `php artisan saka:counters:flush` forces it.

**A listing is still live past its expiry.** The sweeper is hourly. Run
`php artisan saka:listings:expire` and check the scheduler is alive with
`php artisan schedule:list`.
