<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Public;

use App\Domain\Identity\Enums\BusinessType;
use App\Models\Listing;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Public business pages — the surface that did not exist before Milestone 13.
 *
 * The important properties: a half-onboarded profile is not a page, private
 * fields never cross into the public view, and "near me" actually orders by
 * distance rather than by id.
 */
class BusinessPageTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** Dar es Salaam city centre, and a point ~4km away. */
    private const DAR = [-6.8162, 39.2803];

    private function business(array $attributes = []): SellerProfile
    {
        $owner = User::factory()->seller()->create();

        $profile = SellerProfile::query()->firstOrNew(['user_id' => $owner->getKey()]);
        $profile->forceFill(array_merge([
            'user_id' => $owner->getKey(),
            'slug' => 'biz-'.Str::lower(Str::random(8)),
            'display_name' => 'Kilimani Pharmacy',
            'business_type' => BusinessType::Pharmacy,
            'is_verified' => true,
            'business_reg_no' => 'REG-SECRET-001',
            'tin' => 'TIN-SECRET-002',
            'onboarding_completed_at' => now(),
        ], $attributes))->save();

        return $profile->refresh();
    }

    #[Test]
    public function a_business_page_shows_what_a_customer_needs_and_nothing_private(): void
    {
        $business = $this->business([
            'bio' => 'Open seven days.',
            'public_phone' => '+255700000111',
            'website' => 'https://example.co.tz',
            'opening_hours' => ['mon' => [['open' => '08:00', 'close' => '18:00']]],
        ]);

        $response = $this->getJson("/api/v1/businesses/{$business->slug}")->assertOk();

        $response
            ->assertJsonPath('data.display_name', 'Kilimani Pharmacy')
            ->assertJsonPath('data.contact.phone', '+255700000111')
            ->assertJsonPath('data.opening_hours.mon.0.open', '08:00');

        // The owner's registration details are absent by construction, not by
        // a conditional that a later edit could widen.
        $body = $response->getContent();
        $this->assertStringNotContainsString('REG-SECRET-001', $body);
        $this->assertStringNotContainsString('TIN-SECRET-002', $body);
    }

    #[Test]
    public function a_vendor_who_only_signed_up_is_not_a_business_page(): void
    {
        // A profile row is created the moment a vendor opens the portal,
        // pre-filled with their personal name. That is not a business.
        $owner = User::factory()->seller()->create();

        $profile = SellerProfile::query()->firstOrNew(['user_id' => $owner->getKey()]);
        $profile->forceFill([
            'user_id' => $owner->getKey(),
            'slug' => 'signed-up-only',
            'display_name' => 'Jane Doe',
            'onboarding_completed_at' => null,
        ])->save();

        $this->getJson('/api/v1/businesses')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/businesses/signed-up-only')->assertNotFound();
    }

    #[Test]
    public function a_vendor_with_a_live_listing_gets_a_page_even_mid_onboarding(): void
    {
        // Their listing is already reachable, so the business it links to has
        // to exist — a 404 behind a live listing is a dead end.
        $owner = User::factory()->seller()->create();

        $profile = SellerProfile::query()->firstOrNew(['user_id' => $owner->getKey()]);
        $profile->forceFill([
            'user_id' => $owner->getKey(),
            'slug' => 'mid-onboarding',
            'display_name' => 'Half Way Traders',
            'onboarding_completed_at' => null,
        ])->save();

        Listing::factory()->ownedBy($owner)->published()->create();

        $this->getJson('/api/v1/businesses/mid-onboarding')->assertOk();
    }

    #[Test]
    public function a_business_page_lists_only_its_own_published_listings(): void
    {
        $business = $this->business();
        $owner = User::find($business->user_id);

        $mine = Listing::factory()->ownedBy($owner)->published()->create();
        Listing::factory()->ownedBy($owner)->create(); // a draft
        Listing::factory()->published()->create();     // someone else's

        $this->getJson("/api/v1/businesses/{$business->slug}/listings")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $mine->slug);
    }

    #[Test]
    public function a_business_page_shows_approved_reviews_only(): void
    {
        $business = $this->business();
        $listing = Listing::factory()->ownedBy(User::find($business->user_id))->published()->create();

        Review::factory()->create([
            'listing_id' => $listing->id,
            'seller_id' => $business->user_id,
            'status' => 'approved',
        ]);
        Review::factory()->create([
            'listing_id' => $listing->id,
            'seller_id' => $business->user_id,
            'status' => 'pending',
        ]);

        $this->getJson("/api/v1/businesses/{$business->slug}/reviews")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function nearby_businesses_come_back_nearest_first_with_a_distance(): void
    {
        [$lat, $lng] = self::DAR;

        $near = $this->business(['latitude' => $lat + 0.01, 'longitude' => $lng]);   // ~1km
        $far = $this->business(['latitude' => $lat + 0.09, 'longitude' => $lng]);    // ~10km
        $this->business(['latitude' => -3.3869, 'longitude' => 36.6830]);            // Arusha

        $data = $this->getJson("/api/v1/businesses?lat={$lat}&lng={$lng}&radius=20")
            ->assertOk()
            ->json('data');

        $slugs = array_column($data, 'slug');

        $this->assertSame([$near->slug, $far->slug], $slugs);
        // The distance is what the map labels each pin with; computing it twice
        // client-side would disagree with the ordering.
        $this->assertLessThan(2, $data[0]['distance_km']);
    }

    #[Test]
    public function a_business_outside_the_radius_is_excluded_not_just_ranked_last(): void
    {
        [$lat, $lng] = self::DAR;

        $this->business(['latitude' => -3.3869, 'longitude' => 36.6830]); // Arusha, ~550km

        $this->getJson("/api/v1/businesses?lat={$lat}&lng={$lng}&radius=25")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function businesses_can_be_searched_and_filtered_by_trade(): void
    {
        $this->business(['display_name' => 'Masaki Motors', 'business_type' => BusinessType::CarDealer]);
        $this->business(['display_name' => 'Kilimani Pharmacy', 'business_type' => BusinessType::Pharmacy]);

        $this->getJson('/api/v1/businesses?q=masaki')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.display_name', 'Masaki Motors');

        $this->getJson('/api/v1/businesses?business_type=pharmacy')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.display_name', 'Kilimani Pharmacy');
    }

    #[Test]
    public function similar_businesses_exclude_the_one_being_viewed(): void
    {
        [$lat, $lng] = self::DAR;

        $business = $this->business(['latitude' => $lat, 'longitude' => $lng]);
        $this->business(['latitude' => $lat + 0.02, 'longitude' => $lng]);

        $data = $this->getJson("/api/v1/businesses/{$business->slug}/similar")
            ->assertOk()
            ->json('data');

        $this->assertNotContains($business->slug, array_column($data, 'slug'));
        $this->assertCount(1, $data);
    }
}
