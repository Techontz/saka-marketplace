<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Seller;

use App\Domain\Listing\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SellerDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    #[Test]
    public function the_dashboard_exposes_every_documented_metric(): void
    {
        $seller = User::factory()->seller()->create();

        Listing::factory()->count(2)->ownedBy($seller)->published()->create();
        Listing::factory()->ownedBy($seller)->status(ListingStatus::Draft)->create();
        Listing::factory()->ownedBy($seller)->status(ListingStatus::PendingReview)->create();

        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/seller/dashboard')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'listings' => ['total', 'active', 'draft', 'pending', 'rejected', 'paused', 'sold', 'expired', 'archived', 'by_status'],
                'engagement' => ['total_views', 'views_last_30_days', 'total_favorites', 'total_inquiries', 'unread_inquiries'],
                'verification' => ['phone_verified', 'email_verified', 'can_publish', 'seller_verified', 'verification_level'],
                'profile_completion' => ['percent', 'completed', 'total', 'checklist', 'missing'],
            ]])
            ->assertJsonPath('data.listings.total', 4)
            ->assertJsonPath('data.listings.active', 2)
            ->assertJsonPath('data.listings.draft', 1)
            ->assertJsonPath('data.listings.pending', 1)
            ->assertJsonPath('data.verification.can_publish', true);
    }

    #[Test]
    public function an_unverified_seller_sees_that_they_cannot_publish(): void
    {
        $seller = User::factory()->buyer()->create();

        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/seller/dashboard')
            ->assertOk()
            ->assertJsonPath('data.verification.phone_verified', false)
            ->assertJsonPath('data.verification.can_publish', false);
    }

    #[Test]
    public function profile_completion_lists_what_is_missing(): void
    {
        $seller = User::factory()->seller()->create();

        $response = $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/seller/dashboard')->assertOk();

        // A bare percentage tells a seller nothing; the checklist does.
        $this->assertIsArray($response->json('data.profile_completion.missing'));
        $this->assertContains('bio', $response->json('data.profile_completion.missing'));
        $this->assertLessThan(100, $response->json('data.profile_completion.percent'));
    }

    #[Test]
    public function engagement_totals_reflect_real_activity(): void
    {
        $seller = User::factory()->seller()->create();
        $listing = Listing::factory()->ownedBy($seller)->published()->create();

        $this->getJson("/api/v1/listings/{$listing->slug}")->assertOk();

        $buyer = User::factory()->buyer()->create();
        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/account/favorites/{$listing->slug}")->assertOk();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/inquiries', [
            'listing_slug' => $listing->slug,
            'first_name' => 'Asha', 'email' => 'asha@example.com',
            'message' => 'Is this still available for viewing?',
        ])->assertCreated();

        // Counters are buffered in Redis; the dashboard reads MySQL.
        $this->flushCounters();

        $data = $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/seller/dashboard')->assertOk()->json('data');

        $this->assertSame(1, $data['engagement']['total_views']);
        $this->assertSame(1, $data['engagement']['total_favorites']);
        $this->assertSame(1, $data['engagement']['total_inquiries']);
        $this->assertSame(1, $data['engagement']['unread_inquiries']);
    }

    #[Test]
    public function the_dashboard_cache_is_invalidated_when_a_listing_changes(): void
    {
        $seller = User::factory()->seller()->create();

        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/seller/dashboard')
            ->assertOk()->assertJsonPath('data.listings.total', 0);

        $listing = Listing::factory()->ownedBy($seller)->create();

        // Stale for up to 5 minutes unless a write busts it — so writes do.
        $this->actingAs($seller, 'sanctum')
            ->deleteJson("/api/v1/seller/listings/{$listing->uuid}")->assertOk();

        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/seller/dashboard')
            ->assertOk()->assertJsonPath('data.listings.total', 0);
    }

    #[Test]
    public function a_seller_can_read_and_update_their_public_profile(): void
    {
        $seller = User::factory()->seller()->create();

        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/seller/profile')
            ->assertOk()->assertJsonStructure(['data' => ['slug', 'display_name', 'is_verified']]);

        $this->actingAs($seller, 'sanctum')
            ->patchJson('/api/v1/seller/profile', ['bio' => 'Trusted agent in Dar es Salaam.'])
            ->assertOk()->assertJsonPath('data.bio', 'Trusted agent in Dar es Salaam.');
    }

    #[Test]
    public function the_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/v1/seller/dashboard')->assertStatus(401);
    }
}
