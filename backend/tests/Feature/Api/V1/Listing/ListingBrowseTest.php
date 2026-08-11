<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Listing;

use App\Domain\Listing\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ListingBrowseTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    #[Test]
    public function guests_can_browse_published_listings(): void
    {
        Listing::factory()->count(3)->published()->create();

        $this->getJson('/api/v1/listings')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [['uuid', 'slug', 'title', 'price', 'status', 'location', 'stats']],
                'links', 'meta',
            ]);
    }

    #[Test]
    public function unpublished_listings_are_hidden_from_guests(): void
    {
        Listing::factory()->published()->create(['title' => 'Visible listing here']);
        Listing::factory()->status(ListingStatus::Draft)->create(['title' => 'Secret draft listing']);
        Listing::factory()->status(ListingStatus::PendingReview)->create(['title' => 'Awaiting review here']);

        $response = $this->getJson('/api/v1/listings')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Visible listing here', $response->json('data.0.title'));
    }

    #[Test]
    public function a_seller_sees_their_own_drafts_but_not_other_sellers_drafts(): void
    {
        $seller = User::factory()->seller()->create();
        $other = User::factory()->seller()->create();

        Listing::factory()->ownedBy($seller)->status(ListingStatus::Draft)->create(['title' => 'My own draft listing']);
        Listing::factory()->ownedBy($other)->status(ListingStatus::Draft)->create(['title' => 'Their private draft']);

        $this->actingAs($seller, 'sanctum');

        $titles = collect($this->getJson('/api/v1/listings')->assertOk()->json('data'))->pluck('title');

        $this->assertContains('My own draft listing', $titles);
        $this->assertNotContains('Their private draft', $titles);
    }

    #[Test]
    public function a_moderator_sees_everything(): void
    {
        Listing::factory()->published()->create();
        Listing::factory()->status(ListingStatus::PendingReview)->create();
        Listing::factory()->status(ListingStatus::Draft)->create();

        $this->actingAs(User::factory()->moderator()->create(), 'sanctum');

        $this->getJson('/api/v1/listings')->assertOk()->assertJsonCount(3, 'data');
    }

    #[Test]
    public function a_listing_can_be_fetched_by_slug_with_full_detail(): void
    {
        $listing = Listing::factory()->published()->create();

        $this->getJson("/api/v1/listings/{$listing->slug}")
            ->assertOk()
            ->assertJsonPath('data.slug', $listing->slug)
            ->assertJsonStructure([
                'data' => ['uuid', 'slug', 'title', 'description', 'images', 'attributes', 'amenities', 'seller'],
                'meta' => ['is_favorited'],
            ]);
    }

    #[Test]
    public function an_unpublished_listing_returns_404_not_403(): void
    {
        $listing = Listing::factory()->status(ListingStatus::Draft)->create();

        // Never disclose that a resource exists.
        $this->getJson("/api/v1/listings/{$listing->slug}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function viewing_a_listing_records_a_view(): void
    {
        $listing = Listing::factory()->published()->create();

        $this->getJson("/api/v1/listings/{$listing->slug}")->assertOk();

        $this->assertDatabaseHas('listing_views', ['listing_id' => $listing->id]);
        $this->flushCounters();
        $this->assertSame(1, $listing->fresh()->view_count);
    }

    #[Test]
    public function a_seller_viewing_their_own_listing_does_not_inflate_the_count(): void
    {
        $seller = User::factory()->seller()->create();
        $listing = Listing::factory()->ownedBy($seller)->published()->create();

        $this->actingAs($seller, 'sanctum')
            ->getJson("/api/v1/listings/{$listing->slug}")->assertOk();

        $this->flushCounters();
        $this->assertSame(0, $listing->fresh()->view_count);
        $this->assertDatabaseCount('listing_views', 0);
    }

    #[Test]
    public function repeat_views_from_the_same_client_count_once_per_day(): void
    {
        $listing = Listing::factory()->published()->create();

        $this->getJson("/api/v1/listings/{$listing->slug}")->assertOk();
        $this->getJson("/api/v1/listings/{$listing->slug}")->assertOk();
        $this->getJson("/api/v1/listings/{$listing->slug}")->assertOk();

        // The unique key does the deduplication, not application code.
        $this->assertDatabaseCount('listing_views', 1);
        $this->flushCounters();
        $this->assertSame(1, $listing->fresh()->view_count);
    }

    #[Test]
    public function similar_trending_featured_and_recommended_endpoints_work(): void
    {
        $listing = Listing::factory()->published()->inCategory('property-apartments')->create();
        Listing::factory()->count(2)->published()->inCategory('property-apartments')->create();
        Listing::factory()->published()->featured()->create();

        $this->getJson("/api/v1/listings/{$listing->slug}/similar")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/listings/trending')->assertOk();
        $this->getJson('/api/v1/listings/featured')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/listings/recommended')->assertOk();
    }

    #[Test]
    public function pagination_is_bounded_and_supports_cursor_mode(): void
    {
        Listing::factory()->count(25)->published()->create();

        $this->getJson('/api/v1/listings?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10);

        // per_page is capped so a client cannot request the whole table.
        $this->getJson('/api/v1/listings?per_page=99999')->assertStatus(422);

        $this->getJson('/api/v1/listings?cursor=&use_cursor=1')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['per_page']]);
    }
}
