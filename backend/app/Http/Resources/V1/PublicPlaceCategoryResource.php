<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\PublicPlaceCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PublicPlaceCategory */
class PublicPlaceCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'icon' => $this->icon,
            'place_count' => (int) $this->place_count,
            'image_url' => $this->whenLoaded('image', fn () => $this->image?->url('card')),
            'places' => PublicPlaceResource::collection($this->whenLoaded('places')),
        ];
    }
}
