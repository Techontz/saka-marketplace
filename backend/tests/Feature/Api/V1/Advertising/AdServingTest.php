<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Advertising;

use App\Domain\Advertising\Enums\AdCampaignStatus;
use App\Domain\Advertising\Enums\AdPlacement;
use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\Advertiser;
use App\Models\Category;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which advertisements a page is allowed to show.
 *
 * The properties that matter commercially, and each of them is a way the system
 * could quietly cheat somebody:
 *
 *   - an expired or unstarted campaign must not serve, EVEN IF its status
 *     column still says active, because that column is a cache the scheduler
 *     maintains and a cron outage must not become a billing incident;
 *   - a capped campaign stops at its cap;
 *   - targeting is inherited down the category tree, because that is what an
 *     advertiser who bought "property" believes they bought;
 *   - a targeted campaign does NOT leak onto untargeted pages;
 *   - priority decides, and ties break deterministically;
 *   - nothing private about the campaign reaches the public payload.
 */
class AdServingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** A live campaign in `$placement` with one active creative. */
    private function liveCampaign(
        AdPlacement $placement = AdPlacement::ListingsInline,
        array $campaignState = [],
    ): AdCampaign {
        $campaign = AdCampaign::factory()
            ->for(Advertiser::factory())
            ->placement($placement)
            ->create($campaignState);

        AdCreative::factory()->for($campaign, 'campaign')->create();

        return $campaign->refresh();
    }

    private function fetch(AdPlacement $placement, array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/ads?'.http_build_query(
            array_merge(['placement' => $placement->value], $query),
        ));
    }

    // ------------------------------------------------------------ eligibility

    #[Test]
    public function a_live_campaign_is_served(): void
    {
        $campaign = $this->liveCampaign();

        $this->fetch(AdPlacement::ListingsInline)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $campaign->creatives()->first()->uuid);
    }

    #[Test]
    public function an_empty_placement_returns_an_empty_list_not_a_404(): void
    {
        // No inventory sold against a slot is the NORMAL state. A 404 here
        // would light up error tracking on a perfectly healthy site.
        $this->fetch(AdPlacement::HomepageHero)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_campaign_whose_window_has_closed_is_not_served_even_while_its_status_says_active(): void
    {
        $campaign = AdCampaign::factory()
            ->placement(AdPlacement::ListingsTop)
            ->expiredWindow()
            ->status(AdCampaignStatus::Active) // the stale cache the cron would have fixed
            ->create();

        AdCreative::factory()->for($campaign, 'campaign')->create();

        $this->fetch(AdPlacement::ListingsTop)->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_campaign_whose_window_has_not_opened_is_not_served(): void
    {
        $campaign = AdCampaign::factory()
            ->placement(AdPlacement::ListingsTop)
            ->futureWindow()
            ->status(AdCampaignStatus::Active)
            ->create();

        AdCreative::factory()->for($campaign, 'campaign')->create();

        $this->fetch(AdPlacement::ListingsTop)->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_paused_campaign_is_not_served(): void
    {
        $campaign = AdCampaign::factory()
            ->placement(AdPlacement::ListingsTop)
            ->status(AdCampaignStatus::Paused)
            ->create();

        AdCreative::factory()->for($campaign, 'campaign')->create();

        $this->fetch(AdPlacement::ListingsTop)->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_campaign_that_has_hit_its_impression_cap_stops_serving(): void
    {
        $reached = AdCampaign::factory()
            ->placement(AdPlacement::ListingsTop)
            ->capped(cap: 1_000, delivered: 1_000)
            ->create();
        AdCreative::factory()->for($reached, 'campaign')->create();

        $this->fetch(AdPlacement::ListingsTop)->assertOk()->assertJsonCount(0, 'data');

        // One impression short of the cap is still eligible — the boundary is
        // the interesting half of this rule.
        $under = AdCampaign::factory()
            ->placement(AdPlacement::ListingsTop)
            ->capped(cap: 1_000, delivered: 999)
            ->create();
        AdCreative::factory()->for($under, 'campaign')->create();

        $this->fetch(AdPlacement::ListingsTop)->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_campaign_with_no_active_creative_is_skipped_rather_than_rendered_empty(): void
    {
        $campaign = AdCampaign::factory()->placement(AdPlacement::ListingsTop)->create();
        AdCreative::factory()->for($campaign, 'campaign')->inactive()->create();

        // A real state: an administrator deactivates a creative to swap the
        // artwork. The slot must collapse, not reserve space for nothing.
        $this->fetch(AdPlacement::ListingsTop)->assertOk()->assertJsonCount(0, 'data');
    }

    // -------------------------------------------------------------- targeting

    #[Test]
    public function a_campaign_targeting_a_parent_category_serves_on_its_children(): void
    {
        $parent = Category::query()->whereNull('parent_id')->firstOrFail();
        $child = Category::query()->where('parent_id', $parent->id)->firstOrFail();

        $campaign = $this->liveCampaign(AdPlacement::CategoryPage);
        $campaign->categories()->attach($parent->id);

        // Somebody who buys the property vertical expects to appear on
        // apartments. Anything else is a refund conversation.
        $this->fetch(AdPlacement::CategoryPage, ['category' => $child->slug])
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_targeted_campaign_does_not_serve_on_an_unrelated_category(): void
    {
        $roots = Category::query()->whereNull('parent_id')->take(2)->get();
        $this->assertCount(2, $roots, 'The catalogue seed needs at least two verticals.');

        $campaign = $this->liveCampaign(AdPlacement::CategoryPage);
        $campaign->categories()->attach($roots[0]->id);

        $this->fetch(AdPlacement::CategoryPage, ['category' => $roots[1]->slug])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_targeted_campaign_does_not_serve_on_a_page_with_no_category_context(): void
    {
        $category = Category::query()->firstOrFail();

        $campaign = $this->liveCampaign(AdPlacement::HomepageStrip);
        $campaign->categories()->attach($category->id);

        // An ad bought against "vehicles" has not been bought against
        // everything, so a page with no category shows only untargeted stock.
        $this->fetch(AdPlacement::HomepageStrip)->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function an_untargeted_campaign_serves_everywhere(): void
    {
        $this->liveCampaign(AdPlacement::HomepageStrip);
        $category = Category::query()->firstOrFail();

        $this->fetch(AdPlacement::HomepageStrip)->assertJsonCount(1, 'data');
        $this->fetch(AdPlacement::HomepageStrip, ['category' => $category->slug])->assertJsonCount(1, 'data');
    }

    #[Test]
    public function region_targeting_is_respected(): void
    {
        $regions = Region::query()->take(2)->get();
        $this->assertCount(2, $regions, 'The location seed needs at least two regions.');

        $campaign = $this->liveCampaign(AdPlacement::ListingsTop);
        $campaign->regions()->attach($regions[0]->id);

        $this->fetch(AdPlacement::ListingsTop, ['region' => $regions[0]->slug])->assertJsonCount(1, 'data');
        $this->fetch(AdPlacement::ListingsTop, ['region' => $regions[1]->slug])->assertJsonCount(0, 'data');
    }

    // --------------------------------------------------------------- ordering

    #[Test]
    public function higher_priority_wins_an_exclusive_placement(): void
    {
        // The hero shows one campaign. Two are eligible; the paid-up one must
        // win every single time, not most of the time.
        $low = $this->liveCampaign(AdPlacement::HomepageHero, ['priority' => 1]);
        $high = $this->liveCampaign(AdPlacement::HomepageHero, ['priority' => 99]);

        $response = $this->fetch(AdPlacement::HomepageHero)->assertOk()->assertJsonCount(1, 'data');

        $response->assertJsonPath('data.0.uuid', $high->creatives()->first()->uuid);
        $this->assertNotSame($low->creatives()->first()->uuid, $response->json('data.0.uuid'));
    }

    #[Test]
    public function the_rotation_shows_the_least_delivered_creative_first(): void
    {
        $campaign = AdCampaign::factory()->placement(AdPlacement::ListingsTop)->create();

        AdCreative::factory()->for($campaign, 'campaign')->withImpressions(500)->create();
        $behind = AdCreative::factory()->for($campaign, 'campaign')->withImpressions(10)->create();

        // Least-shown-first, so the split converges on even without any
        // scheduling — and, unlike random rotation, is deterministic.
        $this->fetch(AdPlacement::ListingsTop)
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $behind->uuid);
    }

    // --------------------------------------------------------------- payload

    #[Test]
    public function the_public_payload_never_exposes_commercial_detail(): void
    {
        $campaign = $this->liveCampaign(AdPlacement::ListingsTop, [
            'priority' => 42,
            'impression_cap' => 5_000,
        ]);
        $campaign->advertiser()->update(['contact_email' => 'billing@advertiser.example']);

        $body = $this->fetch(AdPlacement::ListingsTop)->assertOk()->json('data.0');

        // The disclosure a visitor is owed.
        $this->assertArrayHasKey('headline', $body);
        $this->assertArrayHasKey('click_url', $body);

        // What SAKA sold, for how much, and how it is doing — none of a
        // competitor's business, and all of it pollable from the marketplace
        // if it were serialised here.
        $encoded = json_encode($body, JSON_THROW_ON_ERROR);

        foreach (['priority', 'impression_cap', 'impressions_count', 'clicks_count', 'billing@advertiser.example'] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $encoded);
        }
    }

    #[Test]
    public function an_unknown_placement_is_rejected(): void
    {
        $this->getJson('/api/v1/ads?placement=not-a-real-slot')
            ->assertStatus(422);
    }

    #[Test]
    public function an_unknown_category_slug_degrades_to_no_context_rather_than_failing(): void
    {
        $this->liveCampaign(AdPlacement::ListingsTop);

        // A stale or mistyped slug must not 422 the page's advert away — the
        // marketplace still has to render.
        $this->fetch(AdPlacement::ListingsTop, ['category' => 'no-such-category'])
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
