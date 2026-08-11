<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 *
 * The ADMIN view of a user: operational fields a moderator needs, and nothing
 * more. No password hash, no tokens, no remember_token. Kept separate from the
 * public UserResource so widening one never silently widens the other.
 */
class AdminUserResource extends JsonResource
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
            'status' => $this->status->value,
            'locale' => $this->locale,
            'email_verified' => $this->email_verified_at !== null,
            'phone_verified' => $this->phone_verified_at !== null,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->all()),
            'listings_count' => $this->whenCounted('listings'),
            'seller_profile' => $this->whenLoaded('sellerProfile', fn () => $this->sellerProfile ? [
                'slug' => $this->sellerProfile->slug,
                'display_name' => $this->sellerProfile->display_name,
                'is_verified' => (bool) $this->sellerProfile->is_verified,
                'verification_level' => $this->sellerProfile->verification_level->value,
                'rating_avg' => $this->sellerProfile->rating_avg,
            ] : null),
            'last_login_at' => $this->last_login_at?->toAtomString(),
            'created_at' => $this->created_at?->toAtomString(),
        ];
    }
}
