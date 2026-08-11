<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\District;
use App\Models\Listing;
use App\Models\Region;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InputHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** @return array<string, array{0: string}> */
    public static function injectionPayloads(): array
    {
        return [
            'union select' => ["' UNION SELECT * FROM users --"],
            'drop table' => ['1; DROP TABLE listings; --'],
            'or true' => ["' OR '1'='1"],
            'comment' => ['admin\'--'],
            'sleep' => ['1) OR SLEEP(5) --'],
            'null byte' => ["abc\0def"],
        ];
    }

    #[Test]
    #[DataProvider('injectionPayloads')]
    public function injection_payloads_cannot_reach_the_query_builder(string $payload): void
    {
        Listing::factory()->count(2)->published()->create();

        // Every filter is a bound parameter or a whitelisted value; none of
        // these may error, and none may widen the result set.
        foreach (['q', 'category', 'region', 'district', 'sort'] as $field) {
            $response = $this->getJson('/api/v1/listings?'.$field.'='.urlencode($payload));
            $this->assertContains($response->status(), [200, 422], "Field {$field} mishandled the payload");
        }

        // The table is still there.
        $this->assertSame(2, Listing::count());
    }

    #[Test]
    public function eav_attribute_keys_and_values_cannot_inject(): void
    {
        Listing::factory()->published()->create();

        $this->getJson('/api/v1/listings?attributes['.urlencode('beds`) OR 1=1 --').']=3')->assertOk();
        $this->getJson('/api/v1/listings?attributes[beds][min]='.urlencode('1 OR 1=1'))->assertOk();

        $this->assertNotEmpty(DB::table('listings')->get());
    }

    #[Test]
    public function stored_html_is_returned_escaped_as_json_not_executed(): void
    {
        $seller = User::factory()->seller()->create();

        $payload = '<script>alert(document.cookie)</script>';

        $response = $this->actingAs($seller, 'sanctum')->postJson('/api/v1/seller/listings', [
            'title' => 'A listing with markup '.$payload,
            'description' => $payload,
            'category_id' => Category::where('slug', 'property-apartments')->value('id'),
            'region_id' => Region::where('slug', 'dar-es-salaam')->value('id'),
            'district_id' => District::where('slug', 'kinondoni')->value('id'),
            'attributes' => ['beds' => 1, 'bathrooms' => 1, 'sqft' => 500],
        ])->assertCreated();

        // The raw body must not contain a literal <script> — angle brackets are
        // hex-escaped so the payload cannot break out of a <script> block if a
        // client ever inlines this JSON into HTML.
        $this->assertStringNotContainsString('<script>', $response->getContent());
        $this->assertStringContainsString('\\u003C', $response->getContent());

        // ...while remaining byte-identical DATA once decoded.
        $this->assertSame($payload, $response->json('data.description'));
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function oversized_input_is_rejected_rather_than_stored(): void
    {
        $seller = User::factory()->seller()->create();

        $this->actingAs($seller, 'sanctum')->postJson('/api/v1/seller/listings', [
            'title' => str_repeat('a', 5000),
            'description' => str_repeat('b', 100000),
            'category_id' => Category::where('slug', 'property-apartments')->value('id'),
            'region_id' => Region::where('slug', 'dar-es-salaam')->value('id'),
            'district_id' => District::where('slug', 'kinondoni')->value('id'),
            'attributes' => ['beds' => 1, 'bathrooms' => 1, 'sqft' => 500],
        ])->assertStatus(422)->assertJsonValidationErrors(['title', 'description']);
    }

    #[Test]
    public function unknown_fields_are_silently_ignored_not_persisted(): void
    {
        $seller = User::factory()->seller()->create();

        $response = $this->actingAs($seller, 'sanctum')->postJson('/api/v1/seller/listings', [
            'title' => 'A perfectly valid listing title',
            'category_id' => Category::where('slug', 'property-apartments')->value('id'),
            'region_id' => Region::where('slug', 'dar-es-salaam')->value('id'),
            'district_id' => District::where('slug', 'kinondoni')->value('id'),
            'attributes' => ['beds' => 1, 'bathrooms' => 1, 'sqft' => 500],
            'popularity_score' => 9999,
            'user_id' => 1,
            'slug' => 'chosen-by-the-client',
        ])->assertCreated();

        $listing = Listing::where('uuid', $response->json('data.uuid'))->firstOrFail();

        $this->assertSame($seller->id, $listing->user_id);
        $this->assertNotSame('chosen-by-the-client', $listing->slug);
        $this->assertEquals(0, (float) $listing->popularity_score);
    }

    #[Test]
    public function pagination_parameters_cannot_be_abused_to_dump_the_table(): void
    {
        Listing::factory()->count(30)->published()->create();

        $this->getJson('/api/v1/listings?per_page=100000')->assertStatus(422);
        $this->getJson('/api/v1/listings?per_page=-1')->assertStatus(422);
        $this->getJson('/api/v1/listings?per_page=abc')->assertStatus(422);
        $this->getJson('/api/v1/listings?page=-5')->assertStatus(422);

        $this->getJson('/api/v1/listings?per_page=100')->assertOk()->assertJsonCount(30, 'data');
    }

    #[Test]
    public function a_malformed_json_body_returns_the_standard_envelope(): void
    {
        $response = $this->call(
            'POST', '/api/v1/inquiries', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            '{"first_name": "broken'
        );

        $this->assertContains($response->status(), [400, 422]);
        $this->assertArrayHasKey('error', $response->json());
    }

    #[Test]
    public function error_responses_never_leak_stack_traces_or_sql(): void
    {
        $response = $this->getJson('/api/v1/listings/definitely-not-a-real-slug')->assertStatus(404);
        $body = $response->getContent();

        foreach (['vendor/laravel', 'SQLSTATE', '/Users/', 'Stack trace', 'PDOException'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }
}
