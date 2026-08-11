<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Engagement\Enums\ReviewStatus;
use App\Models\Listing;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Review> */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    public function definition(): array
    {
        /*
         * The three foreign keys are defaulted rather than left to the caller.
         *
         * Without them every test had to supply listing_id, seller_id AND
         * reviewer_id or get "Field 'reviewer_id' doesn't have a default
         * value" — a raw SQL error that says nothing about what a review is.
         * `seller_id` is derived from the listing so the two cannot disagree.
         */
        $listing = Listing::factory();

        return [
            'uuid' => (string) Str::uuid7(),
            'listing_id' => $listing,
            'seller_id' => fn (array $attributes) => Listing::find($attributes['listing_id'])?->user_id
                ?? User::factory()->seller(),
            'reviewer_id' => User::factory()->buyer(),
            'rating' => $this->faker->numberBetween(1, 5),
            'title' => $this->faker->sentence(4),
            'body' => $this->faker->paragraph(),
            'status' => ReviewStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => ReviewStatus::Approved]);
    }
}
