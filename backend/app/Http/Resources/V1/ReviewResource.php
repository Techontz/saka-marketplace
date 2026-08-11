<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Review */
class ReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'rating' => (int) $this->rating,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status->value,
            'helpful_count' => (int) $this->helpful_count,
            'reply' => $this->reply_body !== null ? [
                'body' => $this->reply_body,
                'replied_at' => $this->replied_at?->toAtomString(),
            ] : null,
            'reviewer' => $this->whenLoaded('reviewer', fn () => [
                'uuid' => $this->reviewer->uuid,
                'name' => $this->reviewer->first_name,
            ]),
            'listing' => $this->whenLoaded('listing', fn () => $this->listing ? [
                'slug' => $this->listing->slug,
                'title' => $this->listing->title,
            ] : null),
            'created_at' => $this->created_at?->toAtomString(),
        ];
    }
}
