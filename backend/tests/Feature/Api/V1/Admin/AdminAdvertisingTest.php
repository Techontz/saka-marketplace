<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Advertising\Enums\AdCampaignStatus;
use App\Domain\Advertising\Enums\AdPlacement;
use App\Domain\Identity\Enums\RoleSlug;
use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\Advertiser;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Advertising administration.
 *
 * The properties worth protecting here are all about MONEY and AUTHORITY:
 *
 *   - only an operator with `advertising.manage` gets in at all;
 *   - a campaign cannot go live by mass assignment — that is the difference
 *     between an edit and an invoice;
 *   - a campaign cannot go live with nothing to render;
 *   - a click destination is constrained to http/https, because it becomes an
 *     href on the marketplace and is supplied from outside the organisation;
 *   - the performance screen reports what the rollups actually contain.
 */
class AdminAdvertisingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function admin(): User
    {
        return User::factory()->create()->syncRoles([RoleSlug::Admin->value]);
    }

    private function advertiser(): Advertiser
    {
        return Advertiser::factory()->create(['name' => 'NMB Bank']);
    }

    // --------------------------------------------------------- authorisation

    #[Test]
    public function a_guest_cannot_reach_advertising_administration(): void
    {
        $this->getJson('/api/v1/admin/ad-campaigns')->assertUnauthorized();
    }

    #[Test]
    public function a_moderator_without_the_permission_is_refused(): void
    {
        // Moderators moderate listings. Booking inventory SAKA invoices for is
        // a different job, and the role matrix says so.
        $moderator = User::factory()->create()->syncRoles([RoleSlug::Moderator->value]);

        $this->actingAs($moderator)
            ->getJson('/api/v1/admin/ad-campaigns')
            ->assertForbidden();
    }

    #[Test]
    public function a_seller_cannot_create_a_campaign_for_themselves(): void
    {
        $seller = User::factory()->seller()->create();

        $this->actingAs($seller)
            ->postJson('/api/v1/admin/ad-campaigns', [
                'advertiser_uuid' => $this->advertiser()->uuid,
                'name' => 'Self-serve',
                'placement' => AdPlacement::HomepageHero->value,
            ])
            ->assertForbidden();
    }

    // -------------------------------------------------------------- campaigns

    #[Test]
    public function an_administrator_creates_a_campaign_as_a_draft(): void
    {
        $advertiser = $this->advertiser();
        $category = Category::query()->whereNull('parent_id')->firstOrFail();

        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/ad-campaigns', [
                'advertiser_uuid' => $advertiser->uuid,
                'name' => 'Home loans — Q3',
                'placement' => AdPlacement::ListingsInline->value,
                'starts_at' => now()->addDay()->toIso8601String(),
                'ends_at' => now()->addMonth()->toIso8601String(),
                'priority' => 20,
                'category_slugs' => [$category->slug],
            ])
            ->assertCreated();

        /*
         * DRAFT, whatever was asked for. A campaign with no creative cannot
         * render, so creating it live would mean a slot that is "active" and
         * blank.
         */
        $response->assertJsonPath('data.status', AdCampaignStatus::Draft->value);
        $response->assertJsonPath('data.priority', 20);
        $response->assertJsonPath('data.targeting.categories.0.slug', $category->slug);
    }

    #[Test]
    public function status_cannot_be_set_through_the_create_or_update_body(): void
    {
        $advertiser = $this->advertiser();
        $admin = $this->admin();

        // Create, trying to smuggle a live status in.
        $created = $this->actingAs($admin)
            ->postJson('/api/v1/admin/ad-campaigns', [
                'advertiser_uuid' => $advertiser->uuid,
                'name' => 'Sneaky',
                'placement' => AdPlacement::Footer->value,
                'status' => AdCampaignStatus::Active->value,
            ])
            ->assertCreated();

        $this->assertSame(AdCampaignStatus::Draft->value, $created->json('data.status'));

        // And again on update. "Fix a typo in the name" must never be able to
        // carry a status change that puts an unreviewed advert live.
        $uuid = $created->json('data.uuid');

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/ad-campaigns/{$uuid}", [
                'name' => 'Renamed',
                'status' => AdCampaignStatus::Active->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.status', AdCampaignStatus::Draft->value);
    }

    #[Test]
    public function a_campaign_cannot_go_live_without_an_active_creative(): void
    {
        $campaign = AdCampaign::factory()->status(AdCampaignStatus::Draft)->create();

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/ad-campaigns/{$campaign->uuid}/transition", [
                'status' => AdCampaignStatus::Active->value,
            ])
            ->assertStatus(422);

        $this->assertSame(AdCampaignStatus::Draft, $campaign->refresh()->status);
    }

    #[Test]
    public function an_inactive_creative_does_not_count_as_something_to_render(): void
    {
        $campaign = AdCampaign::factory()->status(AdCampaignStatus::Draft)->create();
        AdCreative::factory()->for($campaign, 'campaign')->inactive()->create();

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/ad-campaigns/{$campaign->uuid}/transition", [
                'status' => AdCampaignStatus::Active->value,
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function a_campaign_with_a_creative_goes_live_and_then_serves(): void
    {
        $campaign = AdCampaign::factory()
            ->placement(AdPlacement::ListingsTop)
            ->status(AdCampaignStatus::Draft)
            ->create();

        AdCreative::factory()->for($campaign, 'campaign')->create();

        // Draft: invisible to the marketplace.
        $this->getJson('/api/v1/ads?placement='.AdPlacement::ListingsTop->value)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/ad-campaigns/{$campaign->uuid}/transition", [
                'status' => AdCampaignStatus::Active->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', AdCampaignStatus::Active->value);

        // The whole point: the admin action changes what a visitor sees.
        $this->getJson('/api/v1/ads?placement='.AdPlacement::ListingsTop->value)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function pausing_takes_a_campaign_off_the_marketplace_immediately(): void
    {
        $campaign = AdCampaign::factory()->placement(AdPlacement::ListingsTop)->create();
        AdCreative::factory()->for($campaign, 'campaign')->create();

        $this->getJson('/api/v1/ads?placement='.AdPlacement::ListingsTop->value)->assertJsonCount(1, 'data');

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/ad-campaigns/{$campaign->uuid}/transition", [
                'status' => AdCampaignStatus::Paused->value,
            ])
            ->assertOk();

        // An operator pausing a campaign is usually on the phone to the
        // advertiser. It has to stop now, not at the next cron run.
        $this->getJson('/api/v1/ads?placement='.AdPlacement::ListingsTop->value)->assertJsonCount(0, 'data');
    }

    #[Test]
    public function expired_cannot_be_set_by_hand(): void
    {
        $campaign = AdCampaign::factory()->create();

        // Expired is what the SCHEDULE means. An operator who wants a campaign
        // stopped now wants Paused, which survives its window.
        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/ad-campaigns/{$campaign->uuid}/transition", [
                'status' => AdCampaignStatus::Expired->value,
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function a_campaign_cannot_be_moved_to_a_different_advertiser(): void
    {
        $campaign = AdCampaign::factory()->create();
        $other = Advertiser::factory()->create();

        // Re-pointing a campaign's billing after it has delivered would detach
        // the invoice from the delivery. Create a new campaign instead.
        $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/ad-campaigns/{$campaign->uuid}", [
                'advertiser_uuid' => $other->uuid,
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function an_end_date_before_the_start_date_is_rejected(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/ad-campaigns', [
                'advertiser_uuid' => $advertiser->uuid,
                'name' => 'Backwards',
                'placement' => AdPlacement::Footer->value,
                'starts_at' => now()->addMonth()->toIso8601String(),
                'ends_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function patching_only_the_end_date_is_still_checked_against_the_stored_start(): void
    {
        $campaign = AdCampaign::factory()->create([
            'starts_at' => now()->addMonth(),
            'ends_at' => now()->addMonths(2),
        ]);

        /*
         * `after:starts_at` only fires when both fields are present, so a PATCH
         * carrying just `ends_at` would slip past it and leave a window that
         * can never open — a campaign that silently never serves.
         */
        $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/ad-campaigns/{$campaign->uuid}", [
                'ends_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertStatus(422);
    }

    // -------------------------------------------------------------- creatives

    #[Test]
    public function a_creative_is_added_to_a_campaign(): void
    {
        $campaign = AdCampaign::factory()->create();

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/ad-campaigns/{$campaign->uuid}/creatives", [
                'headline' => 'Own a home in Dar es Salaam',
                'body' => 'Mortgages from 15% p.a.',
                'cta_label' => 'Check eligibility',
                'click_url' => 'https://www.nmbbank.co.tz/loans',
            ])
            ->assertCreated()
            ->assertJsonPath('data.headline', 'Own a home in Dar es Salaam')
            ->assertJsonPath('data.performance.impressions', 0)
            // Null, not 0.00 — nothing has been shown yet.
            ->assertJsonPath('data.performance.ctr', null);
    }

    #[Test]
    public function a_javascript_click_url_is_rejected(): void
    {
        $campaign = AdCampaign::factory()->create();

        // This string becomes an href on the marketplace and is supplied by
        // someone outside the organisation. `javascript:` here is stored XSS
        // with an invoice attached.
        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/ad-campaigns/{$campaign->uuid}/creatives", [
                'headline' => 'Totally normal advert',
                'click_url' => 'javascript:alert(document.cookie)',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function deactivating_the_last_creative_empties_the_slot(): void
    {
        $campaign = AdCampaign::factory()->placement(AdPlacement::Businesses)->create();
        $creative = AdCreative::factory()->for($campaign, 'campaign')->create();

        $this->getJson('/api/v1/ads?placement='.AdPlacement::Businesses->value)->assertJsonCount(1, 'data');

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/ad-creatives/{$creative->uuid}", ['is_active' => false])
            ->assertOk();

        $this->getJson('/api/v1/ads?placement='.AdPlacement::Businesses->value)->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------------------ performance

    #[Test]
    public function performance_reports_no_data_rather_than_a_chart_of_zeroes(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/advertising/performance')
            ->assertOk()
            // The flag the UI branches on. A marketplace that has sold no
            // advertising must read as "nothing to show", not as an
            // authoritative chart asserting delivery was flat at zero.
            ->assertJsonPath('data.has_data', false)
            ->assertJsonPath('data.totals.impressions', 0)
            ->assertJsonPath('data.totals.ctr', null);
    }

    #[Test]
    public function performance_reports_what_the_rollups_actually_contain(): void
    {
        $campaign = AdCampaign::factory()->placement(AdPlacement::ListingsTop)->create();
        $creative = AdCreative::factory()->for($campaign, 'campaign')->create();

        // Driven through the PUBLIC beacons, not by writing rollup rows — this
        // proves the admin screen reads the same data the marketplace writes.
        foreach (range(1, 3) as $ignored) {
            $this->postJson("/api/v1/ads/{$creative->uuid}/impression", [
                'placement' => AdPlacement::ListingsTop->value,
            ])->assertNoContent();
        }

        $this->postJson("/api/v1/ads/{$creative->uuid}/click", [
            'placement' => AdPlacement::ListingsTop->value,
        ])->assertNoContent();

        $this->artisan('saka:ads:flush-impressions')->assertSuccessful();

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/advertising/performance')
            ->assertOk();

        $response->assertJsonPath('data.has_data', true);
        $response->assertJsonPath('data.totals.impressions', 3);
        $response->assertJsonPath('data.totals.clicks', 1);
        $response->assertJsonPath('data.by_placement.0.placement', AdPlacement::ListingsTop->value);
        $response->assertJsonPath('data.top_campaigns.0.uuid', $campaign->uuid);
    }

    #[Test]
    public function the_options_endpoint_publishes_the_enums_the_form_needs(): void
    {
        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/advertising/options')
            ->assertOk();

        // The portal must never carry a second copy of these.
        $this->assertCount(count(AdPlacement::cases()), $response->json('data.placements'));
        $this->assertCount(count(AdCampaignStatus::cases()), $response->json('data.statuses'));
        $response->assertJsonPath('data.placements.0.aspect_ratio.desktop', AdPlacement::cases()[0]->aspectRatio()['desktop']);
    }
}
