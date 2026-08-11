<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Seller;

use App\Jobs\GenerateImageVariants;
use App\Models\Listing;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $seller;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seller = User::factory()->seller()->create();
        $this->listing = Listing::factory()->ownedBy($this->seller)->create();
    }

    private function image(string $name = 'photo.jpg', int $w = 1200, int $h = 900): UploadedFile
    {
        return UploadedFile::fake()->image($name, $w, $h);
    }

    #[Test]
    public function a_seller_can_upload_an_image_and_variants_are_queued(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->seller, 'sanctum')
            ->post("/api/v1/seller/listings/{$this->listing->uuid}/media", ['image' => $this->image()])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['uuid', 'url', 'is_primary', 'processing_status']]);

        $this->assertTrue($response->json('data.is_primary'));
        $this->assertSame('pending', $response->json('data.processing_status'));

        // Resizing happens off the request path.
        Queue::assertPushed(GenerateImageVariants::class);

        $media = Media::firstOrFail();
        Storage::disk('public')->assertExists($media->path);
    }

    #[Test]
    public function the_first_image_becomes_primary_and_later_ones_do_not(): void
    {
        Queue::fake();

        $this->actingAs($this->seller, 'sanctum')
            ->post("/api/v1/seller/listings/{$this->listing->uuid}/media", ['image' => $this->image('a.jpg')])
            ->assertCreated();

        $second = $this->actingAs($this->seller, 'sanctum')
            ->post("/api/v1/seller/listings/{$this->listing->uuid}/media", ['image' => $this->image('b.jpg')])
            ->assertCreated();

        $this->assertFalse($second->json('data.is_primary'));
        $this->assertSame(1, Media::where('is_primary', true)->count());
    }

    #[Test]
    public function a_non_image_upload_is_rejected_by_magic_bytes_not_extension(): void
    {
        Queue::fake();

        // Named .jpg but actually a PDF — the extension must not be trusted.
        $fake = UploadedFile::fake()->create('malware.jpg', 10, 'application/pdf');

        $this->actingAs($this->seller, 'sanctum')
            ->post("/api/v1/seller/listings/{$this->listing->uuid}/media", ['image' => $fake])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertDatabaseCount('media', 0);
    }

    #[Test]
    public function svg_uploads_are_refused(): void
    {
        Queue::fake();

        // SVG can carry script — excluded entirely.
        $svg = UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml');

        $this->actingAs($this->seller, 'sanctum')
            ->post("/api/v1/seller/listings/{$this->listing->uuid}/media", ['image' => $svg])
            ->assertStatus(422);
    }

    #[Test]
    public function the_per_listing_image_cap_is_enforced(): void
    {
        Queue::fake();
        config()->set('saka.media.max_images_per_listing', 2);

        $this->actingAs($this->seller, 'sanctum')
            ->post("/api/v1/seller/listings/{$this->listing->uuid}/media", ['image' => $this->image('1.jpg')])->assertCreated();
        $this->actingAs($this->seller, 'sanctum')
            ->post("/api/v1/seller/listings/{$this->listing->uuid}/media", ['image' => $this->image('2.jpg')])->assertCreated();

        $this->actingAs($this->seller, 'sanctum')
            ->post("/api/v1/seller/listings/{$this->listing->uuid}/media", ['image' => $this->image('3.jpg')])
            ->assertStatus(409);
    }

    #[Test]
    public function images_can_be_reordered_and_promoted_to_primary(): void
    {
        Queue::fake();

        $first = $this->actingAs($this->seller, 'sanctum')
            ->post("/api/v1/seller/listings/{$this->listing->uuid}/media", ['image' => $this->image('a.jpg')])->json('data.uuid');
        $second = $this->actingAs($this->seller, 'sanctum')
            ->post("/api/v1/seller/listings/{$this->listing->uuid}/media", ['image' => $this->image('b.jpg')])->json('data.uuid');

        $this->actingAs($this->seller, 'sanctum')
            ->patchJson("/api/v1/seller/listings/{$this->listing->uuid}/media/reorder", ['order' => [$second, $first]])
            ->assertOk();

        $this->assertLessThan(
            Media::where('uuid', $first)->value('position'),
            Media::where('uuid', $second)->value('position'),
        );

        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/seller/listings/{$this->listing->uuid}/media/{$second}/primary")
            ->assertOk()->assertJsonPath('data.is_primary', true);

        $this->assertSame(1, Media::where('is_primary', true)->count());
    }

    #[Test]
    public function deleting_the_primary_image_promotes_another(): void
    {
        Queue::fake();

        $first = $this->actingAs($this->seller, 'sanctum')
            ->post("/api/v1/seller/listings/{$this->listing->uuid}/media", ['image' => $this->image('a.jpg')])->json('data.uuid');
        $this->actingAs($this->seller, 'sanctum')
            ->post("/api/v1/seller/listings/{$this->listing->uuid}/media", ['image' => $this->image('b.jpg')])->json('data.uuid');

        $this->actingAs($this->seller, 'sanctum')
            ->deleteJson("/api/v1/seller/listings/{$this->listing->uuid}/media/{$first}")
            ->assertOk();

        // A gallery must never be left without a primary image.
        $this->assertSame(1, Media::count());
        $this->assertSame(1, Media::where('is_primary', true)->count());
    }

    #[Test]
    public function an_image_can_be_replaced_keeping_its_position(): void
    {
        Queue::fake();

        $uuid = $this->actingAs($this->seller, 'sanctum')
            ->post("/api/v1/seller/listings/{$this->listing->uuid}/media", ['image' => $this->image('a.jpg')])->json('data.uuid');

        $replacement = $this->actingAs($this->seller, 'sanctum')
            ->post("/api/v1/seller/listings/{$this->listing->uuid}/media/{$uuid}/replace", ['image' => $this->image('b.jpg')])
            ->assertOk();

        $this->assertNotSame($uuid, $replacement->json('data.uuid'));
        $this->assertTrue($replacement->json('data.is_primary'));
        $this->assertSame(1, Media::count());
    }

    #[Test]
    public function another_seller_cannot_manage_these_images(): void
    {
        Queue::fake();
        $intruder = User::factory()->seller()->create();

        $this->actingAs($intruder, 'sanctum')
            ->post("/api/v1/seller/listings/{$this->listing->uuid}/media", ['image' => $this->image()])
            ->assertStatus(403);
    }

    #[Test]
    public function media_from_another_listing_cannot_be_targeted(): void
    {
        Queue::fake();
        $other = Listing::factory()->ownedBy($this->seller)->create();

        $uuid = $this->actingAs($this->seller, 'sanctum')
            ->post("/api/v1/seller/listings/{$other->uuid}/media", ['image' => $this->image()])->json('data.uuid');

        // Route-model binding resolves media globally, so ownership is re-checked.
        $this->actingAs($this->seller, 'sanctum')
            ->deleteJson("/api/v1/seller/listings/{$this->listing->uuid}/media/{$uuid}")
            ->assertStatus(404);
    }

    #[Test]
    public function the_variant_job_produces_webp_derivatives(): void
    {
        // Runs the real job (queue not faked) to prove the pipeline works.
        $this->actingAs($this->seller, 'sanctum')
            ->post("/api/v1/seller/listings/{$this->listing->uuid}/media", ['image' => $this->image('big.jpg', 1600, 1200)])
            ->assertCreated();

        $media = Media::firstOrFail()->fresh();

        $this->assertSame('complete', $media->processing_status);
        $this->assertNotEmpty($media->variants);

        foreach (['thumb', 'card', 'detail', 'full'] as $variant) {
            $this->assertArrayHasKey($variant, $media->variants);
            Storage::disk('public')->assertExists($media->variants[$variant]['path']);
        }

        // scaleDown never enlarges: the thumb must be smaller than the original.
        $this->assertLessThan(1600, $media->variants['thumb']['width']);
    }
}
