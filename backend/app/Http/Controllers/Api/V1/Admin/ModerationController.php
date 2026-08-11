<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Engagement\Enums\ReviewStatus;
use App\Domain\Listing\Enums\ListingStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ListingResource;
use App\Http\Resources\V1\ReviewResource;
use App\Models\Listing;
use App\Models\ListingReport;
use App\Models\Review;
use App\Services\Engagement\ReviewService;
use App\Services\Listing\ListingStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Moderation queue.
 *
 * Required by the status workflow: without an approver, every listing would sit
 * in Pending Review forever. Deliberately minimal — the full admin surface is a
 * later milestone.
 */
class ModerationController extends Controller
{
    public function __construct(
        private readonly ListingStatusService $status,
        private readonly ReviewService $reviews,
    ) {}

    public function pendingListings(Request $request): AnonymousResourceCollection
    {
        $this->authorize('moderate', Listing::class);

        return ListingResource::collection(
            Listing::query()
                ->where('status', ListingStatus::PendingReview)
                ->with(['category:id,name,slug,icon,parent_id', 'region:id,name,slug', 'district:id,name,slug', 'primaryMedia'])
                ->oldest('updated_at')
                ->paginate(min((int) $request->integer('per_page', 20), 100)),
        );
    }

    /**
     * The abuse-report queue.
     *
     * Grouped by listing rather than listed one report at a time: eleven
     * reports on the same fake listing are one decision, and a queue that
     * makes a moderator take it eleven times is a queue they stop reading.
     */
    public function listingReports(Request $request): JsonResponse
    {
        $this->authorize('moderate', Listing::class);

        $status = $request->string('status')->toString() ?: 'open';

        $reports = ListingReport::query()
            ->where('status', $status)
            ->with(['listing:id,uuid,slug,title,status,user_id', 'reporter:id,uuid,first_name,last_name'])
            ->latest()
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return response()->json([
            'data' => $reports->getCollection()->map(fn (ListingReport $report) => [
                'uuid' => $report->uuid,
                'reason' => $report->reason,
                'reason_label' => ListingReport::REASONS[$report->reason] ?? $report->reason,
                'details' => $report->details,
                'status' => $report->status,
                'created_at' => $report->created_at?->toAtomString(),
                'listing' => $report->listing === null ? null : [
                    'uuid' => $report->listing->uuid,
                    'slug' => $report->listing->slug,
                    'title' => $report->listing->title,
                    'status' => $report->listing->status->value,
                    // How many other people flagged the same listing — the
                    // number that actually decides whether to act.
                    'open_reports' => ListingReport::query()
                        ->where('listing_id', $report->listing_id)
                        ->where('status', 'open')
                        ->count(),
                ],
                'reporter' => $report->reporter === null
                    ? ['name' => 'Guest']
                    : ['uuid' => $report->reporter->uuid, 'name' => trim($report->reporter->first_name.' '.$report->reporter->last_name)],
            ])->all(),
            'meta' => [
                'current_page' => $reports->currentPage(),
                'last_page' => $reports->lastPage(),
                'per_page' => $reports->perPage(),
                'total' => $reports->total(),
            ],
        ]);
    }

    /** Close a report. Acting on the listing itself is a separate decision. */
    public function resolveReport(Request $request, string $uuid): JsonResponse
    {
        $this->authorize('moderate', Listing::class);

        $validated = $request->validate([
            'status' => ['required', 'in:actioned,dismissed'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $report = ListingReport::query()->where('uuid', $uuid)->firstOrFail();

        $report->forceFill([
            'status' => $validated['status'],
            'resolved_by' => $request->user()?->getKey(),
            'resolved_at' => now(),
            'resolution_note' => $validated['note'] ?? null,
        ])->save();

        return response()->json(['data' => ['uuid' => $report->uuid, 'status' => $report->status]]);
    }

    public function approve(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('moderate', Listing::class);

        $updated = $this->status->transition($listing, ListingStatus::Published, $request->user());

        return response()->json([
            'data' => ['uuid' => $updated->uuid, 'status' => $updated->status->value],
        ]);
    }

    public function reject(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('moderate', Listing::class);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $updated = $this->status->transition(
            $listing,
            ListingStatus::Rejected,
            $request->user(),
            $validated['reason'],
        );

        return response()->json([
            'data' => [
                'uuid' => $updated->uuid,
                'status' => $updated->status->value,
                'rejection_reason' => $updated->rejection_reason,
            ],
        ]);
    }

    /** The VERIFIED badge the frontend already renders on listing cards. */
    public function verify(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('moderate', Listing::class);

        $listing->forceFill(['is_verified' => $request->boolean('verified', true)])->save();

        return response()->json([
            'data' => ['uuid' => $listing->uuid, 'is_verified' => (bool) $listing->is_verified],
        ]);
    }

    /** Promotions (v1.1) — the columns already exist, so this is just a switch. */
    public function feature(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('feature', Listing::class);

        $validated = $request->validate([
            'featured' => ['required', 'boolean'],
            'until' => ['nullable', 'date', 'after:now'],
            'boost_score' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        $listing->forceFill([
            'is_featured' => $validated['featured'],
            'featured_until' => $validated['featured'] ? ($validated['until'] ?? null) : null,
            'boost_score' => $validated['boost_score'] ?? $listing->boost_score,
        ])->save();

        return response()->json([
            'data' => [
                'uuid' => $listing->uuid,
                'is_featured' => (bool) $listing->is_featured,
                'featured_until' => $listing->featured_until?->toAtomString(),
            ],
        ]);
    }

    public function pendingReviews(Request $request): AnonymousResourceCollection
    {
        $this->authorize('moderate', Review::class);

        return ReviewResource::collection(
            Review::query()->pending()
                ->with(['reviewer:id,uuid,first_name', 'listing:id,slug,title'])
                ->oldest()
                ->paginate(min((int) $request->integer('per_page', 20), 100)),
        );
    }

    public function moderateReview(Request $request, Review $review): JsonResponse
    {
        $this->authorize('moderate', Review::class);

        $validated = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $this->reviews->moderate(
            $review,
            ReviewStatus::from($validated['status']),
            $request->user(),
            $validated['note'] ?? null,
        );

        return response()->json(['data' => new ReviewResource($updated)]);
    }
}
