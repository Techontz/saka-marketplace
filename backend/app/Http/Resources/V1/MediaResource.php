<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Media
 *
 * `url` is always the original, so the client has something to render even
 * while variants are still being generated. `variants` fills in once the queued
 * job completes; `processing_status` tells the client which state it is in.
 */
class MediaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'url' => $this->url(),
            'variants' => collect((array) $this->variants)
                ->map(fn (array $variant, string $name): array => [
                    'url' => $this->url($name),
                    'width' => $variant['width'] ?? null,
                    'height' => $variant['height'] ?? null,
                ])
                ->all(),
            'alt_text' => $this->alt_text,
            'width' => $this->width,
            'height' => $this->height,
            'position' => $this->position,
            'is_primary' => (bool) $this->is_primary,
            'processing_status' => $this->processing_status,
        ];
    }
}
