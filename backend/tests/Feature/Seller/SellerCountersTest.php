<?php

declare(strict_types=1);

namespace Tests\Feature\Seller;

use App\Domain\Listing\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Listing\ListingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `seller_profiles.active_listings` and `total_listings`.
 *
 * These were read by the business directory — the featured filter, the "N
 * listings" on every card, the sort-by-listings — and written by nothing, so
 * they sat at zero forever and every business appeared to have no stock.
 */
class SellerCountersTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function seller(): User
    {
        $user = User::factory()->seller()->withVerifiedPhone()->create();

        SellerProfile::query()->firstOrNew(['user_id' => $user->getKey()])
            ->forceFill([
                'user_id' => $user->getKey(),
                'display_name' => 'Counter Test Traders',
                'slug' => 'counter-test-'.$user->getKey(),
                'onboarding_completed_at' => now(),
            ])->save();

        return $user;
    }

    private function counters(User $user): SellerProfile
    {
        return SellerProfile::query()->where('user_id', $user->getKey())->firstOrFail();
    }

    #[Test]
    public function publishing_a_listing_raises_the_active_count(): void
    {
        $seller = $this->seller();
        $listing = Listing::factory()->ownedBy($seller)->status(ListingStatus::PendingReview)->create();

        app(ListingStatusService::class)->transition($listing, ListingStatus::Published, $seller);

        $this->assertSame(1, $this->counters($seller)->active_listings);
    }

    #[Test]
    public function pausing_a_listing_lowers_the_active_count_but_not_the_total(): void
    {
        $seller = $this->seller();
        $listing = Listing::factory()->ownedBy($seller)->status(ListingStatus::PendingReview)->create();

        $status = app(ListingStatusService::class);
        $status->transition($listing, ListingStatus::Published, $seller);
        $this->assertSame(1, $this->counters($seller)->active_listings);

        $status->transition($listing->fresh(), ListingStatus::Paused, $seller);

        $profile = $this->counters($seller);
        $this->assertSame(0, $profile->active_listings);
        // A paused listing still exists; only its availability changed.
        $this->assertSame(1, $profile->total_listings);
    }

    #[Test]
    public function a_featured_business_must_actually_have_live_stock(): void
    {
        $seller = $this->seller();
        $this->counters($seller)->forceFill(['is_verified' => true])->save();

        // Verified, but with nothing published — not featurable.
        $this->getJson('/api/v1/businesses?featured=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $listing = Listing::factory()->ownedBy($seller)->status(ListingStatus::PendingReview)->create();
        app(ListingStatusService::class)->transition($listing, ListingStatus::Published, $seller);

        $this->getJson('/api/v1/businesses?featured=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.listing_count', 1);
    }

    #[Test]
    public function the_reconciliation_command_repairs_drift(): void
    {
        $seller = $this->seller();
        Listing::factory()->count(3)->ownedBy($seller)->published()->create();

        // Exactly the state the whole platform was in before this was fixed.
        $this->counters($seller)->forceFill(['active_listings' => 0, 'total_listings' => 0])->save();

        $this->artisan('saka:sellers:recount')->assertSuccessful();

        $profile = $this->counters($seller);
        $this->assertSame(3, $profile->active_listings);
        $this->assertSame(3, $profile->total_listings);
    }
}
