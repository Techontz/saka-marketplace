<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Admin;

use App\Http\Resources\V1\MediaResource;
use App\Models\PromotionRequest;
use App\Services\Advertising\PromotionRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PromotionRequest
 *
 * A promotion request as the REVIEWER sees it.
 *
 * Everything the vendor's own view carries, plus who filed it and the two
 * pre-flight answers an operator needs before deciding:
 *
 *   `promoted.still_exists` — the listing may have been deleted since;
 *   `blockers`              — everything that would make approval fail.
 *
 * `blockers` is the important one. Without it an operator presses Approve, gets
 * a 422, and has to infer which of five conditions failed. The same checks run
 * again server-side at approval — this is a preview, never the control.
 */
class AdminPromotionRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $service = app(PromotionRequestService::class);
        $subject = $this->promotable;

        return [
            'uuid' => $this->uuid,

            'vendor' => $this->whenLoaded('vendor', fn (): ?array => $this->vendor === null ? null : [
                'uuid' => $this->vendor->uuid,
                'name' => $this->vendor->fullName(),
                'email' => $this->vendor->email,
            ]),

            'promoted' => [
                // The wire alias, never `App\Models\SellerProfile`.
                'type' => $service->aliasFor($this->promotable_type),
                'label' => $subject === null ? null : $service->resourceLabel($subject),
                'still_exists' => $subject !== null,
                'destination_url' => $subject === null ? null : $service->resourceUrl($subject),
            ],

            'placement' => $this->placement->value,
            'placement_label' => $this->placement->label(),

            'requested_start' => $this->requested_start->toDateString(),
            'requested_end' => $this->requested_end->toDateString(),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_reviewable' => $this->status->isReviewable(),

            'creative' => [
                'headline' => $this->headline,
                'body' => $this->body,
                'cta_label' => $this->cta_label,
                'image' => $this->whenLoaded('image', fn () => $this->image ? new MediaResource($this->image) : null),
                'mobile_image' => $this->whenLoaded('mobileImage', fn () => $this->mobileImage ? new MediaResource($this->mobileImage) : null),
            ],

            /*
             * Why approval would fail, if it would.
             *
             * An empty array means "this will go through". Computed from the
             * same facts the service checks, so the two cannot disagree about
             * whether a request is approvable — only about WHEN, since the
             * service re-reads them at the moment of the decision.
             */
            'blockers' => $this->blockers($subject !== null),

            'review' => [
                'reviewed_at' => $this->reviewed_at?->toIso8601String(),
                'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->fullName()),
                'rejection_reason' => $this->rejection_reason,
            ],

            'campaign' => $this->whenLoaded('campaign', fn (): ?array => $this->campaign === null ? null : [
                'uuid' => $this->campaign->uuid,
                'status' => $this->campaign->status->value,
                'status_label' => $this->campaign->status->label(),
                'is_serving' => $this->campaign->status->isServable(),
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** @return array<int, string> */
    private function blockers(bool $subjectExists): array
    {
        if (! $this->status->isReviewable()) {
            return [];
        }

        $problems = [];

        if (! $subjectExists) {
            $problems[] = 'The promoted item no longer exists.';
        }

        if (! $this->hasArtwork()) {
            $problems[] = 'No desktop artwork was uploaded.';
        }

        if ($this->windowHasClosed()) {
            $problems[] = 'The requested dates have already passed.';
        }

        if (! $this->placement->isVendorRequestable()) {
            $problems[] = 'That placement is no longer offered to vendors.';
        }

        return $problems;
    }
}
