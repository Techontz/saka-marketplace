<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Booking\Enums\ServiceMode;
use App\Models\Listing;
use App\Models\SpecialistService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SpecialistService> */
class SpecialistServiceFactory extends Factory
{
    protected $model = SpecialistService::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'listing_id' => Listing::factory(),
            'name' => 'Initial consultation',
            'description' => 'A first meeting to understand your case.',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            // Minor units, matching listings. TZS has no subunit.
            'price_amount' => 50_000,
            'currency' => 'TZS',
            'mode' => ServiceMode::Both,
            'is_active' => true,
            'position' => 0,
        ];
    }

    public function minutes(int $duration, int $buffer = 0): static
    {
        return $this->state(fn () => [
            'duration_minutes' => $duration,
            'buffer_minutes' => $buffer,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function mode(ServiceMode $mode): static
    {
        return $this->state(fn () => ['mode' => $mode]);
    }
}
