<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\AdCreative;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AdCreative
 *
 * What a VISITOR is allowed to know about an advertisement.
 *
 * The campaign is not here, and neither is the advertiser's billing contact,
 * their targeting, their priority, their impression cap or their delivery
 * numbers. Only `advertiser.name` crosses over, because "Sponsored — Toyota
 * Tanzania" is the disclosure that makes the unit honest; everything else on
 * that record is commercial information about what SAKA sold and for how much.
 *
 * Counters are excluded on purpose. A competitor could otherwise poll this
 * endpoint and read a rival's delivery volume straight off the marketplace.
 */
class AdCreativeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'headline' => $this->headline,
            'body' => $this->body,
            'cta_label' => $this->cta_label,
            'click_url' => $this->click_url,

            // Never empty — falls back to the headline, because this is a link
            // and `alt=""` would leave it announced as an unlabelled control.
            'alt_text' => $this->accessibleAltText(),

            /*
             * Full MediaResource, not a bare URL, so the banner can build a
             * srcset from the same renditions everything else uses. A hero
             * creative is one of the largest images on the homepage; serving
             * the original there would undo the variant work wholesale.
             */
            'image' => $this->whenLoaded('image', fn () => $this->image ? new MediaResource($this->image) : null),
            'mobile_image' => $this->whenLoaded('mobileImage', fn () => $this->mobileImage ? new MediaResource($this->mobileImage) : null),

            'advertiser' => $this->whenLoaded('campaign', function (): ?array {
                $advertiser = $this->campaign?->advertiser;

                return $advertiser === null ? null : ['name' => $advertiser->name];
            }),
        ];
    }
}
