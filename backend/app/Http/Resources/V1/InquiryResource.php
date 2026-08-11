<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Inquiry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Inquiry
 *
 * Only ever returned to the seller who received it, the sender, or staff — the
 * InquiryPolicy enforces that. IP and user-agent are captured for abuse
 * handling but never exposed.
 */
class InquiryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'message' => $this->message,
            'source' => $this->source->value,
            'status' => $this->status->value,
            'reply' => $this->reply_body !== null ? [
                'body' => $this->reply_body,
                'replied_at' => $this->replied_at?->toAtomString(),
            ] : null,
            'listing' => $this->whenLoaded('listing', fn () => $this->listing ? [
                'uuid' => $this->listing->uuid,
                'slug' => $this->listing->slug,
                'title' => $this->listing->title,
            ] : null),
            'read_at' => $this->read_at?->toAtomString(),
            'created_at' => $this->created_at?->toAtomString(),
        ];
    }
}
