<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\PromotionRequest;
use App\Services\Advertising\PromotionRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PromotionRequest
 *
 * One promotion request, for the vendor who filed it and for the administrator
 * reviewing it.
 *
 * `promotable_type` is published as an ALIAS — "listing", "business" — never as
 * `App\Models\SellerProfile`. There is no morph map in this application, so the
 * column holds a fully-qualified class name; that is fine for storage and
 * unacceptable on the wire, where it would leak the namespace layout and bake
 * an internal refactor into a public contract.
 *
 * There is no `delivery` block. A request is never served — the CAMPAIGN it
 * mints is, and that is the only thing the beacons write to. Copying figures
 * onto the request would mean two numbers that disagree the moment the
 * administrator edits the campaign.
 */
class PromotionRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $service = app(PromotionRequestService::class);
        $subject = $this->promotable;

        return [
            'uuid' => $this->uuid,

            'promoted' => [
                'type' => $service->aliasFor($this->promotable_type),
                /*
                 * Null when the promoted thing has been deleted since the
                 * request was filed. A real state — an administrator has to be
                 * able to see it in the queue and reject accordingly, and the
                 * vendor's history must not 500 because a listing is gone.
                 */
                'label' => $subject === null ? null : $service->resourceLabel($subject),
                'still_exists' => $subject !== null,
            ],

            'placement' => $this->placement->value,
            'placement_label' => $this->placement->label(),

            'requested_start' => $this->requested_start->toDateString(),
            'requested_end' => $this->requested_end->toDateString(),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_cancellable' => $this->status->isCancellable(),

            'creative' => [
                'headline' => $this->headline,
                'body' => $this->body,
                'cta_label' => $this->cta_label,
                /*
                 * Derived server-side and shown read-only, so the vendor can
                 * see where their promotion will send people. It is NOT an
                 * input: a vendor-supplied destination on a paid placement
                 * inside a trusted marketplace is a phishing vector no scheme
                 * check can close.
                 */
                'destination_url' => $subject === null ? null : $service->resourceUrl($subject),
                'image' => $this->whenLoaded('image', fn () => $this->image ? new MediaResource($this->image) : null),
                'mobile_image' => $this->whenLoaded('mobileImage', fn () => $this->mobileImage ? new MediaResource($this->mobileImage) : null),
            ],

            'review' => [
                'reviewed_at' => $this->reviewed_at?->toIso8601String(),
                // The vendor is shown WHY, which is the difference between a
                // resubmission that fixes the problem and one that repeats it.
                'rejection_reason' => $this->rejection_reason,
            ],

            /*
             * The campaign minted on approval, when there is one.
             *
             * `is_serving` is the honest answer to "is my promotion live?" —
             * approval creates a DRAFT campaign, and an administrator activates
             * it separately. Calling an approved request "Active" before that
             * happens would be the fake state this whole phase avoids.
             */
            'campaign' => $this->whenLoaded('campaign', fn (): ?array => $this->campaign === null ? null : [
                'uuid' => $this->campaign->uuid,
                'status' => $this->campaign->status->value,
                'status_label' => $this->campaign->status->label(),
                'is_serving' => $this->campaign->status->isServable(),
                'impressions' => (int) $this->campaign->impressions_count,
                'clicks' => (int) $this->campaign->clicks_count,
                'ctr' => $this->campaign->clickThroughRate(),
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
