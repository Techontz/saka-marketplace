<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\SellerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SellerProfile */
class SellerProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isOwner = $request->user()?->getKey() === $this->user_id;

        return array_filter([
            'slug' => $this->slug,
            'display_name' => $this->display_name,
            'bio' => $this->bio,
            'business_name' => $this->business_name,
            'logo_url' => $this->whenLoaded('logo', fn () => $this->logo?->url('thumb')),
            'whatsapp' => $this->whatsapp,
            'website' => $this->website,
            'is_verified' => (bool) $this->is_verified,
            'verification_level' => $this->verification_level->value,
            'rating_avg' => $this->rating_avg !== null ? (float) $this->rating_avg : null,
            'rating_count' => (int) $this->rating_count,
            'active_listings' => (int) $this->active_listings,
            // Tax and registration identifiers are the owner's business only.
            'business_reg_no' => $isOwner ? $this->business_reg_no : null,
            'tin' => $isOwner ? $this->tin : null,
            'created_at' => $this->created_at?->toAtomString(),
        ], static fn ($v) => $v !== null);
    }
}
