<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domain\Listing\Enums\ListingStatus;
use App\Models\Category;
use App\Models\District;
use App\Models\Listing;
use App\Models\Region;
use App\Models\User;
use App\Services\Engagement\FavoriteService;
use App\Services\Listing\ListingService;
use App\Services\Listing\ListingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Duplicate requests and near-simultaneous writes.
 *
 * True parallelism is not reachable from PHPUnit, so these assert the DEFENCE
 * rather than simulate the race: the invariants are enforced by database
 * constraints, which hold under real concurrency, rather than by check-then-act
 * in PHP, which does not.
 */
class ConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    #[Test]
    public function duplicate_favorite_requests_create_exactly_one_row(): void
    {
        $user = User::factory()->buyer()->create();
        $listing = Listing::factory()->published()->create();
        $service = app(FavoriteService::class);

        // Simulates two requests arriving together: the second violates the
        // unique key and is absorbed, rather than double-counting.
        $first = $service->add($user, $listing);
        $second = $service->add($user, $listing);

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertDatabaseCount('favorites', 1);
        $this->flushCounters();
        $this->assertSame(1, $listing->fresh()->favorite_count);
    }

    #[Test]
    public function repeated_view_writes_are_absorbed_by_the_unique_key(): void
    {
        $listing = Listing::factory()->published()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->getJson("/api/v1/listings/{$listing->slug}")->assertOk();
        }

        // One counted view per client per day, enforced in the DATABASE.
        $this->assertDatabaseCount('listing_views', 1);
        $this->flushCounters();
        $this->assertSame(1, $listing->fresh()->view_count);
    }

    #[Test]
    public function a_duplicate_review_is_refused_by_the_unique_constraint(): void
    {
        $user = User::factory()->buyer()->create();
        $listing = Listing::factory()->published()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/account/reviews/{$listing->slug}", ['rating' => 5])->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/account/reviews/{$listing->slug}", ['rating' => 1])->assertStatus(409);

        $this->assertDatabaseCount('reviews', 1);
    }

    #[Test]
    public function a_repeated_status_transition_is_idempotent_not_double_logged(): void
    {
        $seller = User::factory()->seller()->create();
        $listing = Listing::factory()->ownedBy($seller)->published()->create();
        $service = app(ListingStatusService::class);

        $service->transition($listing, ListingStatus::Paused, $seller);
        $service->transition($listing->fresh(), ListingStatus::Paused, $seller);

        // Same-state transition is a no-op: no second history row.
        $this->assertSame(1, $listing->statusHistories()->count());
    }

    #[Test]
    public function two_listings_created_in_the_same_moment_get_distinct_slugs(): void
    {
        $seller = User::factory()->seller()->create();
        $service = app(ListingService::class);

        $payload = [
            'title' => 'Identical Masaki apartment title',
            'category_id' => Category::where('slug', 'property-apartments')->value('id'),
            'region_id' => Region::where('slug', 'dar-es-salaam')->value('id'),
            'district_id' => District::where('slug', 'kinondoni')->value('id'),
            'attributes' => ['beds' => 1, 'bathrooms' => 1, 'sqft' => 500],
        ];

        $a = $service->create($seller, $payload);
        $b = $service->create($seller, $payload);

        $this->assertNotSame($a->slug, $b->slug);
        $this->assertSame(2, Listing::count());
    }

    #[Test]
    public function unfavoriting_more_times_than_favoriting_cannot_underflow(): void
    {
        $user = User::factory()->buyer()->create();
        $listing = Listing::factory()->published()->create();
        $service = app(FavoriteService::class);

        $service->add($user, $listing);

        // The flush clamps at zero: an unguarded decrement would throw on the
        // UNSIGNED column.
        $service->remove($user, $listing);
        $service->remove($user, $listing);
        $service->remove($user, $listing);

        $this->flushCounters();
        $this->assertSame(0, $listing->fresh()->favorite_count);
    }
}
