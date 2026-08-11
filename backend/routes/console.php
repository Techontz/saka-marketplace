<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Driven by a single cron entry:
|   * * * * * cd /var/www/saka && php artisan schedule:run >> /dev/null 2>&1
|
| Every task uses withoutOverlapping() so a slow run cannot stack on itself,
| and onOneServer() so a multi-node deployment runs each task once rather than
| once per node — the two failure modes that turn a scheduler into an incident.
|
*/

use Illuminate\Support\Facades\Schedule;

// Counters: buffered in Redis, folded into MySQL every minute. A missed run
// costs nothing — the next flush picks up everything still buffered.
Schedule::command('saka:counters:flush')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->runInBackground();

/*
 * Advertisement impressions, same buffering as the listing counters.
 *
 * A missed run costs nothing: the buffer is read-then-deleted, so whatever is
 * still in Redis is picked up by the next flush.
 */
Schedule::command('saka:ads:flush-impressions')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->runInBackground();

/*
 * Campaign statuses follow their dates.
 *
 * Every five minutes rather than hourly because this is what an advertiser sees
 * after booking a flight that starts today — an hour of a campaign still
 * reading "Scheduled" after its window opened generates a support ticket every
 * time. It does NOT gate serving, which reads the dates directly.
 */
Schedule::command('saka:ads:refresh-statuses')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

/*
 * Promotion requests nobody reviewed in time.
 *
 * Daily rather than per-minute: the unit is a DATE, so nothing changes between
 * one run and the next within a day. Early enough that an operator opening the
 * queue in the morning does not see requests they cannot act on.
 */
Schedule::command('saka:promotions:expire')
    ->dailyAt('00:20')
    ->withoutOverlapping(30)
    ->onOneServer();

// Expiry sweeper. Hourly rather than nightly so a listing does not stay live
// for most of a day past its expiry.
Schedule::command('saka:listings:expire')
    ->hourly()
    ->withoutOverlapping(30)
    ->onOneServer();

// Roll raw views into the daily table before anything reads it.
Schedule::command('saka:views:rollup')
    ->hourly()
    ->withoutOverlapping(30)
    ->onOneServer();

// Category/region listing counts are denormalised and rendered as "N Listings"
// in the category browser. Hourly rather than nightly: a seller who publishes
// in the morning should not see their vertical's count stay stale all day.
/*
 * District and ward coordinates are derived from what is located in them, so
 * they improve as the catalogue grows. Runs before the taxonomy recount for no
 * reason other than keeping the two location jobs together.
 */
Schedule::command('saka:locations:centroids')
    ->dailyAt('03:40')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('saka:taxonomy:recount')
    ->hourly()
    ->withoutOverlapping(30)
    ->onOneServer();

// Popularity depends on the rollup, so it runs after it, off-peak.
Schedule::command('saka:listings:popularity')
    ->dailyAt('02:15')
    ->withoutOverlapping(60)
    ->onOneServer();

// Pruning runs last and only after the rollup has had a full day to catch up.
Schedule::command('saka:views:prune')
    ->dailyAt('03:30')
    ->withoutOverlapping(120)
    ->onOneServer();

/*
 * Seller listing counters are maintained on the write path; this is the safety
 * net for drift, mirroring the taxonomy recount. The business directory reads
 * these numbers, so a stale one shows a business as having no stock.
 */
Schedule::command('saka:sellers:recount')
    ->dailyAt('02:45')
    ->withoutOverlapping(60)
    ->onOneServer();

/*
 * Media is polymorphic, so nothing cascades when an owner is force-deleted.
 * Without this, every purged listing leaves its photos and variants on disk
 * forever. Weekly and off-peak: it is a storage-hygiene job, not a hot path.
 */
Schedule::command('saka:media:prune-orphans')
    ->weeklyOn(0, '04:15')
    ->withoutOverlapping(120)
    ->onOneServer();

// Recovery for transient job failures; anything unrecognised is left alone.
Schedule::command('saka:queue:retry --hours=6')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Horizon's throughput/runtime graphs are built from these snapshots. Without
// it the dashboard renders but its Metrics tab is permanently empty, which is
// the tab you actually need during an incident.
Schedule::command('horizon:snapshot')->everyFiveMinutes()->onOneServer();

// Housekeeping.
Schedule::command('queue:prune-failed --hours=336')->daily()->onOneServer();
Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer();
Schedule::command('auth:clear-resets')->daily()->onOneServer();
