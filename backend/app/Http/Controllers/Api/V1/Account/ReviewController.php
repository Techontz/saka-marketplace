<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Domain\Engagement\Enums\ReviewStatus;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Http\Controllers\Concerns\ResolvesVisibleListings;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ReviewResource;
use App\Models\Listing;
use App\Models\Review;
use App\Services\Engagement\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ReviewController extends Controller
{
    use ResolvesVisibleListings;

    public function __construct(private readonly ReviewService $reviews) {}

    /** Public: approved reviews for a listing. */
    public function forListing(Request $request, string $slug): AnonymousResourceCollection
    {
        $listing = Listing::query()->publiclyVisible()->where('slug', $slug)->firstOrFail();

        return ReviewResource::collection(
            Review::query()
                ->where('listing_id', $listing->id)
                ->approved()
                ->with('reviewer:id,uuid,first_name')
                ->latest()
                ->paginate(min((int) $request->integer('per_page', 10), 50)),
        );
    }

    public function store(Request $request, Listing $listing): JsonResponse
    {
        // A listing the user cannot read must not be reviewable.
        $this->assertListingIsActionable($listing, $request->user());

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);

        $review = $this->reviews->create($request->user(), $listing, $validated);

        return response()->json(['data' => new ReviewResource($review)], Response::HTTP_CREATED);
    }

    /** The reviews this user has written. */
    public function mine(Request $request): AnonymousResourceCollection
    {
        return ReviewResource::collection(
            $request->user()->reviewsWritten()
                ->with('listing:id,slug,title')
                ->latest()
                ->paginate(min((int) $request->integer('per_page', 20), 100)),
        );
    }

    /**
     * Edit a review you wrote.
     *
     * An edited review goes BACK to pending when moderation is on. Without
     * that, a review could be published as something innocuous and then edited
     * into abuse after approval — the moderator's decision has to apply to the
     * text that is actually live.
     *
     * The seller's reply is deliberately left in place: deleting their answer
     * because the customer fixed a typo would be worse than the mismatch, and
     * the reply is timestamped so the sequence stays readable.
     */
    public function update(Request $request, Review $review): JsonResponse
    {
        $this->authorize('update', $review);

        $validated = $request->validate([
            'rating' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'body' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        if ($validated === []) {
            throw ApiException::make(ErrorCode::ValidationFailed, 'Nothing to change.');
        }

        $review->fill($validated);

        $needsRemoderation = config('saka.listings.require_moderation')
            && $review->status === ReviewStatus::Approved
            && ($review->isDirty('body') || $review->isDirty('title') || $review->isDirty('rating'));

        if ($needsRemoderation) {
            $review->forceFill([
                'status' => ReviewStatus::Pending,
                'moderated_at' => null,
                'moderated_by' => null,
            ]);
        }

        $review->save();

        // The rating is denormalised onto the seller, so changing a score has
        // to move their average with it.
        $this->reviews->recalculateSellerRating((int) $review->seller_id);

        return response()->json([
            'data' => new ReviewResource($review->fresh()->load('listing:id,slug,title')),
            'meta' => [
                'pending_remoderation' => $needsRemoderation,
            ],
        ]);
    }

    /**
     * Report someone else's review.
     *
     * The mirror of the vendor's report, and with the same property: it does
     * NOT hide the review. Reporting is a request for a moderator to look, not
     * a way for either side to remove criticism.
     *
     * Recorded to the log rather than a reports table, matching the seller-side
     * report — a `review_reports` table with its own queue is the right end
     * state and is recorded as debt.
     */
    public function report(Request $request, Review $review): JsonResponse
    {
        $user = $request->user();

        if ($review->reviewer_id === $user->getKey()) {
            throw ApiException::make(
                ErrorCode::Conflict,
                'This is your own review — edit or delete it instead.',
            );
        }

        // Only a published review is reportable: anything else is not visible
        // to this customer, and confirming it exists would leak moderation.
        if ($review->status !== ReviewStatus::Approved) {
            throw ApiException::notFound('Review not found.');
        }

        $validated = $request->validate([
            'reason' => ['required', Rule::in(['spam', 'offensive', 'false_information', 'personal_information', 'other'])],
            'details' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        Log::warning('review.reported', [
            'review_id' => $review->getKey(),
            'review_uuid' => $review->uuid,
            'listing_id' => $review->listing_id,
            'reported_by' => $user->getKey(),
            'reporter_role' => 'customer',
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

    public function destroy(Request $request, Review $review): JsonResponse
    {
        $this->authorize('delete', $review);

        $sellerId = (int) $review->seller_id;
        $review->delete();
        $this->reviews->recalculateSellerRating($sellerId);

        return response()->json(['data' => ['message' => 'Review removed.']]);
    }

    /** The seller's single public response. */
    public function reply(Request $request, Review $review): JsonResponse
    {
        $this->authorize('reply', $review);

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:2000'],
        ]);

        return response()->json([
            'data' => new ReviewResource($this->reviews->reply($review, $validated['body'])),
        ]);
    }
}
