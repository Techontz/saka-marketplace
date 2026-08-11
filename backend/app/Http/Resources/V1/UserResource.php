<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 *
 * EXPLICIT field whitelist — never `$this->resource->toArray()`. A model
 * accessor added later must not silently start leaking into API responses.
 * `id` is never exposed; clients use `uuid`.
 */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'email' => $this->email,
            'phone' => $this->phone,
            'locale' => $this->locale,
            'status' => $this->status->value,

            'email_verified' => $this->email_verified_at !== null,
            'phone_verified' => $this->phone_verified_at !== null,

            // Surfaced so a client can render the "verify your phone to
            // publish" prompt without hard-coding the rule.
            'can_publish_listings' => $this->canPublishListings(),

            'avatar_url' => $this->whenLoaded('avatar', fn () => $this->avatar?->url('thumb')),

            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->all()),
            'permissions' => $this->when(
                $request->user()?->is($this->resource) ?? false,
                fn () => $this->permissionSlugs(),
            ),

            'seller_profile' => $this->whenLoaded('sellerProfile', fn () => [
                'slug' => $this->sellerProfile->slug,
                'display_name' => $this->sellerProfile->display_name,
                'is_verified' => $this->sellerProfile->is_verified,
                'rating_avg' => $this->sellerProfile->rating_avg,
                'rating_count' => $this->sellerProfile->rating_count,
            ]),

            'created_at' => $this->created_at?->toAtomString(),
        ];
    }
}
