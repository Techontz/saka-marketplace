<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Seller;

use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Trust\Enums\VerificationStatus;
use App\Domain\Trust\Enums\VerificationType;
use App\Domain\Trust\IdentityNumber;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Http\Controllers\Controller;
use App\Models\VerificationRequest;
use App\Services\Identity\IdentityVerificationProvider;
use App\Services\Media\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The vendor's side of verification.
 *
 * Milestone 9 built the moderator's review queue but nothing that could put a
 * request INTO it — verification requests could only be created directly in the
 * database. This is the missing half.
 *
 * Identity documents go to a PRIVATE disk and are only ever served through
 * short-lived signed URLs. That is the whole reason this cannot reuse the
 * listing media endpoints, which upload to a public bucket.
 */
class VendorVerificationController extends Controller
{
    public function __construct(
        private readonly MediaUploadService $media,
        private readonly IdentityVerificationProvider $identity,
    ) {}

    /** The vendor's own verification history. */
    public function index(Request $request): JsonResponse
    {
        $requests = VerificationRequest::query()
            ->where('user_id', $request->user()->getKey())
            ->latest('created_at')
            ->get();

        return response()->json([
            'data' => $requests->map(fn (VerificationRequest $entry): array => [
                'uuid' => $entry->uuid,
                'type' => $entry->type->value,
                'status' => $entry->status->value,
                /*
                 * MASKED, even for the person it belongs to.
                 *
                 * The vendor already knows their own NIDA; redisplaying it in
                 * full puts twenty identifying digits into the page source, the
                 * browser cache and any screenshot of the dashboard, to tell
                 * them something they typed themselves. The last four are
                 * enough to confirm which document is on file.
                 */
                'document_number_masked' => IdentityNumber::mask($entry->document_number),
                'reviewed_at' => $entry->reviewed_at?->toAtomString(),
                // Doubles as "what the reviewer asked for" when they requested
                // more information rather than rejecting outright.
                'reviewer_note' => $entry->rejection_reason,
                /*
                 * A DERIVED state, not a new column.
                 *
                 * `requestInformation` deliberately leaves the request pending
                 * and actionable (see the note there); pending WITH a reviewer
                 * note is what "needs correction" actually is. Deriving it here
                 * keeps one source of truth and lets the UI say so plainly.
                 */
                'needs_correction' => $entry->status === VerificationStatus::Pending
                    && filled($entry->rejection_reason),
                'created_at' => $entry->created_at?->toAtomString(),
            ])->all(),
            'meta' => [
                'types' => array_map(
                    fn (VerificationType $type): array => [
                        'value' => $type->value,
                        'label' => Str::headline($type->value),
                    ],
                    VerificationType::cases(),
                ),
                /*
                 * What kind of verification is actually in force.
                 *
                 * Published so the portal can say "reviewed by our team"
                 * instead of leaving a human queue looking like a robot that
                 * has not answered. See ManualReviewProvider.
                 */
                'automated_verification' => [
                    'available' => $this->identity->isAvailable(),
                    'provider' => $this->identity->name(),
                ],
                'nida_digits' => IdentityNumber::NIDA_LENGTH,
            ],
        ]);
    }

    /**
     * Submit a document for review.
     *
     * One pending request per TYPE. Without that a vendor can queue twenty
     * copies of the same ID while waiting, and the moderation queue — which is
     * ordered oldest-first — fills with duplicates of one person.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'type' => ['required', Rule::in(array_map(
                fn (VerificationType $type): string => $type->value,
                VerificationType::cases(),
            ))],
            /*
             * Loose here, checked properly below.
             *
             * `max:60` accommodates the dashes and spaces people quote a NIDA
             * with; the digit-count rule is applied after normalisation, so
             * "19900101-12345-00001-23" and its bare-digit twin are judged the
             * same way rather than one passing and one failing.
             */
            'document_number' => ['nullable', 'string', 'max:60'],
            'document' => ['required', 'file', 'max:'.(config('saka.media.max_image_mb', 5) * 1024)],
        ]);

        $type = VerificationType::from($validated['type']);

        $pending = VerificationRequest::query()
            ->where('user_id', $user->getKey())
            ->where('type', $type)
            ->where('status', VerificationStatus::Pending)
            ->exists();

        if ($pending) {
            throw ApiException::make(
                ErrorCode::Conflict,
                'You already have a '.Str::headline($type->value).' document awaiting review.',
            );
        }

        /*
         * NIDA numbers are twenty digits, and the number is REQUIRED for a
         * national-ID submission.
         *
         * Checked AFTER the duplicate guard above, deliberately. A vendor who
         * already has a document awaiting review needs to be told that — not
         * sent to fix a number on a submission that was never going to be
         * accepted anyway.
         *
         * Without it a reviewer has only a photograph to work from and nothing
         * to type into a register — which is the entire manual check. The other
         * document types stay optional because a business certificate is
         * identified by the document itself.
         */
        $documentNumber = IdentityNumber::normalise($validated['document_number'] ?? null);

        if ($type === VerificationType::NationalId) {
            if ($documentNumber === null) {
                throw ApiException::make(
                    ErrorCode::ValidationFailed,
                    'Enter the NIDA number shown on the card.',
                    ['document_number' => ['The NIDA number is required.']],
                );
            }

            if (! IdentityNumber::isValidNida($documentNumber)) {
                throw ApiException::make(
                    ErrorCode::ValidationFailed,
                    'A NIDA number is '.IdentityNumber::NIDA_LENGTH.' digits.',
                    ['document_number' => ['That is not a valid NIDA number.']],
                );
            }
        }

        $verification = DB::transaction(function () use ($user, $type, $documentNumber, $request): VerificationRequest {
            $verification = new VerificationRequest;
            $verification->forceFill([
                'uuid' => (string) Str::uuid7(),
                'user_id' => $user->getKey(),
                'type' => $type,
                'status' => VerificationStatus::Pending,
                // Normalised to bare digits so the same identity always stores
                // the same string, and encrypted by the model's cast.
                'document_number' => $documentNumber,
            ])->save();

            // MediaCollection::Document is the private one — an identity
            // document must never land in the public media bucket.
            $media = $this->media->upload(
                $request->file('document'),
                $verification,
                $user,
                MediaCollection::Document,
            );

            $verification->forceFill(['document_media_id' => $media->id])->save();

            return $verification;
        });

        return response()->json([
            'data' => [
                'uuid' => $verification->uuid,
                'type' => $verification->type->value,
                'status' => $verification->status->value,
                'created_at' => $verification->created_at?->toAtomString(),
            ],
            'meta' => [
                'message' => 'Submitted. A reviewer will look at it — you will be told either way.',
            ],
        ], 201);
    }
}
