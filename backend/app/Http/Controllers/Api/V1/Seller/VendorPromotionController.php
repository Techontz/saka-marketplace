<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Seller;

use App\Domain\Advertising\Enums\AdPlacement;
use App\Domain\Advertising\Enums\PromotionRequestStatus;
use App\Domain\Listing\Enums\ListingStatus;
use App\Domain\Media\Enums\MediaCollection;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PromotionRequestResource;
use App\Models\Listing;
use App\Models\Media;
use App\Models\PromotionRequest;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Advertising\PromotionRequestService;
use App\Services\Audit\AuditLogger;
use App\Services\Media\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * A vendor asking to promote their own work.
 *
 * The vendor half of the advertising system. Everything here is a REQUEST — no
 * endpoint on this surface can put anything on the marketplace. Approval and
 * activation live behind `advertising.manage`, which no vendor has.
 *
 * NOTHING ON THIS SURFACE MENTIONS PAYMENT, because SAKA cannot take any. A
 * submitted request is "Pending review", not "Awaiting payment", and it is
 * never called Active until the campaign an administrator minted from it is
 * genuinely serving.
 */
class VendorPromotionController extends Controller
{
    public function __construct(
        private readonly PromotionRequestService $promotions,
        private readonly MediaUploadService $media,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * What a vendor may request, and what they may promote.
     *
     * Placements come from the shared enum filtered by `isVendorRequestable()`
     * — there is no second placement list. A placement the public serving
     * system does not support cannot appear here, because it would not exist in
     * the enum the serving system reads.
     */
    public function options(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'placements' => $this->promotions->requestablePlacements(),
                'promotable_types' => $this->promotions->promotableTypes(),
                'statuses' => array_map(
                    fn (PromotionRequestStatus $status): array => $status->toArray(),
                    PromotionRequestStatus::cases(),
                ),
            ],
        ]);
    }

    /** The vendor's own requests. Scoped to them at the query, not the view. */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(PromotionRequestStatus::values())],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $requests = PromotionRequest::query()
            // THE authorisation boundary for reads. Not a policy on each row —
            // rows belonging to someone else never enter the result set, so
            // there is nothing to accidentally leak through a serialiser.
            ->where('user_id', $request->user()->getKey())
            ->with(['image', 'mobileImage', 'campaign', 'promotable'])
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(20);

        return PromotionRequestResource::collection($requests)->response();
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $promotion = $this->ownedRequest($request->user(), $uuid);

        return response()->json([
            'data' => new PromotionRequestResource(
                $promotion->load(['image', 'mobileImage', 'campaign', 'promotable']),
            ),
        ]);
    }

    /**
     * Start a request.
     *
     * Created as a DRAFT. Artwork is multipart and goes through the media
     * pipeline, so it cannot arrive in this JSON body — and media is
     * polymorphic, so it needs this row to exist before it can attach to
     * anything. The vendor uploads artwork to the draft and then calls
     * `submit`, which is what puts it in front of an administrator.
     */
    public function store(Request $request): JsonResponse
    {
        $vendor = $request->user();

        $validated = $request->validate([
            'promotable_type' => ['required', Rule::in($this->promotions->promotableTypes())],
            // Optional because a vendor has exactly one business profile and
            // there is nothing to identify. Required for a listing, enforced in
            // the service so the rule lives with the resolution.
            'promotable_uuid' => ['sometimes', 'nullable', 'string'],

            'placement' => ['required', Rule::in(
                array_map(fn (AdPlacement $p): string => $p->value, AdPlacement::vendorRequestable()),
            )],

            /*
             * `after_or_equal:today` — a vendor cannot book the past.
             *
             * Deliberately different from the ADMIN campaign form, which allows
             * back-dating: an administrator legitimately records a campaign
             * agreed last week, and refusing that would send them to Tinker. A
             * vendor has no such case, and a start date in the past would mint
             * a campaign that has already partly run.
             */
            'requested_start' => ['required', 'date', 'after_or_equal:today'],
            'requested_end' => ['required', 'date', 'after:requested_start'],

            'headline' => ['required', 'string', 'min:2', 'max:120'],
            'body' => ['sometimes', 'nullable', 'string', 'max:240'],
            'cta_label' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        // Ownership and eligibility, server-side. The frontend only ever offers
        // the vendor's own listings; that is a convenience, not the control.
        $subject = $this->promotions->resolveOwned(
            $vendor,
            $validated['promotable_type'],
            $validated['promotable_uuid'] ?? null,
        );

        $this->promotions->assertPromotable($subject);

        $promotion = new PromotionRequest;
        $promotion->fill([
            'promotable_type' => $subject->getMorphClass(),
            'promotable_id' => $subject->getKey(),
            'placement' => $validated['placement'],
            'requested_start' => $validated['requested_start'],
            'requested_end' => $validated['requested_end'],
            'headline' => $validated['headline'],
            'body' => $validated['body'] ?? null,
            'cta_label' => $validated['cta_label'] ?? null,
        ]);

        // `user_id` and `status` are guarded: taken from the authenticated
        // caller, never from the body. A vendor who could set either could file
        // in someone else's name or approve themselves.
        $promotion->forceFill([
            'user_id' => $vendor->getKey(),
            'status' => PromotionRequestStatus::Draft->value,
        ])->save();

        return response()->json(
            ['data' => new PromotionRequestResource($promotion->load(['campaign', 'promotable']))],
            Response::HTTP_CREATED,
        );
    }

    /**
     * Attach artwork.
     *
     * Straight through `MediaUploadService` — the same pipeline as listing
     * photos and vendor branding. That is where MIME is sniffed from magic
     * bytes rather than the filename, EXIF (including GPS) is stripped, and the
     * WebP variants the banner renders from are generated. A second upload
     * implementation here would silently skip all three.
     */
    public function uploadArtwork(Request $request, string $uuid, string $kind): JsonResponse
    {
        if (! in_array($kind, ['desktop', 'mobile'], true)) {
            throw ApiException::notFound();
        }

        $request->validate([
            'file' => ['required', 'file', 'max:'.((int) config('saka.media.max_image_mb', 5) * 1024)],
        ]);

        $promotion = $this->ownedRequest($request->user(), $uuid);

        /*
         * Only while it is still a draft.
         *
         * Once submitted, the artwork is what an administrator is reviewing;
         * once approved, it is what the LIVE creative points at. Allowing a
         * swap after either would let a vendor change a running advert — or the
         * thing being reviewed — without anyone seeing the new version.
         */
        if (! $promotion->status->isEditable()) {
            throw ApiException::make(
                ErrorCode::ValidationFailed,
                'This request has already been submitted and cannot be changed.',
            );
        }

        $media = $this->media->upload(
            $request->file('file'),
            $promotion,
            $request->user(),
            MediaCollection::AdCreative,
        );

        $column = $kind === 'desktop' ? 'image_media_id' : 'mobile_media_id';
        $previousId = $promotion->{$column};

        $promotion->forceFill([$column => $media->getKey()])->save();

        // Replacing artwork orphans the old file; delete it so a vendor
        // iterating on a banner does not accumulate dead rows and storage.
        if ($previousId !== null && $previousId !== $media->getKey()) {
            $previous = Media::find($previousId);

            if ($previous !== null) {
                $this->media->delete($previous);
            }
        }

        return response()->json([
            'data' => new PromotionRequestResource(
                $promotion->fresh(['image', 'mobileImage', 'campaign', 'promotable']),
            ),
        ]);
    }

    /**
     * Put the draft in front of an administrator.
     *
     * The artwork check lives HERE rather than at creation, because at creation
     * there cannot be any — see `store`. Refusing an artworkless submission is
     * what keeps the review queue full of things somebody can actually decide
     * on, and it tells the vendor immediately rather than two days later.
     */
    public function submit(Request $request, string $uuid): JsonResponse
    {
        $promotion = $this->ownedRequest($request->user(), $uuid);

        if (! $promotion->status->isDraft()) {
            throw ApiException::make(
                ErrorCode::ValidationFailed,
                'This request has already been submitted.',
            );
        }

        if (! $promotion->hasArtwork()) {
            throw ApiException::make(
                ErrorCode::ValidationFailed,
                'Upload desktop artwork before submitting.',
            );
        }

        // Re-checked at submission as well as at creation: a listing can be
        // archived or sold while the vendor is still filling in the wizard.
        $subject = $promotion->promotable;

        if ($subject === null) {
            throw ApiException::make(
                ErrorCode::ValidationFailed,
                'The item you are promoting no longer exists.',
            );
        }

        $this->promotions->assertPromotable($subject);

        $promotion->forceFill(['status' => PromotionRequestStatus::Pending->value])->save();

        $this->audit->record('promotion.requested', $request->user(), $promotion, [], [
            'placement' => $promotion->placement->value,
            'promotable_type' => $promotion->promotable_type,
            'promotable_id' => $promotion->promotable_id,
        ]);

        return response()->json([
            'data' => new PromotionRequestResource(
                $promotion->load(['image', 'mobileImage', 'campaign', 'promotable']),
            ),
        ]);
    }

    /** Withdraw a request before anyone has acted on it. */
    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $promotion = $this->ownedRequest($request->user(), $uuid);

        if (! $promotion->status->isCancellable()) {
            throw ApiException::make(
                ErrorCode::ValidationFailed,
                'Only a draft or a request still awaiting review can be cancelled.',
            );
        }

        $promotion->forceFill(['status' => PromotionRequestStatus::Cancelled->value])->save();

        $this->audit->record('promotion.cancelled', $request->user(), $promotion);

        return response()->json([
            'data' => new PromotionRequestResource($promotion->load(['campaign', 'promotable'])),
        ]);
    }

    /**
     * The vendor's promotable inventory, for the picker.
     *
     * Returns only what is ACTUALLY promotable — published listings — rather
     * than everything with a disabled state. A picker full of greyed-out drafts
     * invites the question "why not?" on every row; a picker of eligible items
     * plus one line of explanation answers it once.
     */
    public function promotable(Request $request): JsonResponse
    {
        $vendor = $request->user();

        $listings = Listing::query()
            ->where('user_id', $vendor->getKey())
            ->where('status', ListingStatus::Published->value)
            ->whereNotNull('published_at')
            ->with('primaryMedia')
            ->orderByDesc('published_at')
            ->limit(100)
            ->get()
            ->map(fn (Listing $listing): array => [
                'type' => 'listing',
                'uuid' => $listing->uuid,
                'label' => $listing->title,
                'image_url' => $listing->primaryMedia?->url('thumb'),
            ]);

        $profile = SellerProfile::query()->where('user_id', $vendor->getKey())->first();

        $business = $profile !== null && $profile->onboarding_completed_at !== null
            ? [[
                'type' => 'business',
                // No identifier: a vendor has exactly one, and the server
                // resolves it from the authenticated caller.
                'uuid' => null,
                'label' => $profile->display_name,
                'image_url' => $profile->logo?->url('thumb'),
            ]]
            : [];

        return response()->json([
            'data' => [...$listings->all(), ...$business],
        ]);
    }

    // ------------------------------------------------------------- internals

    /**
     * The vendor's own request, or a 404.
     *
     * 404 rather than 403 for a request belonging to somebody else: a 403 would
     * confirm the uuid is real, which is the fact being withheld.
     */
    private function ownedRequest(User $vendor, string $uuid): PromotionRequest
    {
        return PromotionRequest::query()
            ->where('uuid', $uuid)
            ->where('user_id', $vendor->getKey())
            ->firstOr(fn () => throw ApiException::notFound('That request was not found.'));
    }
}
