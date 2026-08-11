<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Domain\Identity\Enums\SocialNetwork;
use App\Models\SellerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SellerProfile
 *
 * @property-read float|string|null $distance_km Selected by a radius search only.
 *
 * A business as the PUBLIC sees it.
 *
 * The third and last view of a seller profile, and the boundaries are the whole
 * point of having three:
 *
 *   - VendorProfileResource — the owner's editor. Registration number, TIN,
 *     onboarding state.
 *   - SellerProfileResource — the seller's own dashboard summary.
 *   - this one — everything a buyer may see, and nothing else.
 *
 * `business_reg_no` and `tin` are absent by construction rather than by a
 * conditional, so widening the owner's view can never widen this one.
 */
class BusinessResource extends JsonResource
{
    /** Set on the detail endpoint; the card shape stays light. */
    private bool $detailed = false;

    public function detailed(): self
    {
        $this->detailed = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = [
            'slug' => $this->slug,
            'display_name' => $this->display_name,
            'business_type' => $this->business_type?->value,
            'business_type_label' => $this->business_type?->label(),
            'logo_url' => $this->whenLoaded('logo', fn () => $this->logo?->url('card')),

            'location' => [
                'region' => $this->whenLoaded('region', fn () => $this->region?->name),
                'region_slug' => $this->whenLoaded('region', fn () => $this->region?->slug),
                'district' => $this->whenLoaded('district', fn () => $this->district?->name),
                'district_slug' => $this->whenLoaded('district', fn () => $this->district?->slug),
                'ward' => $this->whenLoaded('ward', fn () => $this->ward?->name),
                'street' => $this->street,
                'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
                'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            ],

            'rating' => [
                'average' => $this->rating_avg !== null ? (float) $this->rating_avg : null,
                'count' => (int) $this->rating_count,
            ],

            'listing_count' => (int) $this->active_listings,
            'is_verified' => (bool) $this->is_verified,

            // Only present when a geo search computed it.
            'distance_km' => $this->when(
                isset($this->distance_km),
                fn () => round((float) $this->distance_km, 2),
            ),
        ];

        if (! $this->detailed) {
            return $data;
        }

        return array_merge($data, [
            'bio' => $this->bio,
            /*
             * `full` (1600px), not the original.
             *
             * This is a full-bleed hero, so it is the one image on the page
             * that genuinely wants width — but the original is whatever the
             * vendor uploaded, routinely a 4MB straight-off-a-phone JPEG.
             * `url()` falls back to the original when the variant is missing,
             * so a cover whose resize job has not run yet still renders.
             */
            'cover_url' => $this->whenLoaded('cover', fn () => $this->cover?->url('full')),

            'contact' => [
                'phone' => $this->public_phone,
                'email' => $this->public_email,
                'whatsapp' => $this->whatsapp,
                'website' => $this->website,
            ],

            // Null means "never told us", an empty day means "closed that day".
            // The frontend shows those differently, so the distinction survives.
            'opening_hours' => $this->opening_hours,
            /*
             * Normalised on READ as well as on write.
             *
             * Rows predating SocialNetwork validation hold whatever was typed —
             * bare handles, scheme-less hosts, blanks, and links on hosts that
             * do not belong to the network they are filed under. Cleaning here
             * means the public profile never renders an icon over a destination
             * that icon does not describe, without needing a backfill migration
             * to be run first.
             */
            'social_links' => SocialNetwork::normaliseAll((array) $this->social_links),

            'verification' => [
                'is_verified' => (bool) $this->is_verified,
                'level' => $this->verification_level->value,
                'verified_at' => $this->verified_at?->toAtomString(),
            ],

            'stats' => [
                'active_listings' => (int) $this->active_listings,
                'total_listings' => (int) $this->total_listings,
                'response_rate_pct' => $this->response_rate_pct,
                'response_time_minutes' => $this->response_time_minutes,
            ],

            'member_since' => $this->created_at?->toAtomString(),
        ]);
    }
}
