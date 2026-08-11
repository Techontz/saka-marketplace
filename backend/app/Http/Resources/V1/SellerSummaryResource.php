<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 *
 * The PUBLIC view of a seller. Deliberately narrow: no email, no status, no
 * roles. The phone is exposed only for a published listing's own detail page,
 * which is the contact channel the marketplace exists to provide.
 */
class SellerSummaryResource extends JsonResource
{
    public function __construct($resource, private readonly bool $includeContact = false)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return array_filter([
            'uuid' => $this->uuid,
            'display_name' => $this->sellerProfile?->display_name ?? $this->fullName(),
            'slug' => $this->sellerProfile?->slug,
            'is_verified' => (bool) ($this->sellerProfile?->is_verified ?? false),
            'verification_level' => $this->sellerProfile?->verification_level?->value,
            'rating_avg' => $this->sellerProfile?->rating_avg !== null
                ? (float) $this->sellerProfile->rating_avg : null,
            'rating_count' => (int) ($this->sellerProfile?->rating_count ?? 0),
            'member_since' => $this->created_at?->toAtomString(),
            'phone' => $this->includeContact ? $this->phone : null,
        ], static fn ($v) => $v !== null);
    }
}
