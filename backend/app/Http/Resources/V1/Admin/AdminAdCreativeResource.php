<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Admin;

use App\Http\Resources\V1\MediaResource;
use App\Models\AdCreative;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AdCreative
 *
 * A creative with its delivery numbers attached — which the public resource
 * omits, and which is the whole reason an operator opens this screen.
 */
class AdminAdCreativeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'headline' => $this->headline,
            'body' => $this->body,
            'cta_label' => $this->cta_label,
            'click_url' => $this->click_url,
            'alt_text' => $this->alt_text,
            'is_active' => $this->is_active,
            'position' => $this->position,

            'image' => $this->whenLoaded('image', fn () => $this->image ? new MediaResource($this->image) : null),
            'mobile_image' => $this->whenLoaded('mobileImage', fn () => $this->mobileImage ? new MediaResource($this->mobileImage) : null),

            /*
             * Cast, not passed through.
             *
             * These are database defaults: on the model returned straight from
             * an insert they are null, and a UI reading `performance.impressions`
             * would render an em-dash for a brand-new creative that has, quite
             * correctly, been shown zero times.
             */
            'performance' => [
                'impressions' => (int) $this->impressions_count,
                'clicks' => (int) $this->clicks_count,
                'ctr' => $this->clickThroughRate(),
            ],

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
