<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Seller;

use App\Domain\Engagement\Enums\InquiryStatus;
use App\Domain\Engagement\Enums\ReviewStatus;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ReviewResource;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * A vendor's engagement surfaces: analytics, reviews received, inquiry states.
 *
 * The listing/media/inquiry-reply endpoints already existed. What did not:
 *
 *   - any TIME SERIES, so the dashboard could only show lifetime totals and
 *     the requested charts had nothing to draw;
 *   - reviews RECEIVED. `/account/reviews` returns reviews the user WROTE,
 *     which is the opposite of what a vendor needs;
 *   - anything to close an inquiry. The inbox could be read and replied to,
 *     and then grew forever.
 */
class VendorEngagementController extends Controller
{
    /**
     * Daily series for the vendor's own listings.
     *
     * Scoped to the caller at the query level rather than filtered afterwards —
     * a seller must never be able to widen this into platform-wide analytics by
     * changing a parameter.
     */
    public function analytics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:7', 'max:365'],
        ]);

        $user = $request->user();
        $days = (int) ($validated['days'] ?? 30);
        $since = now()->subDays($days - 1)->startOfDay();

        $listingIds = DB::table('listings')
            ->where('user_id', $user->getKey())
            ->whereNull('deleted_at')
            ->pluck('id');

        // No listings means empty series, not an error — a brand-new vendor
        // opening the dashboard is the most common case there is.
        if ($listingIds->isEmpty()) {
            return response()->json([
                'data' => [
                    'range' => ['from' => $since->toDateString(), 'to' => now()->toDateString(), 'days' => $days],
                    'views' => $this->fill(collect(), $since, $days),
                    'favorites' => $this->fill(collect(), $since, $days),
                    'inquiries' => $this->fill(collect(), $since, $days),
                    'reviews' => $this->fill(collect(), $since, $days),
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'range' => ['from' => $since->toDateString(), 'to' => now()->toDateString(), 'days' => $days],

                // From the daily rollup, not `listing_views`: pre-aggregated,
                // so this reads tens of rows rather than millions.
                'views' => $this->fill(
                    DB::table('listing_view_daily')
                        ->whereIn('listing_id', $listingIds)
                        ->where('date', '>=', $since->toDateString())
                        ->groupBy('date')
                        ->orderBy('date')
                        ->selectRaw('date, SUM(views) as value')
                        ->pluck('value', 'date'),
                    $since,
                    $days,
                ),

                'favorites' => $this->fill($this->dailyCount('favorites', 'favoritable_id', $listingIds, $since), $since, $days),
                'inquiries' => $this->fill($this->dailyCount('inquiries', 'listing_id', $listingIds, $since), $since, $days),
                'reviews' => $this->fill($this->dailyCount('reviews', 'listing_id', $listingIds, $since), $since, $days),
            ],
        ]);
    }

    /**
     * Reviews left ON this vendor's listings.
     */
    public function reviews(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['pending', 'approved', 'rejected'])],
            'replied' => ['nullable', 'boolean'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $reviews = Review::query()
            ->where('seller_id', $request->user()->getKey())
            ->with(['reviewer:id,uuid,first_name', 'listing:id,uuid,slug,title'])
            ->when($validated['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->when($validated['rating'] ?? null, fn ($q, int $rating) => $q->where('rating', $rating))
            ->when(
                isset($validated['replied']),
                fn ($q) => $request->boolean('replied')
                    ? $q->whereNotNull('reply_body')
                    : $q->whereNull('reply_body'),
            )
            ->latest('created_at')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return ReviewResource::collection($reviews);
    }

    /**
     * Answer a review publicly.
     *
     * One reply per review, editable afterwards. A thread would turn every
     * disagreement into an argument conducted on the vendor's own listing;
     * a single right of reply is what the rating system is missing, and the
     * columns for it (`reply_body`, `replied_at`) already existed.
     *
     * Only APPROVED reviews may be answered — replying to something still in
     * moderation would publish the vendor's response to a review that may never
     * appear, and the reply is shown alongside it.
     */
    public function replyToReview(Request $request, Review $review): JsonResponse
    {
        $user = $request->user();

        if ($review->seller_id !== $user->getKey()) {
            throw ApiException::notFound();
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:2000'],
        ]);

        if ($review->status !== ReviewStatus::Approved) {
            throw ApiException::make(
                ErrorCode::Conflict,
                'That review is not published yet, so a reply would have nothing to appear under.',
            );
        }

        $review->forceFill([
            'reply_body' => $validated['body'],
            // Kept at the first reply: an edited answer is still an answer, and
            // moving the timestamp would misreport how fast the vendor responds.
            'replied_at' => $review->replied_at ?? now(),
        ])->save();

        return response()->json([
            'data' => new ReviewResource(
                $review->load(['reviewer:id,uuid,first_name', 'listing:id,uuid,slug,title']),
            ),
        ]);
    }

    /**
     * Report a review for moderator attention.
     *
     * Does NOT hide the review. A vendor who could remove criticism by
     * reporting it would make the whole rating system worthless, so this
     * records the objection and routes it to moderation — the review stays
     * exactly as visible as it was.
     */
    public function reportReview(Request $request, Review $review): JsonResponse
    {
        $user = $request->user();

        if ($review->seller_id !== $user->getKey()) {
            // 404, not 403: whether a review exists is not this vendor's
            // business unless it is about them.
            throw ApiException::notFound();
        }

        $validated = $request->validate([
            'reason' => ['required', Rule::in(['spam', 'offensive', 'false_information', 'not_a_customer', 'other'])],
            'details' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        /*
         * Recorded to the log rather than a reports table.
         *
         * A `review_reports` table with its own moderation queue is the right
         * end state, but it is a schema and an admin screen — and shipping the
         * vendor-facing half without it would silently drop reports on the
         * floor, which is worse than not offering the button. This makes the
         * report real and findable today; the queue is recorded as debt.
         */
        Log::warning('review.reported', [
            'review_id' => $review->getKey(),
            'review_uuid' => $review->uuid,
            'listing_id' => $review->listing_id,
            'reported_by' => $user->getKey(),
            'reason' => $validated['reason'],
            'details' => $validated['details'],
        ]);

        return response()->json([
            'data' => [
                'reported' => true,
                'message' => 'Thanks — a moderator will look at this review. It stays visible in the meantime.',
            ],
        ]);
    }

    /**
     * Move an inquiry through the inbox states.
     *
     * `read` is set implicitly when the vendor opens one; this endpoint is for
     * the deliberate ones — resolving a conversation, or filing it away.
     */
    public function updateInquiry(Request $request, Inquiry $inquiry): JsonResponse
    {
        $user = $request->user();

        $this->assertOwnsInquiry($inquiry, $user);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['read', 'closed', 'spam'])],
        ]);

        $target = InquiryStatus::from($validated['status']);

        /*
         * `replied` is not settable here, and that is deliberate: it means "the
         * vendor answered", which is true only when a reply was actually sent.
         * Letting it be set by hand would corrupt response-rate reporting,
         * which is a public trust signal on the vendor's profile.
         */
        if ($inquiry->status === InquiryStatus::Replied && $target === InquiryStatus::Read) {
            throw ApiException::make(
                ErrorCode::Conflict,
                'A replied inquiry cannot be marked unread again.',
            );
        }

        $inquiry->forceFill([
            'status' => $target,
            'read_at' => $inquiry->read_at ?? now(),
        ])->save();

        return response()->json([
            'data' => ['uuid' => $inquiry->uuid, 'status' => $inquiry->status->value],
        ]);
    }

    // ------------------------------------------------------------- internals

    private function assertOwnsInquiry(Inquiry $inquiry, User $user): void
    {
        // An inquiry belongs to the vendor through its listing.
        $owns = DB::table('listings')
            ->where('id', $inquiry->listing_id)
            ->where('user_id', $user->getKey())
            ->exists();

        if (! $owns) {
            throw ApiException::notFound();
        }
    }

    /**
     * @param  Collection<int, int>  $listingIds
     * @return Collection<array-key, mixed>
     */
    private function dailyCount(string $table, string $column, Collection $listingIds, Carbon $since): Collection
    {
        $query = DB::table($table)
            ->whereIn($column, $listingIds)
            ->where('created_at', '>=', $since)
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->selectRaw('DATE(created_at) as bucket, COUNT(*) as value');

        if ($table === 'reviews') {
            $query->whereNull('deleted_at');
        }

        // Favourites are polymorphic: without the type constraint a saved
        // BUSINESS whose id happens to match a listing id would be counted as
        // a save of that listing.
        if ($table === 'favorites') {
            $query->where('favoritable_type', Listing::class);
        }

        return $query->pluck('value', 'bucket');
    }

    /**
     * Pads a sparse `date => count` map to one point per day.
     *
     * A chart built straight from a GROUP BY draws a straight line across days
     * with no activity, which reads as steady traffic rather than none.
     *
     * @param  Collection<array-key, mixed>  $rows
     * @return array<int, array{date: string, value: int}>
     */
    private function fill(Collection $rows, Carbon $since, int $days): array
    {
        $series = [];
        $cursor = $since->copy();

        for ($i = 0; $i < $days; $i++) {
            $date = $cursor->toDateString();
            $series[] = ['date' => $date, 'value' => (int) ($rows[$date] ?? 0)];
            $cursor->addDay();
        }

        return $series;
    }
}
