<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Advertising\Enums\AdCampaignStatus;
use App\Domain\Advertising\Enums\AdPlacement;
use App\Models\AdCampaign;
use App\Models\Advertiser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AdCampaign> */
class AdCampaignFactory extends Factory
{
    protected $model = AdCampaign::class;

    /**
     * Defaults to a LIVE campaign — active, open window, no cap.
     *
     * Chosen deliberately: almost every test is about what a visitor sees, and
     * making the servable case the default keeps those tests about the rule
     * under test rather than about six lines of setup. The states below take it
     * out of eligibility one reason at a time.
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'advertiser_id' => Advertiser::factory(),
            'name' => $this->faker->words(3, true),
            'placement' => AdPlacement::ListingsInline,
            'status' => AdCampaignStatus::Active,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'priority' => 0,
        ];
    }

    public function placement(AdPlacement $placement): static
    {
        return $this->state(fn () => ['placement' => $placement]);
    }

    public function status(AdCampaignStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function priority(int $priority): static
    {
        return $this->state(fn () => ['priority' => $priority]);
    }

    /** Window entirely in the past. Status stays Active so the test proves the
     *  DATES exclude it, not the status column. */
    public function expiredWindow(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subMonth(),
        ]);
    }

    /** Window entirely in the future, for the same reason. */
    public function futureWindow(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    public function capped(int $cap, int $delivered): static
    {
        return $this->state(fn () => [
            'impression_cap' => $cap,
            'impressions_count' => $delivered,
        ]);
    }
}
