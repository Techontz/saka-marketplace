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
 * The vendor's OWN view of their business profile.
 *
 * Distinct from SellerProfileResource, which is the public one. This carries
 * everything the owner may edit — registration numbers, private contact
 * details, the map pin — and is only ever returned to the profile's owner. Two
 * resources rather than one with conditionals, so widening the vendor's view
 * can never accidentally widen what a buyer sees.
 */
class VendorProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'display_name' => $this->display_name,
            'business_name' => $this->business_name,
            'business_type' => $this->business_type?->value,
            'bio' => $this->bio,
            'business_reg_no' => $this->business_reg_no,
            'tin' => $this->tin,

            'location' => [
                'country_code' => $this->country_code,
                // Ids and slugs both: the id is what the column holds, the slug
                // is what every location endpoint speaks, and the owner's own
                // editor needs to match one against the other.
                'region_id' => $this->region_id,
                'region_slug' => $this->whenLoaded('region', fn () => $this->region?->slug),
                'region' => $this->whenLoaded('region', fn () => $this->region?->name),
                'district_id' => $this->district_id,
                'district_slug' => $this->whenLoaded('district', fn () => $this->district?->slug),
                'district' => $this->whenLoaded('district', fn () => $this->district?->name),
                'ward_id' => $this->ward_id,
                'ward_slug' => $this->whenLoaded('ward', fn () => $this->ward?->slug),
                'ward' => $this->whenLoaded('ward', fn () => $this->ward?->name),
                'street' => $this->street,
                'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
                'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            ],

            'contact' => [
                'public_email' => $this->public_email,
                'public_phone' => $this->public_phone,
                'whatsapp' => $this->whatsapp,
                'website' => $this->website,
            ],

            /*
             * Sized variants, not originals.
             *
             * The portal renders the logo at 64px and the cover as a banner, so
             * sending the vendor their own 4MB upload back was pure waste on
             * every dashboard load. `url()` falls through to the original when
             * the variant is absent, which is the state for the few seconds
             * between upload and the resize job running — exactly when the
             * vendor is looking at this screen.
             */
            'branding' => [
                'logo_url' => $this->whenLoaded('logo', fn () => $this->logo?->url('thumb')),
                'cover_url' => $this->whenLoaded('cover', fn () => $this->cover?->url('detail')),
            ],

            // Null means "never configured"; an empty array for a day means
            // "closed that day". The UI shows those differently.
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
                'rating_avg' => $this->rating_avg !== null ? (float) $this->rating_avg : null,
                'rating_count' => (int) $this->rating_count,
                'response_rate_pct' => $this->response_rate_pct,
            ],

            'onboarding_completed_at' => $this->onboarding_completed_at?->toAtomString(),
            'created_at' => $this->created_at?->toAtomString(),
        ];
    }
}
