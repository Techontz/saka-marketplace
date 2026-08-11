<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domain\Listing\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Permission boundaries and IDOR.
 *
 * Every test here corresponds to a way a real attacker probes an API: guess a
 * slug, reuse someone else's identifier, call a surface above your role.
 */
class AuthorizationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    // ------------------------------------------------------------------ IDOR

    #[Test]
    public function an_unpublished_listing_cannot_be_favorited_by_slug_enumeration(): void
    {
        $buyer = User::factory()->buyer()->create();
        $draft = Listing::factory()->status(ListingStatus::Draft)->create();

        // REGRESSION: this returned 200 while GET on the same slug returned
        // 404 — confirming the listing existed and letting anyone favourite it.
        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/account/favorites/{$draft->slug}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->assertDatabaseCount('favorites', 0);
    }

    #[Test]
    public function an_unpublished_listing_cannot_be_reviewed(): void
    {
        $buyer = User::factory()->buyer()->create();
        $draft = Listing::factory()->status(ListingStatus::Draft)->create();

        // REGRESSION: returned 201, polluting the seller's public rating with a
        // review of content nobody could read.
        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/account/reviews/{$draft->slug}", ['rating' => 1])
            ->assertStatus(404);

        $this->assertDatabaseCount('reviews', 0);
    }

    #[Test]
    public function an_owner_may_still_act_on_their_own_unpublished_listing(): void
    {
        $seller = User::factory()->seller()->create();
        $draft = Listing::factory()->ownedBy($seller)->status(ListingStatus::Draft)->create();

        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/v1/account/favorites/{$draft->slug}")
            ->assertOk();
    }

    #[Test]
    public function a_seller_cannot_read_another_sellers_listing_by_uuid(): void
    {
        $seller = User::factory()->seller()->create();
        $victim = User::factory()->seller()->create();
        $listing = Listing::factory()->ownedBy($victim)->status(ListingStatus::Draft)->create();

        $this->actingAs($seller, 'sanctum')
            ->getJson("/api/v1/seller/listings/{$listing->uuid}")
            ->assertStatus(404);
    }

    #[Test]
    public function a_rejection_reason_is_only_visible_to_the_owner(): void
    {
        $seller = User::factory()->seller()->create();
        $listing = Listing::factory()->ownedBy($seller)->published()->create();
        $listing->forceFill(['rejection_reason' => 'Internal moderator note.'])->save();

        $anon = $this->getJson("/api/v1/listings/{$listing->slug}")->assertOk();
        $this->assertArrayNotHasKey('rejection_reason', $anon->json('data'));

        $owner = $this->actingAs($seller, 'sanctum')
            ->getJson("/api/v1/listings/{$listing->slug}")->assertOk();
        $this->assertSame('Internal moderator note.', $owner->json('data.rejection_reason'));
    }

    #[Test]
    public function a_sellers_phone_is_not_exposed_on_an_unpublished_listing(): void
    {
        $seller = User::factory()->seller()->create();
        $listing = Listing::factory()->ownedBy($seller)->status(ListingStatus::Draft)->create();

        $response = $this->actingAs($seller, 'sanctum')
            ->getJson("/api/v1/listings/{$listing->slug}")->assertOk();

        // Contact details are the marketplace's product; they belong only on a
        // live listing.
        $this->assertArrayNotHasKey('phone', $response->json('data.seller') ?? []);
    }

    // ------------------------------------------------------ role boundaries

    /** @return array<string, array{0: string, 1: string}> */
    public static function adminEndpoints(): array
    {
        return [
            'pending listings' => ['GET', '/api/v1/admin/listings/pending'],
            'pending reviews' => ['GET', '/api/v1/admin/reviews/pending'],
        ];
    }

    #[Test]
    #[DataProvider('adminEndpoints')]
    public function the_admin_surface_is_closed_to_buyers_and_sellers(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertStatus(401);

        $this->actingAs(User::factory()->buyer()->create(), 'sanctum')
            ->json($method, $uri)->assertStatus(403);

        $this->app['auth']->forgetGuards();

        $this->actingAs(User::factory()->seller()->create(), 'sanctum')
            ->json($method, $uri)->assertStatus(403);
    }

    #[Test]
    public function a_moderator_cannot_perform_admin_only_actions(): void
    {
        $moderator = User::factory()->moderator()->create();
        $listing = Listing::factory()->published()->create();

        // Moderators moderate; featuring is a commercial decision.
        $this->actingAs($moderator, 'sanctum')
            ->postJson("/api/v1/admin/listings/{$listing->uuid}/feature", ['featured' => true])
            ->assertStatus(403);
    }

    #[Test]
    public function a_buyer_cannot_reply_to_an_inquiry_they_did_not_receive(): void
    {
        $seller = User::factory()->seller()->create();
        $listing = Listing::factory()->ownedBy($seller)->published()->create();

        $uuid = $this->postJson('/api/v1/inquiries', [
            'listing_slug' => $listing->slug,
            'first_name' => 'Asha', 'email' => 'asha@example.com',
            'message' => 'Is this still available for viewing?',
        ])->json('data.uuid');

        $this->actingAs(User::factory()->buyer()->create(), 'sanctum')
            ->postJson("/api/v1/seller/inquiries/{$uuid}/reply", ['body' => 'Hijacking this thread.'])
            ->assertStatus(403);
    }

    #[Test]
    public function a_review_can_only_be_deleted_by_its_author_or_a_moderator(): void
    {
        $seller = User::factory()->seller()->create();
        $listing = Listing::factory()->ownedBy($seller)->published()->create();
        $author = User::factory()->buyer()->create();

        $uuid = $this->actingAs($author, 'sanctum')
            ->postJson("/api/v1/account/reviews/{$listing->slug}", ['rating' => 2])->json('data.uuid');

        $this->app['auth']->forgetGuards();

        // The reviewed seller must not be able to delete criticism.
        $this->actingAs($seller, 'sanctum')
            ->deleteJson("/api/v1/account/reviews/{$uuid}")->assertStatus(403);

        $this->actingAs($author, 'sanctum')
            ->deleteJson("/api/v1/account/reviews/{$uuid}")->assertOk();
    }
}
