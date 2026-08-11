<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\PublicPlace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PublicPlace
 *
 * @property-read float|string|null $distance_km Selected by a radius search only.
 */
class PublicPlaceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->whenLoaded('image', fn () => $this->image?->url('card')),
            'category' => $this->whenLoaded('category', fn () => [
                'slug' => $this->category->slug,
                'name' => $this->category->name,
                'icon' => $this->category->icon,
            ]),
            'location' => [
                'region' => $this->whenLoaded('region', fn () => $this->region?->name),
                'district' => $this->whenLoaded('district', fn () => $this->district?->name),
                'address_line' => $this->address_line,
                'distance_km' => $this->when(
                    isset($this->distance_km),
                    fn () => round((float) $this->distance_km, 2),
                ),
                'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
                'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            ],
            'phone' => $this->phone,
            'website' => $this->website,
            'opening_hours' => $this->opening_hours,
        ];
    }
}
