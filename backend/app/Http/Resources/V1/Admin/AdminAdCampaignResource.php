<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Admin;

use App\Models\AdCampaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AdCampaign
 *
 * A campaign as an ADMINISTRATOR sees it — the commercial view the public
 * `AdCreativeResource` deliberately withholds: priority, cap, delivery, and who
 * is being billed.
 *
 * `effective_status` is the interesting field. `status` is a cache the
 * scheduler maintains and can be up to five minutes stale, so a campaign booked
 * to start in two minutes would read "Scheduled" in the list while already
 * serving. Sending both means the admin can show what the campaign IS doing
 * without either re-deriving the date rule in TypeScript or waiting for cron.
 */
class AdminAdCampaignResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,

            'advertiser' => [
                'uuid' => $this->whenLoaded('advertiser', fn () => $this->advertiser?->uuid),
                'name' => $this->whenLoaded('advertiser', fn () => $this->advertiser?->name),
            ],

            'placement' => $this->placement->value,
            'placement_label' => $this->placement->label(),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'effective_status' => $this->scheduledStatus()->value,

            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),

            'priority' => $this->priority,
            'impression_cap' => $this->impression_cap,

            'targeting' => [
                // Slugs, never ids: the admin's category picker speaks slugs
                // and an auto-increment id in a payload is an invitation to
                // enumerate the catalogue.
                'categories' => $this->whenLoaded(
                    'categories',
                    fn () => $this->categories->map(fn ($category): array => [
                        'slug' => $category->slug,
                        'name' => $category->name,
                    ])->all(),
                ),
                'regions' => $this->whenLoaded(
                    'regions',
                    fn () => $this->regions->map(fn ($region): array => [
                        'slug' => $region->slug,
                        'name' => $region->name,
                    ])->all(),
                ),
            ],

            'performance' => [
                // Cast for the same reason as the creative resource: these are
                // database defaults and read as null on a fresh insert.
                'impressions' => (int) $this->impressions_count,
                'clicks' => (int) $this->clicks_count,
                // Null when nothing has been shown. "No data" and "shown and
                // never clicked" are different facts; the UI renders a dash for
                // the first and 0.00% for the second.
                'ctr' => $this->clickThroughRate(),
            ],

            'creatives_count' => $this->whenCounted('creatives'),
            'creatives' => AdminAdCreativeResource::collection($this->whenLoaded('creatives')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
