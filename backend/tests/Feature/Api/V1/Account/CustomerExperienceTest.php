<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Account;

use App\Domain\Engagement\Enums\NotificationType;
use App\Domain\Engagement\Enums\ReviewStatus;
use App\Domain\Listing\Enums\ListingStatus;
use App\Models\Favorite;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Engagement\CustomerNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Milestone 13: the customer's own surface.
 *
 * The emphasis is on the boundaries that would be expensive to get wrong — one
 * customer reading another's inquiries or notifications, a review edited into
 * something a moderator never saw, or a saved-then-removed favourite silently
 * disappearing from history.
 */
class CustomerExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function customer(): User
    {
        return User::factory()->buyer()->create();
    }

    private function publishedListing(?User $seller = null): Listing
    {
        return Listing::factory()
            ->ownedBy($seller ?? User::factory()->seller()->create())
            ->published()
            ->create();
    }

    private function business(User $owner): SellerProfile
    {
        // The seller factory already creates a profile — a second one would
        // violate the unique user_id.
        $profile = SellerProfile::query()->firstOrNew(['user_id' => $owner->getKey()]);

        $profile->forceFill([
            'user_id' => $owner->getKey(),
            'slug' => 'business-'.Str::lower(Str::random(8)),
            'display_name' => 'Kilimani Traders',
        ])->save();

        return $profile->refresh();
    }

    // ------------------------------------------------------------ favorites

    #[Test]
    public function a_customer_can_save_a_listing_and_a_business(): void
    {
        $customer = $this->customer();
        $listing = $this->publishedListing();
        $business = $this->business(User::factory()->seller()->create());

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/account/favorites/{$listing->slug}")
            ->assertOk()
            ->assertJsonPath('data.favorited', true);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/account/favorites/businesses/{$business->slug}")
            ->assertOk()
            ->assertJsonPath('data.favorited', true);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/account/favorites/listings')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/account/favorites/businesses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $business->slug);
    }

    #[Test]
    public function saving_twice_does_not_double_count(): void
    {
        $customer = $this->customer();
        $listing = $this->publishedListing();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/account/favorites/{$listing->slug}")
            ->assertOk()->assertJsonPath('data.created', true);

        // Second tap changes nothing — the row and the counter both hold.
        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/account/favorites/{$listing->slug}")
            ->assertOk()->assertJsonPath('data.created', false);

        $this->assertSame(1, Favorite::query()->where('user_id', $customer->id)->count());
    }

    #[Test]
    public function removing_a_favorite_keeps_it_in_history(): void
    {
        $customer = $this->customer();
        $listing = $this->publishedListing();

        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/account/favorites/{$listing->slug}");
        $this->actingAs($customer, 'sanctum')->deleteJson("/api/v1/account/favorites/{$listing->slug}")->assertOk();

        // Gone from the saved list...
        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/account/favorites/listings')
            ->assertOk()->assertJsonCount(0, 'data');

        // ...but "I saved something last month and can't find it" is answerable.
        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/account/favorites/history')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.still_saved', false)
            ->assertJsonPath('data.0.target.slug', $listing->slug);
    }

    #[Test]
    public function re_saving_restores_the_original_row_rather_than_adding_another(): void
    {
        $customer = $this->customer();
        $listing = $this->publishedListing();

        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/account/favorites/{$listing->slug}");
        $this->actingAs($customer, 'sanctum')->deleteJson("/api/v1/account/favorites/{$listing->slug}");
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/account/favorites/{$listing->slug}")->assertOk();

        $this->assertSame(1, Favorite::query()->where('user_id', $customer->id)->count());
        $this->assertNull(Favorite::query()->where('user_id', $customer->id)->value('removed_at'));
    }

    // ------------------------------------------------------------- reviews

    #[Test]
    public function a_customer_can_edit_their_own_review(): void
    {
        $customer = $this->customer();
        $listing = $this->publishedListing();

        $review = Review::factory()->create([
            'listing_id' => $listing->id,
            'seller_id' => $listing->user_id,
            'reviewer_id' => $customer->id,
            'status' => ReviewStatus::Approved,
            'rating' => 2,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/v1/account/reviews/{$review->uuid}", [
                'rating' => 5,
                'body' => 'The landlord fixed everything — updating my review.',
            ])
            ->assertOk()
            ->assertJsonPath('data.rating', 5);

        $this->assertSame(5, (int) $review->fresh()->rating);
    }

    #[Test]
    public function an_edited_review_goes_back_for_moderation(): void
    {
        config(['saka.listings.require_moderation' => true]);

        $customer = $this->customer();
        $listing = $this->publishedListing();

        $review = Review::factory()->create([
            'listing_id' => $listing->id,
            'seller_id' => $listing->user_id,
            'reviewer_id' => $customer->id,
            'status' => ReviewStatus::Approved,
        ]);

        // Otherwise a review could be published as something innocuous and
        // edited into abuse the moment it was approved.
        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/v1/account/reviews/{$review->uuid}", ['body' => 'Rewritten after approval.'])
            ->assertOk()
            ->assertJsonPath('meta.pending_remoderation', true);

        $this->assertSame(ReviewStatus::Pending, $review->fresh()->status);
    }

    #[Test]
    public function a_customer_cannot_edit_someone_elses_review(): void
    {
        $listing = $this->publishedListing();

        $review = Review::factory()->create([
            'listing_id' => $listing->id,
            'seller_id' => $listing->user_id,
            'reviewer_id' => $this->customer()->id,
            'status' => ReviewStatus::Approved,
        ]);

        $this->actingAs($this->customer(), 'sanctum')
            ->patchJson("/api/v1/account/reviews/{$review->uuid}", ['rating' => 1])
            ->assertForbidden();
    }

    #[Test]
    public function reporting_a_review_does_not_hide_it(): void
    {
        $listing = $this->publishedListing();

        $review = Review::factory()->create([
            'listing_id' => $listing->id,
            'seller_id' => $listing->user_id,
            'reviewer_id' => $this->customer()->id,
            'status' => ReviewStatus::Approved,
        ]);

        $this->actingAs($this->customer(), 'sanctum')
            ->postJson("/api/v1/account/reviews/{$review->uuid}/report", [
                'reason' => 'offensive',
                'details' => 'This review contains a personal attack on the owner.',
            ])
            ->assertOk();

        $this->assertSame(ReviewStatus::Approved, $review->fresh()->status);
    }

    // ---------------------------------------------------------- inquiries

    #[Test]
    public function an_inquiry_sent_with_a_bearer_token_is_attributed_to_that_account(): void
    {
        $customer = $this->customer();
        $listing = $this->publishedListing();
        $token = $customer->createToken('test')->plainTextToken;

        // Deliberately NOT actingAs: that sets the default guard's resolver and
        // hides the fact that this public route never resolves sanctum on its
        // own. A real browser only ever sends a bearer token.
        $this->withToken($token)
            ->postJson('/api/v1/inquiries', [
                'listing_slug' => $listing->slug,
                'message' => 'Could I arrange a viewing this weekend?',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('inquiries', [
            'sender_user_id' => $customer->getKey(),
            'email' => $customer->email,
        ]);
    }

    #[Test]
    public function a_signed_in_sender_cannot_put_someone_elses_name_on_an_inquiry(): void
    {
        $customer = $this->customer();
        $listing = $this->publishedListing();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/inquiries', [
                'listing_slug' => $listing->slug,
                'first_name' => 'Someone',
                'last_name' => 'Else',
                'email' => 'victim@example.com',
                'message' => 'Is this still available for a viewing?',
            ])
            ->assertCreated();

        // The row is stamped with sender_user_id, so a mismatched name and
        // address would show the seller an identity that is not the account's.
        $this->assertDatabaseHas('inquiries', [
            'sender_user_id' => $customer->getKey(),
            'email' => $customer->email,
            'first_name' => $customer->first_name,
        ]);
    }

    #[Test]
    public function a_signed_in_customer_need_not_retype_their_details(): void
    {
        $customer = $this->customer();
        $listing = $this->publishedListing();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/inquiries', [
                'listing_slug' => $listing->slug,
                'message' => 'Could I arrange a viewing this weekend?',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('inquiries', ['sender_user_id' => $customer->getKey()]);
    }

    #[Test]
    public function a_customer_sees_only_their_own_inquiries(): void
    {
        $customer = $this->customer();
        $other = $this->customer();
        $listing = $this->publishedListing();

        $this->inquiryFrom($customer, $listing);
        $this->inquiryFrom($other, $listing);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/account/inquiries')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function an_inquiry_timeline_reports_only_what_actually_happened(): void
    {
        $customer = $this->customer();
        $listing = $this->publishedListing();
        $inquiry = $this->inquiryFrom($customer, $listing);

        // Unread and unanswered: one event, not a fabricated conversation.
        $events = $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/account/inquiries/{$inquiry->uuid}")
            ->assertOk()
            ->json('meta.timeline');

        $this->assertSame(['sent'], array_column($events, 'event'));

        $inquiry->forceFill(['read_at' => now(), 'replied_at' => now(), 'status' => 'replied'])->save();

        $events = $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/account/inquiries/{$inquiry->uuid}")
            ->assertOk()
            ->json('meta.timeline');

        $this->assertSame(['sent', 'read', 'replied'], array_column($events, 'event'));
    }

    #[Test]
    public function another_customers_inquiry_is_not_found_rather_than_forbidden(): void
    {
        $inquiry = $this->inquiryFrom($this->customer(), $this->publishedListing());

        $this->actingAs($this->customer(), 'sanctum')
            ->getJson("/api/v1/account/inquiries/{$inquiry->uuid}")
            ->assertNotFound();
    }

    // ------------------------------------------------------ notifications

    #[Test]
    public function replying_to_an_inquiry_notifies_the_customer(): void
    {
        $customer = $this->customer();
        $seller = User::factory()->seller()->withVerifiedPhone()->create();
        $listing = $this->publishedListing($seller);
        $inquiry = $this->inquiryFrom($customer, $listing);

        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/v1/seller/inquiries/{$inquiry->uuid}/reply", [
                'body' => 'Yes, it is still available this weekend.',
            ])
            ->assertOk();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/account/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', NotificationType::InquiryReplied->value)
            ->assertJsonPath('meta.unread_count', 1);
    }

    #[Test]
    public function a_price_drop_reaches_everyone_who_saved_the_listing(): void
    {
        $seller = User::factory()->seller()->withVerifiedPhone()->create();
        $listing = $this->publishedListing($seller);

        $watcher = $this->customer();
        $this->actingAs($watcher, 'sanctum')->postJson("/api/v1/account/favorites/{$listing->slug}");

        $this->actingAs($seller, 'sanctum')
            ->patchJson("/api/v1/seller/listings/{$listing->uuid}", ['price' => (int) $listing->price - 50_000])
            ->assertOk();

        $this->actingAs($watcher, 'sanctum')
            ->getJson('/api/v1/account/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.type', NotificationType::FavoritePriceChanged->value);
    }

    #[Test]
    public function a_silenced_category_is_not_delivered_at_all(): void
    {
        $customer = $this->customer();
        $listing = $this->publishedListing();

        $customer->forceFill(['notification_preferences' => ['favorite_alerts' => false]])->save();

        app(CustomerNotifier::class)->favoriteListingChanged(
            $listing,
            NotificationType::FavoritePriceChanged,
            'Price dropped',
            'Cheaper now.',
        );

        // Suppressed at write time, not filtered on read: a notification the
        // customer switched off should never exist.
        $this->assertSame(0, DB::table('notifications')->count());
    }

    #[Test]
    public function a_customer_cannot_read_or_dismiss_another_persons_notification(): void
    {
        $owner = $this->customer();
        $intruder = $this->customer();

        $id = (string) Str::uuid7();
        DB::table('notifications')->insert([
            'id' => $id,
            'type' => NotificationType::InquiryReplied->value,
            'notifiable_type' => $owner->getMorphClass(),
            'notifiable_id' => $owner->getKey(),
            'data' => json_encode(['title' => 'Private']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/v1/account/notifications/{$id}/read")
            ->assertNotFound();

        $this->actingAs($intruder, 'sanctum')
            ->deleteJson("/api/v1/account/notifications/{$id}")
            ->assertNotFound();

        $this->assertDatabaseHas('notifications', ['id' => $id, 'read_at' => null]);
    }

    #[Test]
    public function notification_preferences_merge_rather_than_replace(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer, 'sanctum')
            ->patchJson('/api/v1/account/notifications/preferences', [
                'preferences' => ['favorite_alerts' => false],
            ])
            ->assertOk();

        $this->actingAs($customer, 'sanctum')
            ->patchJson('/api/v1/account/notifications/preferences', [
                'preferences' => ['review_replies' => false],
            ])
            ->assertOk();

        // Setting one switch must not reset the other to its default.
        $preferences = collect($this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/account/notifications/preferences')->json('data'))
            ->pluck('enabled', 'key');

        $this->assertFalse($preferences['favorite_alerts']);
        $this->assertFalse($preferences['review_replies']);
        $this->assertTrue($preferences['inquiry_replies']);
    }

    // ------------------------------------------------------------- account

    #[Test]
    public function closing_an_account_frees_the_email_and_takes_listings_down(): void
    {
        $seller = User::factory()->seller()->withVerifiedPhone()->create(['password' => 'secret-password']);
        $listing = $this->publishedListing($seller);
        $email = $seller->email;

        $this->actingAs($seller, 'sanctum')
            ->deleteJson('/api/v1/account', ['password' => 'secret-password'])
            ->assertOk();

        $this->assertSoftDeleted('users', ['id' => $seller->id]);
        // Nothing stays on sale with no one behind it.
        $this->assertSame(ListingStatus::Archived, $listing->fresh()->status);
        // And the address can be used again.
        $this->assertDatabaseMissing('users', ['email' => $email, 'deleted_at' => null]);
    }

    #[Test]
    public function closing_an_account_requires_the_password(): void
    {
        $customer = User::factory()->buyer()->create(['password' => 'secret-password']);

        // A stolen session must not be enough to destroy an account.
        $this->actingAs($customer, 'sanctum')
            ->deleteJson('/api/v1/account', ['password' => 'not-the-password'])
            ->assertStatus(401);

        $this->assertNotSoftDeleted('users', ['id' => $customer->id]);
    }

    // -------------------------------------------------------------- search

    #[Test]
    public function searching_records_history_and_feeds_popular_searches(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/listings?q=masaki apartment')
            ->assertOk();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/account/search-history')
            ->assertOk()
            ->assertJsonPath('data.0.query', 'masaki apartment');
    }

    #[Test]
    public function paging_through_results_is_one_search_not_five(): void
    {
        $customer = $this->customer();

        foreach ([1, 2, 3] as $page) {
            $this->actingAs($customer, 'sanctum')
                ->getJson("/api/v1/listings?q=arusha&page={$page}")
                ->assertOk();
        }

        // Otherwise one scrolling user could dominate the popular list.
        $this->assertSame(1, DB::table('search_queries')->where('query', 'arusha')->count());
    }

    #[Test]
    public function search_suggestions_cover_more_than_listing_titles(): void
    {
        $listing = $this->publishedListing();
        $term = mb_substr($listing->title, 0, 6);

        $data = $this->getJson('/api/v1/search/suggestions?q='.urlencode($term))
            ->assertOk()
            ->json('data');

        // A customer typing a place or a trade must not get an empty box.
        $this->assertArrayHasKey('listings', $data);
        $this->assertArrayHasKey('businesses', $data);
        $this->assertArrayHasKey('categories', $data);
        $this->assertArrayHasKey('places', $data);
    }

    #[Test]
    public function recently_viewed_reflects_what_this_customer_opened(): void
    {
        $customer = $this->customer();
        $listing = $this->publishedListing();

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/listings/{$listing->slug}")
            ->assertOk();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/account/recently-viewed')
            ->assertOk()
            ->assertJsonPath('data.0.slug', $listing->slug);
    }

    private function inquiryFrom(User $sender, Listing $listing): Inquiry
    {
        $inquiry = new Inquiry;
        $inquiry->forceFill([
            'uuid' => (string) Str::uuid7(),
            'listing_id' => $listing->getKey(),
            'seller_id' => $listing->user_id,
            'sender_user_id' => $sender->getKey(),
            'first_name' => $sender->first_name,
            'last_name' => $sender->last_name,
            'email' => $sender->email,
            'message' => 'Is this still available?',
            'status' => 'new',
            'source' => 'listing',
        ])->save();

        return $inquiry;
    }
}
