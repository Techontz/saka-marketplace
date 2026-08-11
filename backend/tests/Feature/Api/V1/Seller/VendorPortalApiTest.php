<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Seller;

use App\Domain\Identity\Enums\BusinessType;
use App\Domain\Listing\Enums\ListingStatus;
use App\Domain\Trust\Enums\VerificationType;
use App\Models\District;
use App\Models\Favorite;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\Region;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Milestone 12 vendor API: business profile, onboarding progress,
 * analytics, reviews received, inquiry states and listing duplication.
 *
 * The emphasis is on the multi-vertical behaviour and on the boundaries that
 * would be expensive to get wrong — a vendor reading another vendor's numbers,
 * a duplicate inheriting a reputation it did not earn, or a report that
 * silently hides criticism.
 */
class VendorPortalApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function vendor(): User
    {
        $user = User::factory()->seller()->create();
        $user->forceFill(['phone_verified_at' => now()])->save();

        return $user;
    }

    private function listingFor(User $vendor, ListingStatus $status = ListingStatus::Published): Listing
    {
        return Listing::factory()->ownedBy($vendor)->status($status)->create();
    }

    // --------------------------------------------------------- business type

    #[Test]
    public function business_types_describe_the_rules_each_one_implies(): void
    {
        $types = $this->getJson('/api/v1/business-types')->assertOk()->json('data');

        $this->assertCount(count(BusinessType::cases()), $types);

        $byValue = collect($types)->keyBy('value');

        // The whole point of the enum: one portal, per-vertical behaviour.
        $this->assertTrue($byValue['pharmacy']['has_opening_hours']);
        $this->assertFalse($byValue['landlord']['has_opening_hours']);
        $this->assertFalse($byValue['service_provider']['has_walk_in_address']);

        // Nobody running a hotel calls a room a "listing".
        $this->assertSame('rooms', $byValue['hotel']['listing_noun']['plural']);
        $this->assertSame('vehicles', $byValue['car_dealer']['listing_noun']['plural']);
        $this->assertSame(['property'], $byValue['landlord']['category_slugs']);
    }

    #[Test]
    public function business_types_are_readable_without_signing_in(): void
    {
        // The registration screen needs them before an account exists.
        $this->getJson('/api/v1/business-types')->assertOk();
    }

    // ------------------------------------------------------------- profile

    #[Test]
    public function a_vendor_profile_is_created_on_first_access(): void
    {
        $vendor = $this->vendor();
        SellerProfile::where('user_id', $vendor->id)->forceDelete();

        // A user becomes a seller on first publish, so the row may not exist.
        // Every screen can assume one rather than special-casing its absence.
        $this->actingAs($vendor, 'sanctum')
            ->getJson('/api/v1/seller/vendor-profile')
            ->assertOk()
            ->assertJsonPath('data.slug', fn ($slug) => is_string($slug) && $slug !== '');
    }

    #[Test]
    public function onboarding_progress_depends_on_the_business_type(): void
    {
        $vendor = $this->vendor();

        $landlord = $this->actingAs($vendor, 'sanctum')
            ->patchJson('/api/v1/seller/vendor-profile', [
                'business_name' => 'Masaki Lettings',
                'business_type' => 'landlord',
                'bio' => 'We let flats in Masaki.',
            ])
            ->assertOk()
            ->json('meta.progress');

        // A landlord has no opening hours, so that step is not applicable —
        // and "not applicable" must count as done, or they can never reach 100%.
        $this->assertFalse($landlord['steps']['hours']['applicable']);
        $this->assertTrue($landlord['steps']['hours']['complete']);

        $pharmacy = $this->actingAs($vendor, 'sanctum')
            ->patchJson('/api/v1/seller/vendor-profile', ['business_type' => 'pharmacy'])
            ->assertOk()
            ->json('meta.progress');

        // The same profile, a different trade, a different checklist.
        $this->assertTrue($pharmacy['steps']['hours']['applicable']);
        $this->assertFalse($pharmacy['steps']['hours']['complete']);
    }

    #[Test]
    public function the_profile_carries_its_type_rules_so_the_client_needs_no_copy(): void
    {
        $vendor = $this->vendor();

        $meta = $this->actingAs($vendor, 'sanctum')
            ->patchJson('/api/v1/seller/vendor-profile', ['business_type' => 'hotel'])
            ->assertOk()
            ->json('meta.business_type');

        $this->assertSame('rooms', $meta['listing_noun']['plural']);
        $this->assertTrue($meta['has_opening_hours']);
    }

    #[Test]
    public function a_district_must_belong_to_its_region(): void
    {
        $vendor = $this->vendor();

        $region = Region::where('slug', 'dar-es-salaam')->firstOrFail();
        // A district in a DIFFERENT region. `exists:` proves the id is real but
        // not that the two belong together.
        $foreign = District::where('region_id', '!=', $region->id)->firstOrFail();

        $this->actingAs($vendor, 'sanctum')
            ->patchJson('/api/v1/seller/vendor-profile', [
                'region_id' => $region->id,
                'district_id' => $foreign->id,
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function coordinates_must_be_sent_as_a_pair(): void
    {
        // Half a pin is a bad map marker.
        $this->actingAs($this->vendor(), 'sanctum')
            ->patchJson('/api/v1/seller/vendor-profile', ['latitude' => -6.8])
            ->assertStatus(422);
    }

    #[Test]
    public function a_website_must_be_http_or_https(): void
    {
        // Rendered as an href on the public profile — `javascript:` here is
        // stored XSS pointed at every visitor.
        $this->actingAs($this->vendor(), 'sanctum')
            ->patchJson('/api/v1/seller/vendor-profile', ['website' => 'javascript:alert(1)'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('website');
    }

    // -------------------------------------------------------- opening hours

    #[Test]
    public function opening_hours_reject_overlapping_ranges(): void
    {
        $this->actingAs($this->vendor(), 'sanctum')
            ->patchJson('/api/v1/seller/vendor-profile', [
                'opening_hours' => [
                    'mon' => [
                        ['open' => '09:00', 'close' => '17:00'],
                        ['open' => '13:00', 'close' => '15:00'],
                    ],
                ],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function opening_hours_reject_a_close_before_its_open(): void
    {
        $this->actingAs($this->vendor(), 'sanctum')
            ->patchJson('/api/v1/seller/vendor-profile', [
                'opening_hours' => ['tue' => [['open' => '17:00', 'close' => '09:00']]],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function opening_hours_accept_a_split_shift_and_a_closed_day(): void
    {
        // Both are ordinary. A fixed open/close column pair could express
        // neither, which is why this is JSON.
        $this->actingAs($this->vendor(), 'sanctum')
            ->patchJson('/api/v1/seller/vendor-profile', [
                'opening_hours' => [
                    'mon' => [
                        ['open' => '08:00', 'close' => '13:00'],
                        ['open' => '14:00', 'close' => '18:00'],
                    ],
                    'sun' => [],
                ],
            ])
            ->assertOk();
    }

    // ----------------------------------------------------------- analytics

    #[Test]
    public function analytics_are_gap_filled_to_one_point_per_day(): void
    {
        $vendor = $this->vendor();
        $this->listingFor($vendor);

        $data = $this->actingAs($vendor, 'sanctum')
            ->getJson('/api/v1/seller/analytics?days=14')
            ->assertOk()
            ->json('data');

        foreach (['views', 'favorites', 'inquiries', 'reviews'] as $series) {
            $this->assertCount(14, $data[$series], "[{$series}] is not gap-filled.");
        }
    }

    #[Test]
    public function a_vendor_with_no_listings_gets_empty_series_not_an_error(): void
    {
        // The most common first-run state there is.
        $this->actingAs($this->vendor(), 'sanctum')
            ->getJson('/api/v1/seller/analytics?days=7')
            ->assertOk()
            ->assertJsonCount(7, 'data.views');
    }

    #[Test]
    public function analytics_never_include_another_vendors_listings(): void
    {
        $mine = $this->vendor();
        $theirs = $this->vendor();

        $listing = $this->listingFor($theirs);
        Favorite::create([
            'user_id' => $mine->id,
            'favoritable_type' => Listing::class,
            'favoritable_id' => $listing->id,
        ]);

        $series = $this->actingAs($mine, 'sanctum')
            ->getJson('/api/v1/seller/analytics?days=7')
            ->assertOk()
            ->json('data.favorites');

        // Scoped in the query, not filtered afterwards — a seller must not be
        // able to widen this into platform-wide analytics.
        $this->assertSame(0, array_sum(array_column($series, 'value')));
    }

    // ------------------------------------------------------------- reviews

    #[Test]
    public function reviews_returns_reviews_received_not_reviews_written(): void
    {
        $vendor = $this->vendor();
        $buyer = User::factory()->buyer()->create();
        $listing = $this->listingFor($vendor);

        Review::factory()->create([
            'listing_id' => $listing->id,
            'seller_id' => $vendor->id,
            'reviewer_id' => $buyer->id,
        ]);

        // `/account/reviews` is reviews the user WROTE — the opposite of what a
        // vendor needs, and the reason this endpoint exists.
        $this->actingAs($vendor, 'sanctum')
            ->getJson('/api/v1/seller/reviews')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/seller/reviews')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_vendor_can_answer_a_review_once_and_then_edit_the_answer(): void
    {
        $vendor = $this->vendor();
        $listing = $this->listingFor($vendor);

        $review = Review::factory()->create([
            'listing_id' => $listing->id,
            'seller_id' => $vendor->id,
            'status' => 'approved',
        ]);

        $this->actingAs($vendor, 'sanctum')
            ->postJson("/api/v1/seller/reviews/{$review->uuid}/reply", [
                'body' => 'Thank you — the heating has since been replaced.',
            ])
            ->assertOk()
            ->assertJsonPath('data.reply.body', 'Thank you — the heating has since been replaced.');

        $firstRepliedAt = $review->fresh()->replied_at;

        $this->travel(2)->days();

        $this->actingAs($vendor, 'sanctum')
            ->postJson("/api/v1/seller/reviews/{$review->uuid}/reply", [
                'body' => 'Thank you — the heating was replaced in March.',
            ])
            ->assertOk();

        // An edited answer is still the same answer: moving the timestamp would
        // misreport how quickly this vendor responds.
        $this->assertTrue($firstRepliedAt->equalTo($review->fresh()->replied_at));
    }

    #[Test]
    public function a_review_still_in_moderation_cannot_be_answered(): void
    {
        $vendor = $this->vendor();
        $listing = $this->listingFor($vendor);

        $review = Review::factory()->create([
            'listing_id' => $listing->id,
            'seller_id' => $vendor->id,
            'status' => 'pending',
        ]);

        // The reply is published alongside the review. Answering something that
        // may never appear would publish half a conversation.
        $this->actingAs($vendor, 'sanctum')
            ->postJson("/api/v1/seller/reviews/{$review->uuid}/reply", ['body' => 'Not fair!'])
            ->assertStatus(409);

        $this->assertNull($review->fresh()->reply_body);
    }

    #[Test]
    public function a_vendor_cannot_answer_someone_elses_review(): void
    {
        $vendor = $this->vendor();
        $theirs = $this->vendor();

        $review = Review::factory()->create([
            'listing_id' => $this->listingFor($theirs)->id,
            'seller_id' => $theirs->id,
            'status' => 'approved',
        ]);

        $this->actingAs($vendor, 'sanctum')
            ->postJson("/api/v1/seller/reviews/{$review->uuid}/reply", ['body' => 'Speaking for you.'])
            ->assertNotFound();

        $this->assertNull($review->fresh()->reply_body);
    }

    #[Test]
    public function reporting_a_review_does_not_hide_it(): void
    {
        $vendor = $this->vendor();
        $listing = $this->listingFor($vendor);

        $review = Review::factory()->create([
            'listing_id' => $listing->id,
            'seller_id' => $vendor->id,
            'status' => 'approved',
        ]);

        $this->actingAs($vendor, 'sanctum')
            ->postJson("/api/v1/seller/reviews/{$review->uuid}/report", [
                'reason' => 'not_a_customer',
                'details' => 'This person has never bought anything from us.',
            ])
            ->assertOk();

        /*
         * A vendor who could remove criticism by reporting it would make the
         * whole rating system worthless. The report is routed to moderation;
         * the review stays exactly as visible as it was.
         */
        $this->assertSame('approved', $review->fresh()->status->value);
    }

    #[Test]
    public function a_vendor_cannot_report_someone_elses_review(): void
    {
        $mine = $this->vendor();
        $theirs = $this->vendor();
        $listing = $this->listingFor($theirs);

        $review = Review::factory()->create([
            'listing_id' => $listing->id,
            'seller_id' => $theirs->id,
            'reviewer_id' => User::factory()->buyer()->create()->id,
        ]);

        // 404, not 403: whether a review exists is not this vendor's business
        // unless it is about them.
        $this->actingAs($mine, 'sanctum')
            ->postJson("/api/v1/seller/reviews/{$review->uuid}/report", [
                'reason' => 'spam',
                'details' => 'Trying to suppress a rival.',
            ])
            ->assertNotFound();
    }

    // ----------------------------------------------------------- inquiries

    #[Test]
    public function an_inquiry_can_be_resolved_and_filed(): void
    {
        $vendor = $this->vendor();
        $listing = $this->listingFor($vendor);

        $inquiry = Inquiry::create([
            'listing_id' => $listing->id,
            'first_name' => 'Asha',
            'email' => 'asha@example.com',
            'message' => 'Is this still available for viewing?',
        ]);

        $this->actingAs($vendor, 'sanctum')
            ->patchJson("/api/v1/seller/inquiries/{$inquiry->uuid}", ['status' => 'closed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');
    }

    #[Test]
    public function replied_cannot_be_set_by_hand(): void
    {
        $vendor = $this->vendor();
        $listing = $this->listingFor($vendor);

        $inquiry = Inquiry::create([
            'listing_id' => $listing->id,
            'first_name' => 'Asha',
            'email' => 'asha@example.com',
            'message' => 'Is this still available for viewing?',
        ]);

        /*
         * `replied` means "the vendor answered", which feeds the public
         * response-rate signal on their profile. Letting it be set directly
         * would let a vendor manufacture a reputation for responsiveness.
         */
        $this->actingAs($vendor, 'sanctum')
            ->patchJson("/api/v1/seller/inquiries/{$inquiry->uuid}", ['status' => 'replied'])
            ->assertStatus(422);
    }

    #[Test]
    public function a_vendor_cannot_touch_another_vendors_inquiry(): void
    {
        $mine = $this->vendor();
        $theirs = $this->vendor();
        $listing = $this->listingFor($theirs);

        $inquiry = Inquiry::create([
            'listing_id' => $listing->id,
            'first_name' => 'Asha',
            'email' => 'asha@example.com',
            'message' => 'Is this still available for viewing?',
        ]);

        $this->actingAs($mine, 'sanctum')
            ->patchJson("/api/v1/seller/inquiries/{$inquiry->uuid}", ['status' => 'closed'])
            ->assertNotFound();
    }

    // ----------------------------------------------------------- duplicate

    #[Test]
    public function a_duplicate_starts_with_no_reputation(): void
    {
        $vendor = $this->vendor();

        $original = $this->listingFor($vendor);
        $original->forceFill([
            'view_count' => 4000,
            'favorite_count' => 120,
            'inquiry_count' => 30,
            'is_featured' => true,
            'is_verified' => true,
        ])->save();

        $copy = $this->actingAs($vendor, 'sanctum')
            ->postJson("/api/v1/seller/listings/{$original->uuid}/duplicate")
            ->assertCreated()
            ->json('data');

        // A copy that inherited 4,000 views and a verified badge would be a
        // fabricated reputation.
        $this->assertSame('draft', $copy['status']);
        $this->assertSame(0, $copy['stats']['views']);
        $this->assertSame(0, $copy['stats']['favorites']);
        $this->assertFalse($copy['is_featured']);
        $this->assertFalse($copy['is_verified']);
        $this->assertNotSame($original->uuid, $copy['uuid']);
        $this->assertNotSame($original->slug, $copy['slug']);
    }

    #[Test]
    public function a_duplicate_does_not_copy_photos(): void
    {
        $vendor = $this->vendor();
        $original = $this->listingFor($vendor);

        $response = $this->actingAs($vendor, 'sanctum')
            ->postJson("/api/v1/seller/listings/{$original->uuid}/duplicate")
            ->assertCreated();

        // The photos are of a DIFFERENT flat. Copying them produces listings
        // that all show the same room, which is the exact complaint buyers have
        // about duplicated inventory.
        $this->assertSame([], $response->json('data.images'));
        $this->assertStringContainsString('Photos were not copied', $response->json('meta.message'));
    }

    #[Test]
    public function a_sold_listing_can_still_be_duplicated(): void
    {
        $vendor = $this->vendor();
        $original = $this->listingFor($vendor, ListingStatus::Sold);

        // Relisting last season's stock is the main use case for the feature.
        // Authorizing against `update` — which refuses on sold — would forbid
        // exactly that.
        $this->actingAs($vendor, 'sanctum')
            ->postJson("/api/v1/seller/listings/{$original->uuid}/duplicate")
            ->assertCreated();
    }

    #[Test]
    public function duplicating_does_not_require_a_verified_phone(): void
    {
        $vendor = User::factory()->seller()->create();
        $vendor->forceFill(['phone_verified_at' => null])->save();

        $original = $this->listingFor($vendor, ListingStatus::Draft);

        // A duplicate is a DRAFT, and drafts never needed a verified phone.
        // Gating the copy but not the original would be arbitrary.
        $this->actingAs($vendor, 'sanctum')
            ->postJson("/api/v1/seller/listings/{$original->uuid}/duplicate")
            ->assertCreated();
    }

    #[Test]
    public function a_vendor_cannot_duplicate_someone_elses_listing(): void
    {
        $mine = $this->vendor();
        $theirs = $this->vendor();

        $listing = $this->listingFor($theirs);

        $this->actingAs($mine, 'sanctum')
            ->postJson("/api/v1/seller/listings/{$listing->uuid}/duplicate")
            ->assertNotFound();
    }

    // -------------------------------------------------------- verification

    #[Test]
    public function a_vendor_cannot_queue_two_pending_documents_of_one_type(): void
    {
        $vendor = $this->vendor();

        VerificationRequest::factory()->create([
            'user_id' => $vendor->id,
            'type' => VerificationType::NationalId,
        ]);

        // The moderation queue is oldest-first; without this it fills with
        // duplicates of one impatient person.
        $this->actingAs($vendor, 'sanctum')
            ->postJson('/api/v1/seller/verifications', [
                'type' => 'national_id',
                'document' => File::image('id.jpg'),
            ])
            ->assertStatus(409);
    }
}
