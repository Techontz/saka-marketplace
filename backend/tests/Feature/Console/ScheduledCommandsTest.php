<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Listing\Enums\ListingStatus;
use App\Jobs\GenerateImageVariants;
use App\Models\Listing;
use App\Services\Metrics\CounterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScheduledCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    // ---------------------------------------------------------------- expiry

    #[Test]
    public function the_sweeper_expires_only_listings_past_their_date(): void
    {
        $expired = Listing::factory()->published()->create(['expires_at' => now()->subDay()]);
        $live = Listing::factory()->published()->create(['expires_at' => now()->addMonth()]);
        $noExpiry = Listing::factory()->published()->create(['expires_at' => null]);

        $this->artisan('saka:listings:expire')->assertSuccessful();

        $this->assertSame(ListingStatus::Expired, $expired->fresh()->status);
        $this->assertSame(ListingStatus::Published, $live->fresh()->status);
        $this->assertSame(ListingStatus::Published, $noExpiry->fresh()->status);
    }

    #[Test]
    public function expiry_is_recorded_in_the_status_history(): void
    {
        $listing = Listing::factory()->published()->create(['expires_at' => now()->subDay()]);

        $this->artisan('saka:listings:expire')->assertSuccessful();

        // Goes through ListingStatusService, not a bulk UPDATE, so the audit
        // trail and search de-indexing both happen.
        $this->assertDatabaseHas('listing_status_histories', [
            'listing_id' => $listing->id,
            'from_status' => 'published',
            'to_status' => 'expired',
        ]);
    }

    #[Test]
    public function an_expired_listing_leaves_public_browse(): void
    {
        Listing::factory()->published()->create(['expires_at' => now()->subDay()]);

        $this->getJson('/api/v1/listings')->assertOk()->assertJsonCount(1, 'data');
        $this->artisan('saka:listings:expire')->assertSuccessful();
        $this->getJson('/api/v1/listings')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function dry_run_changes_nothing(): void
    {
        $listing = Listing::factory()->published()->create(['expires_at' => now()->subDay()]);

        $this->artisan('saka:listings:expire --dry-run')->assertSuccessful();

        $this->assertSame(ListingStatus::Published, $listing->fresh()->status);
    }

    // --------------------------------------------------------------- rollup

    #[Test]
    public function views_are_rolled_up_into_the_daily_table(): void
    {
        $listing = Listing::factory()->published()->create();

        foreach (['aa', 'bb', 'bb'] as $i => $ip) {
            DB::table('listing_views')->insert([
                'listing_id' => $listing->id,
                'ip_hash' => str_pad($ip, 64, (string) $i),
                'viewed_at' => now()->subHours(2),
            ]);
        }

        $this->artisan('saka:views:rollup')->assertSuccessful();

        $row = DB::table('listing_view_daily')->where('listing_id', $listing->id)->first();
        $this->assertSame(3, (int) $row->views);
        $this->assertSame(3, (int) $row->unique_views);
    }

    #[Test]
    public function the_rollup_is_idempotent(): void
    {
        $listing = Listing::factory()->published()->create();
        DB::table('listing_views')->insert([
            'listing_id' => $listing->id, 'ip_hash' => str_repeat('a', 64), 'viewed_at' => now(),
        ]);

        // A retried scheduler run must not double the numbers.
        $this->artisan('saka:views:rollup')->assertSuccessful();
        $this->artisan('saka:views:rollup')->assertSuccessful();

        $this->assertSame(1, DB::table('listing_view_daily')->count());
        $this->assertSame(1, (int) DB::table('listing_view_daily')->value('views'));
    }

    // --------------------------------------------------------------- prune

    #[Test]
    public function pruning_removes_only_rows_past_the_retention_window(): void
    {
        $listing = Listing::factory()->published()->create();

        DB::table('listing_views')->insert([
            ['listing_id' => $listing->id, 'ip_hash' => str_repeat('a', 64), 'viewed_at' => now()->subDays(120)],
            ['listing_id' => $listing->id, 'ip_hash' => str_repeat('b', 64), 'viewed_at' => now()->subDays(10)],
        ]);

        $this->artisan('saka:views:prune --days=90')->assertSuccessful();

        $this->assertSame(1, DB::table('listing_views')->count());
    }

    // ---------------------------------------------------------- popularity

    #[Test]
    public function popularity_reflects_engagement_and_recency(): void
    {
        $busy = Listing::factory()->published()->create(['published_at' => now()->subDay()]);
        $quiet = Listing::factory()->published()->create(['published_at' => now()->subDay()]);

        DB::table('listings')->where('id', $busy->id)
            ->update(['favorite_count' => 20, 'inquiry_count' => 10]);

        DB::table('listing_view_daily')->insert([
            'listing_id' => $busy->id, 'date' => now()->toDateString(), 'views' => 500, 'unique_views' => 400,
        ]);

        $this->artisan('saka:listings:popularity')->assertSuccessful();

        $this->assertGreaterThan(
            (float) $quiet->fresh()->popularity_score,
            (float) $busy->fresh()->popularity_score,
        );
    }

    #[Test]
    public function an_old_listing_decays_below_a_fresh_one_with_equal_engagement(): void
    {
        $old = Listing::factory()->published()->create(['published_at' => now()->subDays(365)]);
        $fresh = Listing::factory()->published()->create(['published_at' => now()->subDay()]);

        DB::table('listings')->whereIn('id', [$old->id, $fresh->id])
            ->update(['favorite_count' => 10, 'inquiry_count' => 5]);

        $this->artisan('saka:listings:popularity')->assertSuccessful();

        // Otherwise a year-old listing sits at the top of Trending forever.
        $this->assertGreaterThan(
            (float) $old->fresh()->popularity_score,
            (float) $fresh->fresh()->popularity_score,
        );
    }

    // ------------------------------------------------------------ counters

    #[Test]
    public function buffered_counters_are_folded_into_the_database(): void
    {
        $listing = Listing::factory()->published()->create();
        $counters = app(CounterService::class);

        $counters->increment('view_count', $listing->id, 5);
        $counters->increment('favorite_count', $listing->id, 2);

        // Nothing written yet — that is the point of buffering.
        $this->assertSame(0, $listing->fresh()->view_count);
        $this->assertSame(5, $counters->pending('view_count', $listing->id));

        $this->artisan('saka:counters:flush')->assertSuccessful();

        $this->assertSame(5, $listing->fresh()->view_count);
        $this->assertSame(2, $listing->fresh()->favorite_count);
        $this->assertSame(0, $counters->pending('view_count', $listing->id));
    }

    #[Test]
    public function a_net_negative_counter_clamps_at_zero(): void
    {
        $listing = Listing::factory()->published()->create();
        $counters = app(CounterService::class);

        $counters->decrement('favorite_count', $listing->id, 3);

        // favorite_count is UNSIGNED; without the clamp this throws.
        $this->artisan('saka:counters:flush')->assertSuccessful();

        $this->assertSame(0, $listing->fresh()->favorite_count);
    }

    #[Test]
    public function flushing_twice_does_not_double_apply(): void
    {
        $listing = Listing::factory()->published()->create();
        app(CounterService::class)->increment('view_count', $listing->id, 4);

        $this->artisan('saka:counters:flush')->assertSuccessful();
        $this->artisan('saka:counters:flush')->assertSuccessful();

        $this->assertSame(4, $listing->fresh()->view_count);
    }

    // --------------------------------------------------------- job recovery

    #[Test]
    public function only_replayable_failed_jobs_are_retried(): void
    {
        DB::table('failed_jobs')->insert([
            [
                'uuid' => (string) Str::uuid(),
                'connection' => 'redis', 'queue' => 'media',
                'payload' => json_encode(['displayName' => GenerateImageVariants::class]),
                'exception' => 'boom', 'failed_at' => now(),
            ],
            [
                'uuid' => (string) Str::uuid(),
                'connection' => 'redis', 'queue' => 'default',
                'payload' => json_encode(['displayName' => 'App\\Jobs\\SomeUnknownJob']),
                'exception' => 'boom', 'failed_at' => now(),
            ],
        ]);

        // Anything not known to be idempotent is left for a human.
        $this->artisan('saka:queue:retry --dry-run')
            ->expectsOutputToContain('Retried 1, skipped 1')
            ->assertSuccessful();
    }

    #[Test]
    public function old_failed_jobs_are_outside_the_retry_window(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis', 'queue' => 'media',
            'payload' => json_encode(['displayName' => GenerateImageVariants::class]),
            'exception' => 'boom', 'failed_at' => now()->subDays(5),
        ]);

        $this->artisan('saka:queue:retry --hours=6')
            ->expectsOutputToContain('No recent failed jobs')
            ->assertSuccessful();
    }
}
