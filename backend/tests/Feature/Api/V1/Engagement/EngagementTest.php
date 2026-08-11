<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Engagement;

use App\Domain\Engagement\Enums\ReviewStatus;
use App\Models\Listing;
use App\Models\Review;
use App\Models\User;
use App\Notifications\NewInquiryNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EngagementTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    // ------------------------------------------------------------- favorites

    #[Test]
    public function a_user_can_favorite_and_unfavorite_a_listing(): void
    {
        $user = User::factory()->buyer()->create();
        $listing = Listing::factory()->published()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/account/favorites/{$listing->slug}")
            ->assertOk()->assertJsonPath('data.favorited', true);

        $this->flushCounters();
        $this->assertSame(1, $listing->fresh()->favorite_count);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/account/favorites/{$listing->slug}")
            ->assertOk()->assertJsonPath('data.favorited', false);

        $this->flushCounters();
        $this->assertSame(0, $listing->fresh()->favorite_count);
    }

    #[Test]
    public function favoriting_twice_is_idempotent(): void
    {
        $user = User::factory()->buyer()->create();
        $listing = Listing::factory()->published()->create();

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/account/favorites/{$listing->slug}")->assertOk();
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/account/favorites/{$listing->slug}")
            ->assertOk()->assertJsonPath('data.created', false);

        // The unique key does the work; the counter must not double.
        $this->assertDatabaseCount('favorites', 1);
        $this->flushCounters();
        $this->assertSame(1, $listing->fresh()->favorite_count);
    }

    #[Test]
    public function unfavoriting_twice_cannot_drive_the_counter_negative(): void
    {
        $user = User::factory()->buyer()->create();
        $listing = Listing::factory()->published()->create();

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/account/favorites/{$listing->slug}")->assertOk();
        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/account/favorites/{$listing->slug}")->assertOk();
        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/account/favorites/{$listing->slug}")->assertOk();

        // The flush clamps at zero, so the UNSIGNED column cannot underflow.
        $this->flushCounters();
        $this->assertSame(0, $listing->fresh()->favorite_count);
    }

    #[Test]
    public function the_favorites_list_and_detail_flag_reflect_state(): void
    {
        $user = User::factory()->buyer()->create();
        $listing = Listing::factory()->published()->create();

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/account/favorites/{$listing->slug}")->assertOk();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/account/favorites')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/listings/{$listing->slug}")
            ->assertOk()->assertJsonPath('meta.is_favorited', true);
    }

    #[Test]
    public function favorites_require_authentication(): void
    {
        $listing = Listing::factory()->published()->create();
        $this->postJson("/api/v1/account/favorites/{$listing->slug}")->assertStatus(401);
    }

    // ------------------------------------------------------------- inquiries

    #[Test]
    public function a_guest_can_send_an_inquiry_and_the_seller_is_notified(): void
    {
        Notification::fake();
        $seller = User::factory()->seller()->create();
        $listing = Listing::factory()->ownedBy($seller)->published()->create();

        $this->postJson('/api/v1/inquiries', [
            'listing_slug' => $listing->slug,
            'first_name' => 'Asha',
            'email' => 'asha@example.com',
            'message' => 'Is this still available for viewing?',
        ])->assertCreated();

        $this->assertDatabaseHas('inquiries', ['listing_id' => $listing->id, 'seller_id' => $seller->id]);
        $this->flushCounters();
        $this->assertSame(1, $listing->fresh()->inquiry_count);
        Notification::assertSentTo($seller, NewInquiryNotification::class);
    }

    #[Test]
    public function the_contact_form_works_without_a_listing(): void
    {
        $this->postJson('/api/v1/inquiries', [
            'first_name' => 'Asha',
            'email' => 'asha@example.com',
            'message' => 'A general question about your platform.',
        ])->assertCreated();

        $this->assertDatabaseHas('inquiries', ['listing_id' => null, 'source' => 'contact_page']);
    }

    #[Test]
    public function the_honeypot_rejects_bot_submissions(): void
    {
        $this->postJson('/api/v1/inquiries', [
            'first_name' => 'Bot',
            'email' => 'bot@example.com',
            'message' => 'Buy cheap things at my website now.',
            'website' => 'http://spam.example.com', // hidden field a human never fills
        ])->assertStatus(422);

        $this->assertDatabaseCount('inquiries', 0);
    }

    #[Test]
    public function a_seller_cannot_inquire_about_their_own_listing(): void
    {
        $seller = User::factory()->seller()->create();
        $listing = Listing::factory()->ownedBy($seller)->published()->create();

        $this->actingAs($seller, 'sanctum')->postJson('/api/v1/inquiries', [
            'listing_slug' => $listing->slug,
            'first_name' => 'Juma',
            'email' => 'juma@example.com',
            'message' => 'Talking to myself about this listing.',
        ])->assertStatus(409);
    }

    #[Test]
    public function a_seller_can_read_and_reply_to_an_inquiry(): void
    {
        Notification::fake();
        $seller = User::factory()->seller()->create();
        $listing = Listing::factory()->ownedBy($seller)->published()->create();

        $uuid = $this->postJson('/api/v1/inquiries', [
            'listing_slug' => $listing->slug,
            'first_name' => 'Asha',
            'email' => 'asha@example.com',
            'message' => 'Is this still available for viewing?',
        ])->json('data.uuid');

        // Opening it marks it read.
        $this->actingAs($seller, 'sanctum')->getJson("/api/v1/seller/inquiries/{$uuid}")
            ->assertOk()->assertJsonPath('data.status', 'read');

        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/v1/seller/inquiries/{$uuid}/reply", ['body' => 'Yes, viewings are on Saturday.'])
            ->assertOk()->assertJsonPath('data.status', 'replied');
    }

    #[Test]
    public function another_seller_cannot_read_someone_elses_inquiry(): void
    {
        Notification::fake();
        $seller = User::factory()->seller()->create();
        $intruder = User::factory()->seller()->create();
        $listing = Listing::factory()->ownedBy($seller)->published()->create();

        $uuid = $this->postJson('/api/v1/inquiries', [
            'listing_slug' => $listing->slug,
            'first_name' => 'Asha',
            'email' => 'asha@example.com',
            'message' => 'Is this still available for viewing?',
        ])->json('data.uuid');

        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/v1/seller/inquiries/{$uuid}")->assertStatus(403);
    }

    // --------------------------------------------------------------- reviews

    #[Test]
    public function a_buyer_can_review_a_listing_and_it_awaits_moderation(): void
    {
        $buyer = User::factory()->buyer()->create();
        $listing = Listing::factory()->published()->create();

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/account/reviews/{$listing->slug}", ['rating' => 5, 'body' => 'Excellent seller.'])
            ->assertCreated()
            ->assertJsonPath('data.status', ReviewStatus::Pending->value);

        // Pending reviews are invisible publicly and do not move the rating.
        $this->getJson("/api/v1/listings/{$listing->slug}/reviews")->assertOk()->assertJsonCount(0, 'data');
        $this->assertNull($listing->user->sellerProfile?->rating_avg);
    }

    #[Test]
    public function a_seller_cannot_review_their_own_listing(): void
    {
        $seller = User::factory()->seller()->create();
        $listing = Listing::factory()->ownedBy($seller)->published()->create();

        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/v1/account/reviews/{$listing->slug}", ['rating' => 5])
            ->assertStatus(409);
    }

    #[Test]
    public function a_buyer_can_only_review_a_listing_once(): void
    {
        $buyer = User::factory()->buyer()->create();
        $listing = Listing::factory()->published()->create();

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/account/reviews/{$listing->slug}", ['rating' => 5])->assertCreated();

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/account/reviews/{$listing->slug}", ['rating' => 1])->assertStatus(409);
    }

    #[Test]
    public function approving_a_review_updates_the_seller_rating(): void
    {
        $moderator = User::factory()->moderator()->create();
        $seller = User::factory()->seller()->create();
        $listing = Listing::factory()->ownedBy($seller)->published()->create();

        $uuid = $this->actingAs(User::factory()->buyer()->create(), 'sanctum')
            ->postJson("/api/v1/account/reviews/{$listing->slug}", ['rating' => 4])->json('data.uuid');

        $this->actingAs($moderator, 'sanctum')
            ->postJson("/api/v1/admin/reviews/{$uuid}/moderate", ['status' => 'approved'])
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $profile = $seller->fresh()->sellerProfile;
        $this->assertSame(1, $profile->rating_count);
        $this->assertEquals(4.0, (float) $profile->rating_avg);

        $this->getJson("/api/v1/listings/{$listing->slug}/reviews")->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_seller_can_reply_once_to_a_review(): void
    {
        $seller = User::factory()->seller()->create();
        $listing = Listing::factory()->ownedBy($seller)->published()->create();

        $review = Review::factory()->create([
            'seller_id' => $seller->id,
            'listing_id' => $listing->id,
            'reviewer_id' => User::factory()->buyer()->create()->id,
            'rating' => 3,
        ]);

        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/v1/account/reviews/{$review->uuid}/reply", ['body' => 'Thanks for the feedback.'])
            ->assertOk();

        // Only one public response per review.
        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/v1/account/reviews/{$review->uuid}/reply", ['body' => 'Actually, one more thing.'])
            ->assertStatus(403);
    }

    #[Test]
    public function rating_must_be_between_one_and_five(): void
    {
        $buyer = User::factory()->buyer()->create();
        $listing = Listing::factory()->published()->create();

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/account/reviews/{$listing->slug}", ['rating' => 9])
            ->assertStatus(422)->assertJsonValidationErrors('rating');
    }
}
