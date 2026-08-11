<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Advertiser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Advertiser> */
class AdvertiserFactory extends Factory
{
    protected $model = Advertiser::class;

    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'uuid' => (string) Str::uuid7(),
            'name' => $name,
            // Suffixed rather than relying on the faker name being unique:
            // `slug` is a unique column and company names collide often enough
            // to make a large seed run fail intermittently.
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'contact_name' => $this->faker->name(),
            'contact_email' => $this->faker->safeEmail(),
            'contact_phone' => '+2557'.$this->faker->numerify('########'),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
