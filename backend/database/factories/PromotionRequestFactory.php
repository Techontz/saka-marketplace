<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Advertising\Enums\AdPlacement;
use App\Domain\Advertising\Enums\PromotionRequestStatus;
use App\Models\Listing;
use App\Models\Media;
use App\Models\PromotionRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PromotionRequest> */
class PromotionRequestFactory extends Factory
{
    protected $model = PromotionRequest::class;

    /**
     * Defaults to a SUBMITTED, approvable request.
     *
     * Most tests are about what happens to a request in the queue, so the
     * default is the state an administrator would find one in — pending, dated
     * in the future, with artwork. The states below take it out of
     * approvability one reason at a time.
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'user_id' => User::factory()->seller(),
            'promotable_type' => Listing::class,
            'promotable_id' => Listing::factory(),
            'placement' => AdPlacement::ListingsInline,
            'requested_start' => now()->addDay()->toDateString(),
            'requested_end' => now()->addWeek()->toDateString(),
            'status' => PromotionRequestStatus::Pending,
            'headline' => 'Modern two-bedroom apartment in Masaki',
            'body' => 'Sea view, secure parking, ready to move in.',
            'cta_label' => 'View listing',
        ];
    }

    public function status(PromotionRequestStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /**
     * Attach real artwork.
     *
     * A Media row rather than a bare id, because approval copies the id onto
     * the creative and a dangling foreign key would fail the insert rather than
     * the assertion the test is making.
     */
    public function withArtwork(): static
    {
        return $this->afterCreating(function (PromotionRequest $promotion): void {
            /*
             * forceCreate, not create.
             *
             * `Media` guards `uuid` — it is assigned by the HasUuid trait and
             * must never come from a request body — so mass assignment drops it
             * and the insert fails on a non-null column. Factories elsewhere get
             * away with setting guarded attributes because Laravel wraps
             * Factory::create in Model::unguarded(); this row is created
             * directly inside an afterCreating hook, which is outside that.
             */
            $media = Media::query()->forceCreate([
                'uuid' => (string) Str::uuid7(),
                'mediable_type' => PromotionRequest::class,
                'mediable_id' => $promotion->getKey(),
                'collection' => 'ad_creative',
                'disk' => 'public',
                'path' => 'ads/'.Str::lower(Str::random(10)).'.jpg',
                'original_filename' => 'banner.jpg',
                'mime_type' => 'image/jpeg',
                'extension' => 'jpg',
                'size_bytes' => 204_800,
                'width' => 1600,
                'height' => 400,
            ]);

            $promotion->forceFill(['image_media_id' => $media->getKey()])->save();
        });
    }

    /** Window entirely in the past — the "nobody reviewed it in time" case. */
    public function pastWindow(): static
    {
        return $this->state(fn () => [
            'requested_start' => now()->subWeeks(2)->toDateString(),
            'requested_end' => now()->subWeek()->toDateString(),
        ]);
    }

    public function forListing(Listing $listing): static
    {
        return $this->state(fn () => [
            'promotable_type' => Listing::class,
            'promotable_id' => $listing->getKey(),
            'user_id' => $listing->user_id,
        ]);
    }
}
