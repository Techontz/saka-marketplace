<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Domain\Engagement\Enums\ReviewStatus;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Models\Listing;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Moderated reviews (promoted to MVP by Milestone 4 decision 2).
 *
 * Only Approved rows feed the seller's aggregate rating, so a pending or
 * rejected review can never move the number a buyer sees.
 */
class ReviewService
{
    public function __construct(private readonly CustomerNotifier $notifier) {}

    /** @param array<string, mixed> $data */
    public function create(User $reviewer, Listing $listing, array $data): Review
    {
        if ($reviewer->getKey() === $listing->user_id) {
            throw ApiException::make(ErrorCode::Conflict, 'You cannot review your own listing.');
        }

        $already = Review::query()
            ->where('reviewer_id', $reviewer->getKey())
            ->where('listing_id', $listing->getKey())
            ->exists();

        if ($already) {
            throw ApiException::make(ErrorCode::Conflict, 'You have already reviewed this listing.');
        }

        $review = Review::create([
            'seller_id' => $listing->user_id,
            'listing_id' => $listing->getKey(),
            'reviewer_id' => $reviewer->getKey(),
            'rating' => (int) $data['rating'],
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
        ]);

        // Auto-approve only when moderation is off; otherwise it waits.
        if (! config('saka.listings.require_moderation')) {
            $this->moderate($review, ReviewStatus::Approved, null);
        }

        return $review->fresh();
    }

    public function moderate(Review $review, ReviewStatus $status, ?User $moderator, ?string $note = null): Review
    {
        $moderated = DB::transaction(function () use ($review, $status, $moderator, $note): Review {
            $review->forceFill([
                'status' => $status,
                'moderated_by' => $moderator?->getKey(),
                'moderated_at' => now(),
                'moderation_note' => $note,
            ])->save();

            $this->recalculateSellerRating((int) $review->seller_id);

            return $review->fresh();
        });

        /*
         * Only a MODERATOR'S decision is announced. Auto-approval on creation
         * calls this too, and telling someone "your review is published"
         * one millisecond after they wrote it is noise.
         */
        if ($moderator !== null) {
            $this->notifier->reviewModerated(
                $moderated->loadMissing('listing:id,slug,title'),
                $status === ReviewStatus::Approved,
            );
        }

        return $moderated;
    }

    public function reply(Review $review, string $body): Review
    {
        $review->forceFill(['reply_body' => $body, 'replied_at' => now()])->save();

        $review = $review->fresh();

        $this->notifier->reviewReplied($review->loadMissing('listing:id,slug,title'));

        return $review;
    }

    /**
     * Recomputes from source rather than incrementing a running average —
     * an incremental average drifts and cannot be corrected after a review is
     * edited, rejected or deleted.
     */
    public function recalculateSellerRating(int $sellerId): void
    {
        // DB::table, not Review::query(): hydrating an aggregate onto a model
        // gives you undefined properties that only look like they work.
        $stats = DB::table('reviews')
            ->where('seller_id', $sellerId)
            ->where('status', ReviewStatus::Approved->value)
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as total, AVG(rating) as average')
            ->first();

        $count = (int) ($stats->total ?? 0);

        DB::table('seller_profiles')->where('user_id', $sellerId)->update([
            'rating_count' => $count,
            'rating_avg' => $count > 0 ? round((float) $stats->average, 2) : null,
            'updated_at' => now(),
        ]);
    }
}
