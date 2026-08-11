<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Advertising;

use App\Domain\Advertising\Enums\AdCampaignStatus;
use App\Domain\Advertising\Enums\AdPlacement;
use App\Domain\Advertising\Enums\PromotionRequestStatus;
use App\Domain\Identity\Enums\RoleSlug;
use App\Models\AdCampaign;
use App\Models\Category;
use App\Models\Listing;
use App\Models\PromotionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Vendor promotion requests.
 *
 * The whole feature exists to let vendors buy visibility WITHOUT handing them
 * control of what the marketplace serves, so most of these tests are about the
 * boundary between the two:
 *
 *   - a vendor can only ever promote something they own;
 *   - a vendor cannot reach another vendor's requests, in either direction;
 *   - nothing a vendor does puts anything on the site;
 *   - approval mints a DRAFT campaign, because approving and publishing are two
 *     decisions and Phase 11A keeps them apart;
 *   - approval re-verifies everything, because time passes in the queue.
 *
 * And, throughout: no state anywhere claims a payment that did not happen.
 */
class PromotionRequestTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function vendorWithListing(): array
    {
        $vendor = User::factory()->seller()->create();
        $listing = Listing::factory()->published()->ownedBy($vendor)->create();

        return [$vendor, $listing];
    }

    private function admin(): User
    {
        return User::factory()->create()->syncRoles([RoleSlug::Admin->value]);
    }

    /** A draft request with artwork, ready to submit. */
    private function draftFor(User $vendor, Listing $listing): PromotionRequest
    {
        return PromotionRequest::factory()
            ->forListing($listing)
            ->status(PromotionRequestStatus::Draft)
            ->withArtwork()
            ->create(['user_id' => $vendor->getKey()]);
    }

    // ------------------------------------------------------------ submission

    #[Test]
    public function a_guest_cannot_create_a_promotion_request(): void
    {
        $this->postJson('/api/v1/seller/promotions', [])->assertUnauthorized();
    }

    #[Test]
    public function a_vendor_creates_a_promotion_request_as_a_draft(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();

        $response = $this->actingAs($vendor)
            ->postJson('/api/v1/seller/promotions', [
                'promotable_type' => 'listing',
                'promotable_uuid' => $listing->uuid,
                'placement' => AdPlacement::ListingsInline->value,
                'requested_start' => now()->addDay()->toDateString(),
                'requested_end' => now()->addWeek()->toDateString(),
                'headline' => 'Modern two-bedroom apartment in Masaki',
                'body' => 'Sea view, secure parking.',
                'cta_label' => 'View listing',
            ])
            ->assertCreated();

        // Draft, not pending: artwork cannot arrive in a JSON body, so a
        // request that went straight to the queue would be unreviewable.
        $response->assertJsonPath('data.status', PromotionRequestStatus::Draft->value);
        $response->assertJsonPath('data.promoted.type', 'listing');
        $response->assertJsonPath('data.status_label', 'Draft');
    }

    #[Test]
    public function the_wire_never_exposes_a_php_class_name(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();
        $promotion = $this->draftFor($vendor, $listing);

        $body = $this->actingAs($vendor)
            ->getJson("/api/v1/seller/promotions/{$promotion->uuid}")
            ->assertOk()
            ->json();

        // `promotable_type` holds a FQCN in the database, which is fine for
        // storage and would leak the namespace layout on the wire.
        $this->assertStringNotContainsString('App\\Models', json_encode($body, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function a_vendor_cannot_promote_another_vendors_listing(): void
    {
        [$vendor] = $this->vendorWithListing();
        [, $theirListing] = $this->vendorWithListing();

        // 404, not 403: a 403 would confirm the uuid names a real listing.
        $this->actingAs($vendor)
            ->postJson('/api/v1/seller/promotions', [
                'promotable_type' => 'listing',
                'promotable_uuid' => $theirListing->uuid,
                'placement' => AdPlacement::ListingsInline->value,
                'requested_start' => now()->addDay()->toDateString(),
                'requested_end' => now()->addWeek()->toDateString(),
                'headline' => 'Not mine to sell',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('promotion_requests', 0);
    }

    #[Test]
    public function an_unpublished_listing_cannot_be_promoted(): void
    {
        $vendor = User::factory()->seller()->create();
        $draft = Listing::factory()->ownedBy($vendor)->create(['published_at' => null]);

        // Paying to advertise a draft sends traffic to a 404.
        $this->actingAs($vendor)
            ->postJson('/api/v1/seller/promotions', [
                'promotable_type' => 'listing',
                'promotable_uuid' => $draft->uuid,
                'placement' => AdPlacement::ListingsInline->value,
                'requested_start' => now()->addDay()->toDateString(),
                'requested_end' => now()->addWeek()->toDateString(),
                'headline' => 'Still a draft',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function the_homepage_hero_is_not_offered_to_vendors(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();

        // The most valuable unit on the site, sold directly. A self-service
        // request must not compete for the slot an agency has committed to.
        $this->actingAs($vendor)
            ->postJson('/api/v1/seller/promotions', [
                'promotable_type' => 'listing',
                'promotable_uuid' => $listing->uuid,
                'placement' => AdPlacement::HomepageHero->value,
                'requested_start' => now()->addDay()->toDateString(),
                'requested_end' => now()->addWeek()->toDateString(),
                'headline' => 'Front page please',
            ])
            ->assertStatus(422);

        $options = $this->actingAs($vendor)
            ->getJson('/api/v1/seller/promotions/options')
            ->assertOk()
            ->json('data.placements');

        $offered = array_column($options, 'value');
        $this->assertNotContains(AdPlacement::HomepageHero->value, $offered);
    }

    #[Test]
    public function a_vendor_cannot_book_dates_in_the_past(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();

        $this->actingAs($vendor)
            ->postJson('/api/v1/seller/promotions', [
                'promotable_type' => 'listing',
                'promotable_uuid' => $listing->uuid,
                'placement' => AdPlacement::ListingsInline->value,
                'requested_start' => now()->subWeek()->toDateString(),
                'requested_end' => now()->addWeek()->toDateString(),
                'headline' => 'Time travel',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function an_end_date_before_the_start_is_rejected(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();

        $this->actingAs($vendor)
            ->postJson('/api/v1/seller/promotions', [
                'promotable_type' => 'listing',
                'promotable_uuid' => $listing->uuid,
                'placement' => AdPlacement::ListingsInline->value,
                'requested_start' => now()->addMonth()->toDateString(),
                'requested_end' => now()->addDay()->toDateString(),
                'headline' => 'Backwards',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function a_request_without_artwork_cannot_be_submitted(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();

        $promotion = PromotionRequest::factory()
            ->forListing($listing)
            ->status(PromotionRequestStatus::Draft)
            ->create(['user_id' => $vendor->getKey()]);

        // Told now, not two days later by a rejection.
        $this->actingAs($vendor)
            ->postJson("/api/v1/seller/promotions/{$promotion->uuid}/submit")
            ->assertStatus(422);

        $this->assertSame(PromotionRequestStatus::Draft, $promotion->refresh()->status);
    }

    #[Test]
    public function submitting_moves_a_draft_into_the_review_queue(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();
        $promotion = $this->draftFor($vendor, $listing);

        $this->actingAs($vendor)
            ->postJson("/api/v1/seller/promotions/{$promotion->uuid}/submit")
            ->assertOk()
            // "Pending review" — never "Awaiting payment". Nothing has been
            // charged and the label must not imply otherwise.
            ->assertJsonPath('data.status', PromotionRequestStatus::Pending->value)
            ->assertJsonPath('data.status_label', 'Pending review');
    }

    #[Test]
    public function nothing_a_vendor_submits_reaches_the_marketplace(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();
        $promotion = $this->draftFor($vendor, $listing);

        $this->actingAs($vendor)
            ->postJson("/api/v1/seller/promotions/{$promotion->uuid}/submit")
            ->assertOk();

        // The point of the whole phase: a vendor can ask, and only asking.
        $this->getJson('/api/v1/ads?placement='.AdPlacement::ListingsInline->value)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseCount('ad_campaigns', 0);
    }

    // -------------------------------------------------------------- isolation

    #[Test]
    public function a_vendor_sees_only_their_own_requests(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();
        $this->draftFor($vendor, $listing);

        [$other, $otherListing] = $this->vendorWithListing();
        $this->draftFor($other, $otherListing);

        $this->actingAs($vendor)
            ->getJson('/api/v1/seller/promotions')
            ->assertOk()
            // Scoped at the query, so another vendor's rows never enter the
            // result set and cannot leak through a serialiser.
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_vendor_cannot_read_another_vendors_request(): void
    {
        [$vendor] = $this->vendorWithListing();
        [$other, $otherListing] = $this->vendorWithListing();
        $theirs = $this->draftFor($other, $otherListing);

        $this->actingAs($vendor)
            ->getJson("/api/v1/seller/promotions/{$theirs->uuid}")
            ->assertNotFound();
    }

    #[Test]
    public function a_vendor_cannot_cancel_another_vendors_request(): void
    {
        [$vendor] = $this->vendorWithListing();
        [$other, $otherListing] = $this->vendorWithListing();
        $theirs = $this->draftFor($other, $otherListing);

        $this->actingAs($vendor)
            ->postJson("/api/v1/seller/promotions/{$theirs->uuid}/cancel")
            ->assertNotFound();

        $this->assertSame(PromotionRequestStatus::Draft, $theirs->refresh()->status);
    }

    #[Test]
    public function a_vendor_can_cancel_their_own_pending_request(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();
        $promotion = PromotionRequest::factory()->forListing($listing)->withArtwork()->create([
            'user_id' => $vendor->getKey(),
        ]);

        $this->actingAs($vendor)
            ->postJson("/api/v1/seller/promotions/{$promotion->uuid}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', PromotionRequestStatus::Cancelled->value);
    }

    #[Test]
    public function a_reviewed_request_can_no_longer_be_cancelled(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();
        $promotion = PromotionRequest::factory()
            ->forListing($listing)
            ->status(PromotionRequestStatus::Approved)
            ->create(['user_id' => $vendor->getKey()]);

        $this->actingAs($vendor)
            ->postJson("/api/v1/seller/promotions/{$promotion->uuid}/cancel")
            ->assertStatus(422);
    }

    #[Test]
    public function artwork_cannot_be_swapped_after_submission(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();
        $promotion = PromotionRequest::factory()->forListing($listing)->withArtwork()->create([
            'user_id' => $vendor->getKey(),
        ]);

        // Submitted artwork is what an administrator is reviewing; approved
        // artwork is what the live creative points at. Either way, a swap
        // would change what nobody has seen.
        $this->actingAs($vendor)
            ->postJson("/api/v1/seller/promotions/{$promotion->uuid}/artwork/desktop", [])
            ->assertStatus(422);
    }

    // --------------------------------------------------------------- approval

    #[Test]
    public function a_vendor_cannot_reach_the_admin_review_endpoints(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();
        $promotion = PromotionRequest::factory()->forListing($listing)->withArtwork()->create([
            'user_id' => $vendor->getKey(),
        ]);

        $this->actingAs($vendor)
            ->getJson('/api/v1/admin/promotion-requests')
            ->assertForbidden();

        $this->actingAs($vendor)
            ->postJson("/api/v1/admin/promotion-requests/{$promotion->uuid}/approve")
            ->assertForbidden();

        $this->assertSame(PromotionRequestStatus::Pending, $promotion->refresh()->status);
    }

    #[Test]
    public function a_moderator_without_advertising_manage_cannot_approve(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();
        $promotion = PromotionRequest::factory()->forListing($listing)->withArtwork()->create([
            'user_id' => $vendor->getKey(),
        ]);

        $moderator = User::factory()->create()->syncRoles([RoleSlug::Moderator->value]);

        $this->actingAs($moderator)
            ->postJson("/api/v1/admin/promotion-requests/{$promotion->uuid}/approve")
            ->assertForbidden();
    }

    #[Test]
    public function approval_mints_a_draft_campaign_that_is_not_yet_serving(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();
        $promotion = PromotionRequest::factory()->forListing($listing)->withArtwork()->create([
            'user_id' => $vendor->getKey(),
            'placement' => AdPlacement::ListingsTop,
        ]);

        $response = $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/promotion-requests/{$promotion->uuid}/approve")
            ->assertOk();

        $response->assertJsonPath('data.status', PromotionRequestStatus::Approved->value);

        /*
         * DRAFT, and the response says so explicitly. Approving a request and
         * putting it on the site are two decisions; Phase 11A's lifecycle keeps
         * them apart, and silently activating here would also bypass the
         * "must have an active creative" guard on transition.
         */
        $response->assertJsonPath('meta.campaign_status', AdCampaignStatus::Draft->value);
        $response->assertJsonPath('meta.requires_activation', true);

        $campaign = AdCampaign::query()->firstOrFail();
        $this->assertSame(AdCampaignStatus::Draft, $campaign->status);
        $this->assertSame(1, $campaign->creatives()->count());

        // Still nothing on the marketplace.
        $this->getJson('/api/v1/ads?placement='.AdPlacement::ListingsTop->value)
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function the_minted_campaign_follows_the_existing_lifecycle_and_then_serves(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();
        $promotion = PromotionRequest::factory()->forListing($listing)->withArtwork()->create([
            'user_id' => $vendor->getKey(),
            'placement' => AdPlacement::ListingsTop,
            'requested_start' => now()->subDay()->toDateString(),
            'requested_end' => now()->addWeek()->toDateString(),
        ]);

        $admin = $this->admin();

        $campaignUuid = $this->actingAs($admin)
            ->postJson("/api/v1/admin/promotion-requests/{$promotion->uuid}/approve")
            ->assertOk()
            ->json('meta.campaign_uuid');

        // The SAME Phase 11A transition endpoint — no separate activation path
        // for promotions, which is what keeps one lifecycle rather than two.
        $this->actingAs($admin)
            ->postJson("/api/v1/admin/ad-campaigns/{$campaignUuid}/transition", [
                'status' => AdCampaignStatus::Active->value,
            ])
            ->assertOk();

        /*
         * Browsed WITH the vertical, because approval targets the campaign at
         * the promoted listing's vertical — a promotion for an apartment
         * appears on property searches and not on used cars.
         *
         * The vertical, not the leaf: `AdServingService` matches a campaign
         * whose targeted category is in the browsed category's ancestor chain,
         * so targeting "Apartments" would reach only people who had already
         * narrowed that far.
         */
        $vertical = $listing->category->pathIds()[0];
        $verticalSlug = Category::query()->findOrFail($vertical)->slug;

        $this->getJson(
            '/api/v1/ads?placement='.AdPlacement::ListingsTop->value.'&category='.$verticalSlug,
        )
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // And a leaf UNDER that vertical reaches it too, which is the whole
        // point of targeting the vertical rather than the leaf.
        $this->getJson(
            '/api/v1/ads?placement='.AdPlacement::ListingsTop->value.'&category='.$listing->category->slug,
        )
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function the_minted_creative_points_at_the_saka_resource_not_a_vendor_supplied_url(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();
        $promotion = PromotionRequest::factory()->forListing($listing)->withArtwork()->create([
            'user_id' => $vendor->getKey(),
        ]);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/promotion-requests/{$promotion->uuid}/approve")
            ->assertOk();

        $creative = AdCampaign::query()->firstOrFail()->creatives()->firstOrFail();

        /*
         * The single most important restriction in the vendor flow. An
         * arbitrary destination on a paid placement inside a marketplace people
         * trust is a phishing page with a media buy behind it, and no scheme
         * check closes that — `https://saka-login.example.com` passes them all.
         */
        $this->assertSame(
            rtrim((string) config('saka.frontend_url'), '/')."/listings/{$listing->slug}",
            $creative->click_url,
        );
    }

    #[Test]
    public function approval_is_refused_when_the_promoted_listing_is_no_longer_published(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();
        $promotion = PromotionRequest::factory()->forListing($listing)->withArtwork()->create([
            'user_id' => $vendor->getKey(),
        ]);

        // Time passes in the queue: the listing sold this morning.
        $listing->forceFill(['status' => 'sold'])->save();

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/promotion-requests/{$promotion->uuid}/approve")
            ->assertStatus(422);

        $this->assertDatabaseCount('ad_campaigns', 0);
        $this->assertSame(PromotionRequestStatus::Pending, $promotion->refresh()->status);
    }

    #[Test]
    public function approval_is_refused_when_the_requested_window_has_already_closed(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();
        $promotion = PromotionRequest::factory()
            ->forListing($listing)
            ->withArtwork()
            ->pastWindow()
            ->create(['user_id' => $vendor->getKey()]);

        // Otherwise the campaign is minted expired and the vendor is told their
        // promotion was approved when it will never run.
        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/promotion-requests/{$promotion->uuid}/approve")
            ->assertStatus(422);
    }

    #[Test]
    public function approval_is_refused_without_artwork(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();
        $promotion = PromotionRequest::factory()->forListing($listing)->create([
            'user_id' => $vendor->getKey(),
        ]);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/promotion-requests/{$promotion->uuid}/approve")
            ->assertStatus(422);
    }

    #[Test]
    public function a_request_cannot_be_approved_twice(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();
        $promotion = PromotionRequest::factory()->forListing($listing)->withArtwork()->create([
            'user_id' => $vendor->getKey(),
        ]);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/promotion-requests/{$promotion->uuid}/approve")
            ->assertOk();

        // A second approval would mint a second campaign for one booking.
        $this->actingAs($admin)
            ->postJson("/api/v1/admin/promotion-requests/{$promotion->uuid}/approve")
            ->assertStatus(422);

        $this->assertDatabaseCount('ad_campaigns', 1);
    }

    // -------------------------------------------------------------- rejection

    #[Test]
    public function rejection_requires_a_reason(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();
        $promotion = PromotionRequest::factory()->forListing($listing)->withArtwork()->create([
            'user_id' => $vendor->getKey(),
        ]);

        $admin = $this->admin();

        // A vendor told only "Rejected" resubmits the same thing.
        $this->actingAs($admin)
            ->postJson("/api/v1/admin/promotion-requests/{$promotion->uuid}/reject", [])
            ->assertStatus(422);

        // And "no" is not a reason.
        $this->actingAs($admin)
            ->postJson("/api/v1/admin/promotion-requests/{$promotion->uuid}/reject", ['reason' => 'no'])
            ->assertStatus(422);

        $this->assertSame(PromotionRequestStatus::Pending, $promotion->refresh()->status);
    }

    #[Test]
    public function the_vendor_can_read_why_their_request_was_rejected(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();
        $promotion = PromotionRequest::factory()->forListing($listing)->withArtwork()->create([
            'user_id' => $vendor->getKey(),
        ]);

        $reason = 'The artwork covers the headline and is unreadable on mobile.';

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/promotion-requests/{$promotion->uuid}/reject", ['reason' => $reason])
            ->assertOk();

        $this->actingAs($vendor)
            ->getJson("/api/v1/seller/promotions/{$promotion->uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', PromotionRequestStatus::Rejected->value)
            ->assertJsonPath('data.review.rejection_reason', $reason);

        $this->assertDatabaseCount('ad_campaigns', 0);
    }

    // ------------------------------------------------------------------ audit

    #[Test]
    public function the_lifecycle_is_audited(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();
        $promotion = $this->draftFor($vendor, $listing);

        $this->actingAs($vendor)
            ->postJson("/api/v1/seller/promotions/{$promotion->uuid}/submit")
            ->assertOk();

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/promotion-requests/{$promotion->uuid}/approve")
            ->assertOk();

        // Who did what to whose money-adjacent record, in the existing log.
        $this->assertDatabaseHas('audit_events', ['action' => 'promotion.requested']);
        $this->assertDatabaseHas('audit_events', ['action' => 'promotion.approved']);
    }

    // ----------------------------------------------------------- housekeeping

    #[Test]
    public function the_sweeper_expires_stale_pending_requests_but_leaves_drafts_alone(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();

        $stale = PromotionRequest::factory()->forListing($listing)->withArtwork()->pastWindow()->create([
            'user_id' => $vendor->getKey(),
        ]);

        $draft = PromotionRequest::factory()
            ->forListing($listing)
            ->status(PromotionRequestStatus::Draft)
            ->pastWindow()
            ->create(['user_id' => $vendor->getKey()]);

        $this->artisan('saka:promotions:expire')->assertSuccessful();

        $this->assertSame(PromotionRequestStatus::Expired, $stale->refresh()->status);
        // A vendor's unfinished wizard is theirs to abandon; expiring it would
        // delete their work without asking.
        $this->assertSame(PromotionRequestStatus::Draft, $draft->refresh()->status);
    }

    #[Test]
    public function no_promotion_state_anywhere_claims_a_payment(): void
    {
        [$vendor, $listing] = $this->vendorWithListing();
        $promotion = PromotionRequest::factory()->forListing($listing)->withArtwork()->create([
            'user_id' => $vendor->getKey(),
        ]);

        $vendorView = $this->actingAs($vendor)->getJson('/api/v1/seller/promotions')->assertOk()->json();
        $adminView = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/promotion-requests')->assertOk()->json();

        $encoded = json_encode([$vendorView, $adminView], JSON_THROW_ON_ERROR);

        /*
         * SAKA cannot take money. Until it can, no surface may carry a word
         * that implies it has — this test is what stops "Paid" reappearing in a
         * label six months from now.
         */
        foreach (['paid', 'invoice', 'transaction', 'amount_minor', 'wallet'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $encoded);
        }

        $this->assertSame(PromotionRequestStatus::Pending->value, $promotion->status->value);
    }
}
