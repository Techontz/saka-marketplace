<?php

declare(strict_types=1);

namespace App\Services\Listing;

use App\Domain\Listing\Enums\ListingStatus;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Models\Listing;
use App\Models\ListingStatusHistory;
use App\Models\User;
use App\Services\Engagement\CustomerNotifier;
use App\Services\Seller\SellerCounterService;
use Illuminate\Support\Facades\DB;

/**
 * The single place a listing's status may change.
 *
 * Every transition is checked against ListingStatus::allowedTransitions() and
 * recorded in listing_status_histories. Nothing else in the codebase assigns
 * `status` directly — that is what makes the workflow auditable and stops a
 * listing from, say, jumping straight from Draft to Published without review.
 */
class ListingStatusService
{
    public function __construct(
        private readonly ListingIndexer $indexer,
        private readonly CustomerNotifier $notifier,
        private readonly SellerCounterService $counters,
    ) {}

    public function transition(
        Listing $listing,
        ListingStatus $target,
        ?User $actor = null,
        ?string $reason = null,
    ): Listing {
        $from = $listing->status;

        if ($from === $target) {
            return $listing;
        }

        if (! $from->canTransitionTo($target)) {
            throw ApiException::make(
                ErrorCode::InvalidStateTransition,
                "A listing cannot move from {$from->label()} to {$target->label()}.",
                ['from' => $from->value, 'to' => $target->value,
                    'allowed' => array_map(fn (ListingStatus $s) => $s->value, $from->allowedTransitions())],
            );
        }

        $updated = DB::transaction(function () use ($listing, $from, $target, $actor, $reason): Listing {
            $attributes = ['status' => $target];

            if ($target === ListingStatus::Published) {
                $attributes['published_at'] = $listing->published_at ?? now();
                $attributes['expires_at'] = now()->addDays((int) config('saka.listings.expiry_days'));
                $attributes['rejection_reason'] = null;
            }

            if ($target === ListingStatus::Rejected) {
                $attributes['rejection_reason'] = $reason;
            }

            $listing->forceFill($attributes)->save();

            ListingStatusHistory::create([
                'listing_id' => $listing->id,
                'from_status' => $from->value,
                'to_status' => $target->value,
                'changed_by' => $actor?->getKey(),
                'reason' => $reason,
                'created_at' => now(),
            ]);

            // Keep the search projection in step with visibility.
            $target === ListingStatus::Published
                ? $this->indexer->index($listing)
                : $this->indexer->remove($listing);

            return $listing->fresh();
        });

        /*
         * Two audiences, two different notifications:
         *
         *   - the OWNER, when a moderator approved or rejected their listing;
         *   - everyone who SAVED it, when it sold, paused or came back.
         *
         * Both are outside the transaction: a notification failure must not
         * roll back the status change that actually happened.
         */
        // The business directory reads `active_listings`; a status change that
        // did not update it would leave a business advertising stock it no
        // longer has, or hiding stock it does.
        $this->counters->recount((int) $updated->user_id);

        if ($actor !== null && $actor->getKey() !== $updated->user_id) {
            $this->notifier->listingModerated($updated, $target);
        }

        $this->notifier->favoriteStatusChanged($updated, $target);

        return $updated;
    }

    /**
     * Submit for review — or publish straight away when moderation is disabled.
     *
     * The phone gate is enforced here as well as in middleware: middleware
     * guards the HTTP route, this guards the domain, and a future admin action
     * or console command must not be able to bypass it.
     */
    public function submitForReview(Listing $listing, User $actor): Listing
    {
        if (! $actor->canPublishListings()) {
            throw ApiException::phoneNotVerified();
        }

        if ($listing->media()->count() === 0) {
            throw ApiException::make(
                ErrorCode::InvalidStateTransition,
                'Add at least one photo before submitting this listing.',
                ['reason' => 'missing_primary_image'],
            );
        }

        $listing = $this->transition($listing, ListingStatus::PendingReview, $actor);

        if (! config('saka.listings.require_moderation')) {
            $listing = $this->transition($listing, ListingStatus::Published, $actor);
        }

        return $listing;
    }
}
