<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/**
 * Reviews are moderated before they affect a seller's aggregate rating.
 * Only Approved rows are counted by the rating rollup.
 */
enum ReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function countsTowardRating(): bool
    {
        return $this === self::Approved;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
