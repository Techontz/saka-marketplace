<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/**
 * The kinds of thing SAKA tells a customer about.
 *
 * Each case is also a PREFERENCE KEY: a customer switching "favourite alerts"
 * off silences exactly the cases whose `preferenceKey()` matches. Grouping them
 * rather than exposing eleven switches keeps the settings screen honest — a
 * user cannot meaningfully choose between "price dropped" and "back on sale".
 */
enum NotificationType: string
{
    // ---- things that happened to a listing the customer saved ------------
    case FavoritePriceChanged = 'favorite.price_changed';
    case FavoriteStatusChanged = 'favorite.status_changed';

    // ---- replies the customer is waiting for ------------------------------
    case InquiryReplied = 'inquiry.replied';
    case ReviewReplied = 'review.replied';

    // ---- the customer's own content --------------------------------------
    case ReviewApproved = 'review.approved';
    case ReviewRejected = 'review.rejected';

    // ---- the customer's own listings, when they also sell -----------------
    case ListingApproved = 'listing.approved';
    case ListingRejected = 'listing.rejected';
    case ListingExpiring = 'listing.expiring';

    /**
     * The preference group this notification belongs to.
     *
     * Deliberately coarse. Note that moderation messages have NO switch: being
     * told your review was rejected is not marketing, and a customer who has
     * silenced it would experience the removal as content vanishing for no
     * reason.
     */
    public function preferenceKey(): ?string
    {
        return match ($this) {
            self::FavoritePriceChanged, self::FavoriteStatusChanged => 'favorite_alerts',
            self::InquiryReplied => 'inquiry_replies',
            self::ReviewReplied => 'review_replies',
            self::ListingApproved, self::ListingRejected, self::ListingExpiring => 'listing_updates',
            self::ReviewApproved, self::ReviewRejected => null,
        };
    }

    /**
     * The switches a customer actually sees, and their defaults.
     *
     * @return array<string, bool>
     */
    public static function preferenceDefaults(): array
    {
        return [
            'favorite_alerts' => true,
            'inquiry_replies' => true,
            'review_replies' => true,
            'listing_updates' => true,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::FavoritePriceChanged => 'Price changed',
            self::FavoriteStatusChanged => 'Availability changed',
            self::InquiryReplied => 'Reply to your message',
            self::ReviewReplied => 'Reply to your review',
            self::ReviewApproved => 'Your review is published',
            self::ReviewRejected => 'Your review was not published',
            self::ListingApproved => 'Your listing is live',
            self::ListingRejected => 'Your listing needs changes',
            self::ListingExpiring => 'Your listing is about to expire',
        };
    }

    /** Where tapping the notification should take the customer. */
    public function isAboutOwnListing(): bool
    {
        return in_array(
            $this,
            [self::ListingApproved, self::ListingRejected, self::ListingExpiring],
            strict: true,
        );
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
