<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Listing;

use App\Models\Listing;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Keyword search.
 *
 * Uses DatabaseTruncation rather than RefreshDatabase ON PURPOSE: InnoDB's
 * FULLTEXT index is maintained at COMMIT, so rows written inside an open
 * transaction are invisible to MATCH(). Under RefreshDatabase every search here
 * would return zero rows and the tests would be silently meaningless.
 *
 * The cost is a slower class; the alternative is untested search.
 */
class ListingSearchTest extends TestCase
{
    use DatabaseTruncation;

    protected bool $seed = true;

    /**
     * DatabaseTruncation COMMITS its rows. RefreshDatabase, used by every other
     * suite, does not re-migrate — it just opens a transaction over whatever is
     * already there. Without this cleanup, listings written here leak into
     * later suites and break their counts. Found exactly that way.
     */
    protected function tearDown(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['listing_attribute_values', 'listing_status_histories', 'listing_views',
            'favorites', 'inquiries', 'reviews', 'media', 'listings'] as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        parent::tearDown();
    }

    #[Test]
    public function keyword_search_uses_the_fulltext_index(): void
    {
        Listing::factory()->published()->create(['title' => 'Masaki beachfront apartment for lease']);
        Listing::factory()->published()->create(['title' => 'Kariakoo commercial warehouse space']);

        $response = $this->getJson('/api/v1/listings?q=masaki')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertStringContainsString('Masaki', $response->json('data.0.title'));
    }

    #[Test]
    public function search_matches_a_word_prefix_for_typeahead(): void
    {
        Listing::factory()->published()->create(['title' => 'Kariakoo commercial warehouse space']);

        $this->getJson('/api/v1/listings?q=wareho')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function all_supplied_words_must_match(): void
    {
        Listing::factory()->published()->create(['title' => 'Masaki beachfront apartment for lease']);
        Listing::factory()->published()->create(['title' => 'Kariakoo commercial warehouse space']);

        $this->getJson('/api/v1/listings?q='.urlencode('masaki warehouse'))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function boolean_mode_operators_from_user_input_are_neutralised(): void
    {
        Listing::factory()->published()->create(['title' => 'A perfectly ordinary listing']);

        // Raw operators would be a syntax error or silently change the meaning
        // of the query, so they are stripped before they reach MySQL.
        $this->getJson('/api/v1/listings?q='.urlencode('+++ *** ((( ~~~'))->assertOk();
        $this->getJson('/api/v1/listings?q='.urlencode('" OR 1=1 --'))->assertOk();
        $this->getJson('/api/v1/listings?q='.urlencode('>ordinary <listing'))->assertOk();
    }

    #[Test]
    public function search_combines_with_other_filters(): void
    {
        Listing::factory()->published()->inCategory('property-apartments')
            ->create(['title' => 'Masaki beachfront apartment for lease']);
        Listing::factory()->published()->inCategory('vehicles-cars')
            ->create(['title' => 'Masaki car dealership stock clearance']);

        $this->getJson('/api/v1/listings?q=masaki&category=property')
            ->assertOk()->assertJsonCount(1, 'data');
    }
}
