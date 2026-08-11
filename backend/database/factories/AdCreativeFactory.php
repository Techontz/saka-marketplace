<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AdCampaign;
use App\Models\AdCreative;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AdCreative> */
class AdCreativeFactory extends Factory
{
    protected $model = AdCreative::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'ad_campaign_id' => AdCampaign::factory(),
            'headline' => $this->faker->sentence(4),
            'body' => $this->faker->sentence(8),
            'cta_label' => 'Explore',
            'click_url' => 'https://'.$this->faker->domainName().'/offer',
            'is_active' => true,
            'position' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /** Pre-loaded delivery, for testing the least-shown-first rotation. */
    public function withImpressions(int $count): static
    {
        return $this->state(fn () => ['impressions_count' => $count]);
    }
}
