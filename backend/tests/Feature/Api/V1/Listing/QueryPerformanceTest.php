<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Listing;

use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * N+1 regression guard.
 *
 * These assert that query COUNT stays flat as the result set grows. Without
 * them an eager-load can be dropped during a refactor and nothing fails — the
 * endpoint just gets quietly slower under real data.
 */
class QueryPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** @return array{0: mixed, 1: int} */
    private function countQueries(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = $callback();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$result, $count];
    }

    #[Test]
    public function the_listing_index_query_count_does_not_grow_with_the_page_size(): void
    {
        Listing::factory()->count(3)->published()->create();
        [$small, $smallQueries] = $this->countQueries(fn () => $this->getJson('/api/v1/listings?per_page=3'));
        $small->assertOk();

        Listing::factory()->count(27)->published()->create();
        [$large, $largeQueries] = $this->countQueries(fn () => $this->getJson('/api/v1/listings?per_page=30'));
        $large->assertOk()->assertJsonCount(30, 'data');

        // 10x the rows must not mean 10x the queries.
        $this->assertSame(
            $smallQueries,
            $largeQueries,
            "Query count grew from {$smallQueries} to {$largeQueries} — an eager load is missing.",
        );
    }

    #[Test]
    public function the_listing_index_stays_within_a_sane_query_budget(): void
    {
        Listing::factory()->count(20)->published()->create();

        [$response, $queries] = $this->countQueries(fn () => $this->getJson('/api/v1/listings?per_page=20'));
        $response->assertOk();

        // count + page + one per eager-loaded relation. Generous, but it fails
        // loudly if someone lazy-loads inside the Resource.
        $this->assertLessThanOrEqual(
            12,
            $queries,
            "The listing index used {$queries} queries for one page.",
        );
    }

    #[Test]
    public function filtering_does_not_multiply_queries_per_filter(): void
    {
        Listing::factory()->count(15)->published()->inCategory('property-apartments')->create();

        [, $plain] = $this->countQueries(fn () => $this->getJson('/api/v1/listings'));
        [, $filtered] = $this->countQueries(
            fn () => $this->getJson('/api/v1/listings?category=property&min_price=1&purpose=sale&verified=0'),
        );

        // Filter stages resolve slugs, so a small constant increase is expected;
        // anything proportional to the result set is not.
        $this->assertLessThanOrEqual($plain + 4, $filtered);
    }

    #[Test]
    public function the_detail_endpoint_stays_within_budget(): void
    {
        $listing = Listing::factory()->published()->create();

        [$response, $queries] = $this->countQueries(
            fn () => $this->getJson("/api/v1/listings/{$listing->slug}"),
        );
        $response->assertOk();

        $this->assertLessThanOrEqual(
            18,
            $queries,
            "The listing detail endpoint used {$queries} queries.",
        );
    }

    #[Test]
    public function the_seller_dashboard_is_a_bounded_number_of_aggregates(): void
    {
        $seller = User::factory()->seller()->create();
        Listing::factory()->count(25)->ownedBy($seller)->published()->create();

        [$response, $queries] = $this->countQueries(
            fn () => $this->actingAs($seller, 'sanctum')->getJson('/api/v1/seller/dashboard'),
        );
        $response->assertOk();

        // Grouped aggregates, not "load every listing and count in PHP".
        $this->assertLessThanOrEqual(
            15,
            $queries,
            "The dashboard used {$queries} queries for 25 listings.",
        );
    }

    #[Test]
    public function the_favorites_list_does_not_n_plus_one(): void
    {
        $user = User::factory()->buyer()->create();
        $listings = Listing::factory()->count(15)->published()->create();

        foreach ($listings as $listing) {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/v1/account/favorites/{$listing->slug}")->assertOk();
        }

        [$response, $queries] = $this->countQueries(
            fn () => $this->actingAs($user, 'sanctum')->getJson('/api/v1/account/favorites'),
        );
        $response->assertOk()->assertJsonCount(15, 'data');

        $this->assertLessThanOrEqual(
            12,
            $queries,
            "The favorites list used {$queries} queries for 15 listings.",
        );
    }
}
