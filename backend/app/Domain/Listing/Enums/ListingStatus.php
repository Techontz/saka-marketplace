<?php

declare(strict_types=1);

namespace App\Domain\Listing\Enums;

/**
 * Lifecycle of a listing.
 *
 * The transition table below is the single source of truth for what may follow
 * what; ListingService consults it rather than scattering `if` checks.
 */
enum ListingStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Published = 'published';
    case Rejected = 'rejected';
    case Paused = 'paused';
    case Expired = 'expired';
    case Sold = 'sold';
    case Archived = 'archived';

    /**
     * Statuses visible to guests and buyers.
     *
     * @return array<int, self>
     */
    public static function publiclyVisible(): array
    {
        return [self::Published];
    }

    /** @return array<int, self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingReview, self::Archived],
            self::PendingReview => [self::Published, self::Rejected, self::Draft, self::Archived],
            self::Published => [self::Paused, self::Sold, self::Expired, self::Archived],
            self::Rejected => [self::Draft, self::Archived],
            self::Paused => [self::Published, self::Archived],
            self::Expired => [self::PendingReview, self::Archived],
            self::Sold => [self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }

    /** Statuses that occupy a seller's active-listing quota. */
    public function countsTowardQuota(): bool
    {
        return in_array($this, [self::PendingReview, self::Published, self::Paused], strict: true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingReview => 'Pending review',
            self::Published => 'Published',
            self::Rejected => 'Rejected',
            self::Paused => 'Paused',
            self::Expired => 'Expired',
            self::Sold => 'Sold',
            self::Archived => 'Archived',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
