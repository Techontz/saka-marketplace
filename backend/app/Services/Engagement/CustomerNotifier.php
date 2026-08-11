<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Domain\Engagement\Enums\NotificationType;
use App\Domain\Listing\Enums\ListingStatus;
use App\Models\Favorite;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The events that fill a customer's notification centre.
 *
 * One class rather than a call to NotificationService scattered through five
 * services: the PAYLOAD is what the frontend renders, so every notification of
 * a given kind has to carry the same keys. Keeping them together is what stops
 * "inquiry replied" arriving with a listing slug in one place and a uuid in
 * another.
 *
 * Every method is best-effort by design. A notification that cannot be written
 * must never fail the action that triggered it — a seller's reply is not lost
 * because the notification insert failed.
 */
class CustomerNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** A business answered a message the customer sent. */
    public function inquiryReplied(Inquiry $inquiry): void
    {
        $recipient = $inquiry->sender_user_id === null
            ? null
            : User::find($inquiry->sender_user_id);

        // Inquiries can be sent by guests, who have nowhere to receive this.
        if ($recipient === null) {
            return;
        }

        $listing = $inquiry->listing;

        $this->notifications->send($recipient, NotificationType::InquiryReplied, [
            'title' => 'You have a reply',
            'body' => $listing === null
                ? 'A business replied to your message.'
                : 'A reply about "'.$listing->title.'".',
            'inquiry_uuid' => $inquiry->uuid,
            'listing_slug' => $listing?->slug,
            'url' => '/account/inquiries/'.$inquiry->uuid,
        ]);
    }

    /** A business answered the customer's review. */
    public function reviewReplied(Review $review): void
    {
        $recipient = User::find($review->reviewer_id);

        if ($recipient === null) {
            return;
        }

        $listing = $review->listing;

        $this->notifications->send($recipient, NotificationType::ReviewReplied, [
            'title' => 'A business replied to your review',
            'body' => $listing === null
                ? 'Your review received a reply.'
                : 'Your review of "'.$listing->title.'" received a reply.',
            'review_uuid' => $review->uuid,
            'listing_slug' => $listing?->slug,
            'url' => $listing === null ? '/account/reviews' : '/listings/'.$listing->slug,
        ]);
    }

    /** A moderator decided on the customer's review. */
    public function reviewModerated(Review $review, bool $approved): void
    {
        $recipient = User::find($review->reviewer_id);

        if ($recipient === null) {
            return;
        }

        $listing = $review->listing;

        $this->notifications->send(
            $recipient,
            $approved ? NotificationType::ReviewApproved : NotificationType::ReviewRejected,
            [
                'title' => $approved ? 'Your review is published' : 'Your review was not published',
                'body' => $approved
                    ? 'Thanks — your review is now visible to other customers.'
                    : ($review->moderation_note ?: 'A moderator decided not to publish this review.'),
                'review_uuid' => $review->uuid,
                'listing_slug' => $listing?->slug,
                'url' => '/account/reviews',
            ],
        );
    }

    /** A moderator decided on a listing this user owns. */
    public function listingModerated(Listing $listing, ListingStatus $status): void
    {
        $owner = User::find($listing->user_id);

        if ($owner === null) {
            return;
        }

        $type = match ($status) {
            ListingStatus::Published => NotificationType::ListingApproved,
            ListingStatus::Rejected => NotificationType::ListingRejected,
            default => null,
        };

        if ($type === null) {
            return;
        }

        $this->notifications->send($owner, $type, [
            'title' => $type === NotificationType::ListingApproved
                ? 'Your listing is live'
                : 'Your listing needs changes',
            'body' => $type === NotificationType::ListingApproved
                ? '"'.$listing->title.'" is now visible on SAKA.'
                : ($listing->rejection_reason ?: 'A moderator asked for changes before this can go live.'),
            'listing_uuid' => $listing->uuid,
            'listing_slug' => $listing->slug,
            'url' => '/listings/'.$listing->uuid,
        ]);
    }

    /**
     * Something changed on a listing people have saved.
     *
     * The owner is excluded — a seller who drops their own price does not need
     * to be told about it, and they already get the seller-side signal.
     */
    public function favoriteListingChanged(Listing $listing, NotificationType $type, string $title, string $body): int
    {
        $watchers = $this->watchersOf($listing);

        if ($watchers->isEmpty()) {
            return 0;
        }

        return $this->notifications->sendMany(
            $watchers,
            $type,
            fn (User $user): array => [
                'title' => $title,
                'body' => $body,
                'listing_slug' => $listing->slug,
                'listing_title' => $listing->title,
                'url' => '/listings/'.$listing->slug,
            ],
        );
    }

    public function favoritePriceChanged(Listing $listing, ?int $from, ?int $to): int
    {
        if ($from === null || $to === null || $from === $to) {
            return 0;
        }

        $dropped = $to < $from;
        $currency = $listing->currency ?? 'TZS';

        return $this->favoriteListingChanged(
            $listing,
            NotificationType::FavoritePriceChanged,
            $dropped ? 'Price dropped' : 'Price changed',
            '"'.$listing->title.'" is now '.$currency.' '.number_format($to).
                ' (was '.$currency.' '.number_format($from).').',
        );
    }

    public function favoriteStatusChanged(Listing $listing, ListingStatus $status): int
    {
        // Only the states a watcher would care about. A listing moving between
        // draft and review is the seller's business, not a watcher's.
        $message = match ($status) {
            ListingStatus::Sold => 'has been marked as sold.',
            ListingStatus::Paused => 'has been paused by the seller.',
            ListingStatus::Archived, ListingStatus::Expired => 'is no longer available.',
            ListingStatus::Published => 'is available again.',
            default => null,
        };

        if ($message === null) {
            return 0;
        }

        return $this->favoriteListingChanged(
            $listing,
            NotificationType::FavoriteStatusChanged,
            'A saved listing changed',
            '"'.$listing->title.'" '.$message,
        );
    }

    /** @return Collection<int, User> */
    private function watchersOf(Listing $listing): Collection
    {
        $ids = Favorite::query()
            ->where('favoritable_type', Listing::class)
            ->where('favoritable_id', $listing->getKey())
            ->whereNull('removed_at')
            ->where('user_id', '!=', $listing->user_id)
            ->pluck('user_id');

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()->whereIn('id', $ids)->get();
    }
}
