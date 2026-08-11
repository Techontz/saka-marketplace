<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Advertising;

use App\Domain\Advertising\Enums\AdCampaignStatus;
use App\Domain\Advertising\Enums\AdPlacement;
use App\Models\AdCampaign;
use App\Models\AdClick;
use App\Models\AdCreative;
use App\Services\Advertising\AdMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Counting delivery honestly.
 *
 * These numbers are what advertisers are invoiced against, so the tests are
 * mostly about NOT over-counting and about the totals agreeing with the rows
 * they are derived from.
 */
class AdMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private AdCreative $creative;

    protected function setUp(): void
    {
        parent::setUp();

        $campaign = AdCampaign::factory()->placement(AdPlacement::ListingsTop)->create();
        $this->creative = AdCreative::factory()->for($campaign, 'campaign')->create();

        // The buffer is a real Redis hash on DB 15 (see phpunit.xml). Left over
        // from a previous test it would make counts non-deterministic.
        Redis::del('counters:ad_impressions');
    }

    // ----------------------------------------------------------- impressions

    #[Test]
    public function serving_an_advert_does_not_by_itself_count_an_impression(): void
    {
        $this->getJson('/api/v1/ads?placement='.AdPlacement::ListingsTop->value)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        app(AdMetricsService::class)->flushImpressions();

        /*
         * THE point of the serve/beacon split. A page renders its slots
         * server-side including ones below the fold that nobody scrolls to;
         * counting on serve would bill for those, and the figure would be
         * indistinguishable from a real one.
         */
        $this->assertSame(0, $this->creative->refresh()->impressions_count);
    }

    #[Test]
    public function the_beacon_counts_a_viewable_impression_and_flushes_to_the_rollup(): void
    {
        $this->postJson("/api/v1/ads/{$this->creative->uuid}/impression", [
            'placement' => AdPlacement::ListingsTop->value,
        ])->assertNoContent();

        // Buffered in Redis, not yet in MySQL — that is the whole design.
        $this->assertSame(0, $this->creative->refresh()->impressions_count);

        $result = app(AdMetricsService::class)->flushImpressions();

        $this->assertSame(1, $result['impressions']);
        $this->assertSame(1, $this->creative->refresh()->impressions_count);
        $this->assertSame(1, $this->creative->campaign->refresh()->impressions_count);

        $this->assertDatabaseHas('ad_impressions_daily', [
            'ad_creative_id' => $this->creative->id,
            'placement' => AdPlacement::ListingsTop->value,
            'date' => now()->toDateString(),
            'impressions' => 1,
        ]);
    }

    #[Test]
    public function a_second_flush_on_the_same_day_accumulates_rather_than_replacing(): void
    {
        $metrics = app(AdMetricsService::class);
        $placement = AdPlacement::ListingsTop->value;

        $this->postJson("/api/v1/ads/{$this->creative->uuid}/impression", ['placement' => $placement]);
        $metrics->flushImpressions();

        $this->postJson("/api/v1/ads/{$this->creative->uuid}/impression", ['placement' => $placement]);
        $this->postJson("/api/v1/ads/{$this->creative->uuid}/impression", ['placement' => $placement]);
        $metrics->flushImpressions();

        /*
         * The flush runs once a minute all day against the same
         * creative/placement/date row. An assignment instead of an increment
         * would leave the row holding only the last minute — a report that
         * looks plausible and is wrong by two orders of magnitude.
         */
        $this->assertDatabaseHas('ad_impressions_daily', [
            'ad_creative_id' => $this->creative->id,
            'date' => now()->toDateString(),
            'impressions' => 3,
        ]);

        $this->assertSame(3, $this->creative->refresh()->impressions_count);
    }

    #[Test]
    public function flushing_an_empty_buffer_is_a_no_op(): void
    {
        $result = app(AdMetricsService::class)->flushImpressions();

        $this->assertSame(['rows' => 0, 'impressions' => 0], $result);
        $this->assertDatabaseCount('ad_impressions_daily', 0);
    }

    #[Test]
    public function a_beacon_for_an_unknown_creative_is_accepted_silently(): void
    {
        // Fire-and-forget from an already-rendered page: a 404 would surface as
        // a console error on a visitor's screen for an advert since deleted.
        $this->postJson('/api/v1/ads/00000000-0000-0000-0000-000000000000/impression', [
            'placement' => AdPlacement::ListingsTop->value,
        ])->assertNoContent();
    }

    #[Test]
    public function a_beacon_without_a_placement_is_rejected(): void
    {
        // The placement is denormalised onto the rollup row, so an absent one
        // would write an empty ENUM and corrupt the report.
        $this->postJson("/api/v1/ads/{$this->creative->uuid}/impression", [])
            ->assertStatus(422);
    }

    // ---------------------------------------------------------------- clicks

    #[Test]
    public function a_click_is_recorded_immediately_and_individually(): void
    {
        $this->postJson("/api/v1/ads/{$this->creative->uuid}/click", [
            'placement' => AdPlacement::ListingsTop->value,
        ])->assertNoContent();

        // No flush: clicks are rare, billable, and written synchronously.
        $this->assertSame(1, $this->creative->refresh()->clicks_count);
        $this->assertSame(1, $this->creative->campaign->refresh()->clicks_count);
        $this->assertDatabaseCount('ad_clicks', 1);
    }

    #[Test]
    public function a_click_stores_a_hashed_client_not_a_raw_address(): void
    {
        $this->postJson("/api/v1/ads/{$this->creative->uuid}/click", [
            'placement' => AdPlacement::ListingsTop->value,
        ])->assertNoContent();

        $click = AdClick::query()->firstOrFail();

        $this->assertNotNull($click->ip_hash);
        $this->assertSame(64, strlen($click->ip_hash), 'Expected a sha256 hex digest.');

        // Enough to spot one machine clicking four hundred times; not enough to
        // identify a person, which an ad click does not justify collecting.
        $this->assertStringNotContainsString('127.0.0.1', $click->ip_hash);
    }

    #[Test]
    public function the_click_through_rate_is_null_before_anything_is_shown(): void
    {
        $campaign = $this->creative->campaign;

        // Null, not 0.0 — "no data" and "shown and never clicked" are different
        // facts and an advertiser reads them very differently.
        $this->assertNull($campaign->clickThroughRate());

        $campaign->forceFill(['impressions_count' => 200, 'clicks_count' => 5])->save();

        $this->assertSame(2.5, $campaign->refresh()->clickThroughRate());
    }

    // ------------------------------------------------------------- scheduler

    #[Test]
    public function the_scheduler_expires_and_activates_campaigns_but_leaves_human_decisions_alone(): void
    {
        $finished = AdCampaign::factory()->expiredWindow()->status(AdCampaignStatus::Active)->create();
        $opening = AdCampaign::factory()->status(AdCampaignStatus::Scheduled)->create([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addMonth(),
        ]);

        // Paused with a window that has closed. The clock must NOT touch it:
        // silently expiring a paused campaign means re-dating it by hand before
        // it could ever resume.
        $paused = AdCampaign::factory()->expiredWindow()->status(AdCampaignStatus::Paused)->create();
        $draft = AdCampaign::factory()->status(AdCampaignStatus::Draft)->create();

        $this->artisan('saka:ads:refresh-statuses')->assertSuccessful();

        $this->assertSame(AdCampaignStatus::Expired, $finished->refresh()->status);
        $this->assertSame(AdCampaignStatus::Active, $opening->refresh()->status);
        $this->assertSame(AdCampaignStatus::Paused, $paused->refresh()->status);
        $this->assertSame(AdCampaignStatus::Draft, $draft->refresh()->status);
    }

    #[Test]
    public function the_flush_command_runs(): void
    {
        $this->postJson("/api/v1/ads/{$this->creative->uuid}/impression", [
            'placement' => AdPlacement::ListingsTop->value,
        ]);

        $this->artisan('saka:ads:flush-impressions')->assertSuccessful();

        $this->assertSame(1, $this->creative->refresh()->impressions_count);
    }
}
