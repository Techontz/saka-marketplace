<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Admin;

use App\Models\Advertiser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Advertiser
 *
 * Admin-only. The billing contact on this record has never been exposed on the
 * public surface and must not start being — `AdCreativeResource` sends the
 * advertiser's NAME and nothing else.
 */
class AdminAdvertiserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'is_active' => $this->is_active,

            'contact' => [
                'name' => $this->contact_name,
                'email' => $this->contact_email,
                'phone' => $this->contact_phone,
            ],

            'notes' => $this->notes,

            // Present when this advertiser is also a vendor on the platform, so
            // an operator can jump straight to the storefront.
            'vendor' => $this->whenLoaded('sellerProfile', fn (): ?array => $this->sellerProfile === null ? null : [
                'slug' => $this->sellerProfile->slug,
                'display_name' => $this->sellerProfile->display_name,
            ]),

            'campaigns_count' => $this->whenCounted('campaigns'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
