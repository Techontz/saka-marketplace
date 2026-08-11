<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Seller;

use App\Domain\Listing\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The full listing lifecycle. Every transition is checked against the enum's
 * transition table, and every change is recorded in listing_status_histories.
 */
class ListingStatusWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = User::factory()->seller()->create();
    }

    private function draftWithImage(): Listing
    {
        $listing = Listing::factory()->ownedBy($this->seller)->status(ListingStatus::Draft)->create();

        // uuid is assigned by the HasUuid trait, not mass-assigned.
        Media::create([
            'mediable_type' => $listing->getMorphClass(),
            'mediable_id' => $listing->id,
            'collection' => 'gallery',
            'disk' => 'public',
            'path' => 'listings/x/gallery/test.jpg',
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size_bytes' => 1024,
            'is_primary' => true,
        ]);

        return $listing->fresh();
    }

    #[Test]
    public function a_draft_can_be_submitted_for_review(): void
    {
        $listing = $this->draftWithImage();

        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/seller/listings/{$listing->uuid}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', ListingStatus::PendingReview->value);

        $this->assertDatabaseHas('listing_status_histories', [
            'listing_id' => $listing->id,
            'from_status' => 'draft',
            'to_status' => 'pending_review',
        ]);
    }

    #[Test]
    public function a_listing_without_an_image_cannot_be_submitted(): void
    {
        $listing = Listing::factory()->ownedBy($this->seller)->status(ListingStatus::Draft)->create();

        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/seller/listings/{$listing->uuid}/submit")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION')
            ->assertJsonPath('error.details.reason', 'missing_primary_image');
    }

    #[Test]
    public function a_seller_without_a_verified_phone_cannot_publish(): void
    {
        $unverified = User::factory()->buyer()->create(); // no phone verification
        $listing = Listing::factory()->ownedBy($unverified)->status(ListingStatus::Draft)->create();

        // Milestone 4 decision 5 — the gate applies at the route layer...
        $this->actingAs($unverified, 'sanctum')
            ->postJson("/api/v1/seller/listings/{$listing->uuid}/submit")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PHONE_NOT_VERIFIED');

        $this->assertSame(ListingStatus::Draft, $listing->fresh()->status);
    }

    #[Test]
    public function an_illegal_transition_is_refused_with_the_allowed_set(): void
    {
        $listing = Listing::factory()->ownedBy($this->seller)->status(ListingStatus::Draft)->create();

        // Draft cannot go straight to Sold.
        $response = $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/seller/listings/{$listing->uuid}/sold")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');

        $this->assertContains('pending_review', $response->json('error.details.allowed'));
    }

    #[Test]
    public function a_moderator_can_approve_a_pending_listing(): void
    {
        $moderator = User::factory()->moderator()->create();
        $listing = Listing::factory()->ownedBy($this->seller)->status(ListingStatus::PendingReview)->create();

        $this->actingAs($moderator, 'sanctum')
            ->postJson("/api/v1/admin/listings/{$listing->uuid}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', ListingStatus::Published->value);

        $fresh = $listing->fresh();
        $this->assertNotNull($fresh->published_at);
        $this->assertNotNull($fresh->expires_at);
    }

    #[Test]
    public function a_moderator_can_reject_with_a_reason(): void
    {
        $moderator = User::factory()->moderator()->create();
        $listing = Listing::factory()->ownedBy($this->seller)->status(ListingStatus::PendingReview)->create();

        $this->actingAs($moderator, 'sanctum')
            ->postJson("/api/v1/admin/listings/{$listing->uuid}/reject", ['reason' => 'Photos do not match the description.'])
            ->assertOk()
            ->assertJsonPath('data.status', ListingStatus::Rejected->value);

        $this->assertSame('Photos do not match the description.', $listing->fresh()->rejection_reason);
    }

    #[Test]
    public function rejection_requires_a_reason(): void
    {
        $moderator = User::factory()->moderator()->create();
        $listing = Listing::factory()->ownedBy($this->seller)->status(ListingStatus::PendingReview)->create();

        $this->actingAs($moderator, 'sanctum')
            ->postJson("/api/v1/admin/listings/{$listing->uuid}/reject", [])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    #[Test]
    public function a_seller_cannot_moderate(): void
    {
        $listing = Listing::factory()->ownedBy($this->seller)->status(ListingStatus::PendingReview)->create();

        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/admin/listings/{$listing->uuid}/approve")
            ->assertStatus(403);
    }

    #[Test]
    public function published_listings_can_be_paused_resumed_and_sold(): void
    {
        $listing = Listing::factory()->ownedBy($this->seller)->published()->create();

        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/seller/listings/{$listing->uuid}/pause")
            ->assertOk()->assertJsonPath('data.status', ListingStatus::Paused->value);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/seller/listings/{$listing->uuid}/resume")
            ->assertOk()->assertJsonPath('data.status', ListingStatus::Published->value);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/seller/listings/{$listing->uuid}/sold")
            ->assertOk()->assertJsonPath('data.status', ListingStatus::Sold->value);
    }

    #[Test]
    public function a_paused_listing_disappears_from_public_browse(): void
    {
        $listing = Listing::factory()->ownedBy($this->seller)->published()->create();

        $this->getJson('/api/v1/listings')->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/seller/listings/{$listing->uuid}/pause")->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/listings')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_sold_listing_can_no_longer_be_edited(): void
    {
        $listing = Listing::factory()->ownedBy($this->seller)->status(ListingStatus::Sold)->create();

        // A sold listing is a historical record, not a draft.
        $this->actingAs($this->seller, 'sanctum')
            ->patchJson("/api/v1/seller/listings/{$listing->uuid}", ['title' => 'Trying to reuse a sold listing'])
            ->assertStatus(403);
    }

    #[Test]
    public function a_moderator_can_verify_and_feature_a_listing(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $listing = Listing::factory()->ownedBy($this->seller)->published()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/listings/{$listing->uuid}/verify", ['verified' => true])
            ->assertOk()->assertJsonPath('data.is_verified', true);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/listings/{$listing->uuid}/feature", ['featured' => true])
            ->assertOk()->assertJsonPath('data.is_featured', true);
    }

    #[Test]
    public function editing_a_published_listing_sends_it_back_for_review(): void
    {
        $listing = Listing::factory()->ownedBy($this->seller)->published()->create();

        $this->actingAs($this->seller, 'sanctum')
            ->patchJson("/api/v1/seller/listings/{$listing->uuid}", ['title' => 'A materially different title now'])
            ->assertOk();

        // Otherwise a seller could publish innocuous copy and swap it after.
        $this->assertSame(ListingStatus::PendingReview, $listing->fresh()->status);
    }

    #[Test]
    public function a_seller_cannot_publish_their_own_listing_by_resuming_it(): void
    {
        // The status machine allows Pending review -> Published, because that
        // is how a MODERATOR approves. `resume` transitions to exactly that
        // status, so without a guard a seller could approve their own listing.
        $listing = Listing::factory()
            ->ownedBy($this->seller)
            ->status(ListingStatus::PendingReview)
            ->create();

        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/seller/listings/{$listing->uuid}/resume")
            ->assertStatus(409);

        $this->assertSame(ListingStatus::PendingReview, $listing->fresh()->status);
    }
}
