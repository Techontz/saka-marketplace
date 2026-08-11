<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Region / District / Ward share one shape.
 *
 * The wrapped model is deliberately not pinned to one class — these are the
 * fields every location model is required to expose.
 *
 * @property-read string $slug
 * @property-read string $name
 * @property-read float|string|null $latitude
 * @property-read float|string|null $longitude
 * @property-read int|null $listing_count
 */
class LocationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return array_filter([
            'slug' => $this->slug,
            'name' => $this->name,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'listing_count' => $this->listing_count ?? null,
            'districts' => LocationResource::collection($this->whenLoaded('districts')),
            'wards' => LocationResource::collection($this->whenLoaded('wards')),
        ], static fn ($v) => $v !== null);
    }
}
