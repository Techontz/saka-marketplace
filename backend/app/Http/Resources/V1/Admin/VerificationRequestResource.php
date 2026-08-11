<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Admin;

use App\Domain\Trust\Enums\VerificationStatus;
use App\Domain\Trust\IdentityNumber;
use App\Models\VerificationRequest;
use App\Services\Identity\IdentityVerificationProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VerificationRequest */
class VerificationRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type->value,
            'status' => $this->status->value,
            /*
             * FULL, and only here.
             *
             * A reviewer's job is to compare this number against the document
             * in the photograph, so masking it would make the queue unusable.
             * This resource is reachable only behind `verification.review`, is
             * never rendered on a public surface, and has no counterpart on the
             * marketplace API — the vendor's own view gets a masked value and
             * the public business payload carries no identity field at all.
             */
            'document_number' => $this->document_number,
            'document_number_masked' => IdentityNumber::mask($this->document_number),

            /*
             * What an automated check would have said.
             *
             * Always "unavailable" today, and rendered as such rather than as a
             * failure: NIDA publishes no integration, so every decision on this
             * screen is the reviewer's own. See ManualReviewProvider.
             */
            'automated_check' => app(IdentityVerificationProvider::class)->isAvailable()
                ? null
                : ['outcome' => 'unavailable', 'provider' => app(IdentityVerificationProvider::class)->name()],

            // Pending WITH a note is a request the reviewer has already asked
            // the vendor to correct — a different thing from one nobody has
            // opened, and the queue sorts and labels it differently.
            'needs_correction' => $this->status === VerificationStatus::Pending
                && filled($this->rejection_reason),
            // A SIGNED, short-lived URL — identity documents are on a private
            // disk and must never get a permanent public link.
            'document_url' => $this->whenLoaded(
                'document',
                fn () => $this->document?->temporaryUrl(10),
            ),
            'user' => $this->whenLoaded('user', fn () => [
                'uuid' => $this->user->uuid,
                'name' => $this->user->fullName(),
                'email' => $this->user->email,
                'phone_verified' => $this->user->phone_verified_at !== null,
            ]),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->fullName()),
            'reviewed_at' => $this->reviewed_at?->toAtomString(),
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at?->toAtomString(),
        ];
    }
}
