<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Listing\Enums\ListingCondition;
use App\Domain\Listing\Enums\ListingPurpose;
use App\Domain\Listing\Enums\ListingStatus;
use App\Domain\Listing\Enums\PriceUnit;
use App\Models\Category;
use App\Models\District;
use App\Models\Listing;
use App\Models\Region;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Listing> */
class ListingFactory extends Factory
{
    protected $model = Listing::class;

    public function definition(): array
    {
        $title = $this->faker->sentence(6);

        return [
            'uuid' => (string) Str::uuid7(),
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'user_id' => User::factory(),
            'category_id' => fn () => Category::query()->leaves()->inRandomOrder()->value('id'),
            'title' => $title,
            'description' => $this->faker->paragraph(),
            'purpose' => ListingPurpose::Sale,
            'price' => $this->faker->numberBetween(100_000, 500_000_000),
            'currency' => 'TZS',
            'price_unit' => PriceUnit::Total,
            'condition' => ListingCondition::Used,
            'region_id' => fn () => Region::query()->value('id'),
            'district_id' => fn () => District::query()->value('id'),
            'status' => ListingStatus::Draft,
            'latitude' => -6.7924,
            'longitude' => 39.2083,
        ];
    }

    /** Published and publicly visible. */
    public function published(): static
    {
        return $this->state(fn () => [
            'status' => ListingStatus::Published,
            'published_at' => now()->subDay(),
            'expires_at' => now()->addDays(60),
        ]);
    }

    public function status(ListingStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function inCategory(string $slug): static
    {
        return $this->state(fn () => [
            'category_id' => Category::query()->where('slug', $slug)->value('id'),
        ]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->getKey()]);
    }

    public function at(float $lat, float $lng): static
    {
        return $this->state(fn () => ['latitude' => $lat, 'longitude' => $lng]);
    }

    public function priced(int $amount): static
    {
        return $this->state(fn () => ['price' => $amount]);
    }

    public function featured(): static
    {
        return $this->state(fn () => ['is_featured' => true]);
    }

    public function verified(): static
    {
        return $this->state(fn () => ['is_verified' => true]);
    }
}
