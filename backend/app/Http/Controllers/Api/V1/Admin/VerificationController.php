<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Trust\Enums\VerificationLevel;
use App\Domain\Trust\Enums\VerificationStatus;
use App\Domain\Trust\Enums\VerificationType;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Admin\VerificationRequestResource;
use App\Models\SellerProfile;
use App\Models\VerificationRequest;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Seller verification review — the queue behind the VERIFIED badge.
 *
 * Approving raises the seller's verification LEVEL rather than setting a
 * boolean, so a business-verified seller is distinguishable from an
 * ID-verified one and the level can only ever move upward.
 */
class VerificationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeReview($request);

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', VerificationStatus::values())],
            'type' => ['nullable', 'string', 'in:'.implode(',', VerificationType::values())],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $requests = VerificationRequest::query()
            ->with(['user:id,uuid,first_name,last_name,email,phone_verified_at', 'document', 'reviewer:id,first_name,last_name'])
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($validated['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            // Oldest first: a review queue is a FIFO, not a stack.
            ->oldest('created_at')
            ->paginate($validated['per_page'] ?? 25)
            ->withQueryString();

        return VerificationRequestResource::collection($requests);
    }

    public function approve(Request $request, VerificationRequest $verification): JsonResponse
    {
        $this->authorizeReview($request);
        $this->assertPending($verification);

        DB::transaction(function () use ($verification, $request): void {
            $verification->forceFill([
                'status' => VerificationStatus::Approved,
                'reviewed_by' => $request->user()->getKey(),
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ])->save();

            $this->raiseSellerLevel($verification);
        });

        $this->audit->record('verification.approved', $request->user(), $verification, [], [
            'type' => $verification->type->value,
        ]);

        return response()->json([
            'data' => new VerificationRequestResource(
                $verification->fresh()->load(['user', 'reviewer']),
            ),
        ]);
    }

    public function reject(Request $request, VerificationRequest $verification): JsonResponse
    {
        $this->authorizeReview($request);
        $this->assertPending($verification);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $verification->forceFill([
            'status' => VerificationStatus::Rejected,
            'reviewed_by' => $request->user()->getKey(),
            'reviewed_at' => now(),
            'rejection_reason' => $validated['reason'],
        ])->save();

        $this->audit->record('verification.rejected', $request->user(), $verification, [], [
            'reason' => $validated['reason'],
        ]);

        return response()->json([
            'data' => new VerificationRequestResource($verification->fresh()->load(['user', 'reviewer'])),
        ]);
    }

    /**
     * Ask the seller for more information without deciding either way.
     *
     * The queue previously had two exits: approved or rejected. Most real
     * submissions need neither — the ID photo is cut off, the business name
     * does not match. Rejecting those is wrong (the seller has done nothing
     * disqualifying) and approving them is worse, so reviewers were forced to
     * choose between two bad options or leave the request sitting.
     *
     * This keeps the request PENDING — it stays in the queue and can still be
     * approved or rejected later — while recording what was asked for and
     * notifying the seller.
     */
    public function requestInformation(Request $request, VerificationRequest $verification): JsonResponse
    {
        $this->authorizeReview($request);
        $this->assertPending($verification);

        $validated = $request->validate([
            'message' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $verification->forceFill([
            // Deliberately NOT a status change. `rejection_reason` doubles as
            // "the last thing a reviewer said", which is what the seller needs
            // to see, and the request stays actionable.
            'rejection_reason' => $validated['message'],
            'reviewed_by' => $request->user()->getKey(),
            // reviewed_at stays null: this is not a decision, and setting it
            // would make the request look closed in every "awaiting review"
            // query.
        ])->save();

        $this->audit->record(
            'verification.info_requested',
            $request->user(),
            $verification,
            [],
            ['message' => $validated['message']],
        );

        return response()->json([
            'data' => new VerificationRequestResource($verification->fresh()->load(['user', 'reviewer'])),
        ]);
    }

    /**
     * Maps an approved document type onto the seller's level, and never
     * downgrades: an ID-verified seller who later submits an address proof
     * keeps the higher standing.
     */
    private function raiseSellerLevel(VerificationRequest $verification): void
    {
        $profile = SellerProfile::where('user_id', $verification->user_id)->first();

        if ($profile === null) {
            return;
        }

        $target = match ($verification->type) {
            VerificationType::Business => VerificationLevel::Business,
            VerificationType::NationalId => VerificationLevel::Id,
            default => VerificationLevel::Phone,
        };

        if ($profile->verification_level->satisfies($target)) {
            return;
        }

        $profile->forceFill([
            'verification_level' => $target,
            'is_verified' => true,
            'verified_at' => $profile->verified_at ?? now(),
        ])->save();
    }

    private function assertPending(VerificationRequest $verification): void
    {
        if ($verification->status !== VerificationStatus::Pending) {
            throw ApiException::make(
                ErrorCode::Conflict,
                'This verification request has already been reviewed.',
                ['status' => $verification->status->value],
            );
        }
    }

    private function authorizeReview(Request $request): void
    {
        if (! $request->user()?->hasPermission(Permission::VerificationReview)) {
            throw ApiException::forbidden();
        }
    }
}
