<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Category */
class CategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'icon' => $this->icon,
            'description' => $this->description,
            'depth' => $this->depth,
            'is_leaf' => $this->is_leaf,
            'listing_count' => $this->listing_count,
            'image_url' => $this->whenLoaded('image', fn () => $this->image?->url('card')),
            'children' => self::collection($this->whenLoaded('children')),
            'attributes' => AttributeResource::collection($this->whenLoaded('attributes')),
        ];
    }
}
