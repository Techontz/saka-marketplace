<?php

declare(strict_types=1);

namespace App\Domain\Trust\Enums;

/**
 * Verification ladder.
 *
 * `Phone` is MVP and is a HARD GATE on publishing (Milestone 4 decision 5).
 * The document-backed levels are the v1.1 "Seller Verification" feature and
 * are what drive the VERIFIED badge shown on listing cards.
 */
enum VerificationType: string
{
    case Phone = 'phone';
    case NationalId = 'national_id';
    case Business = 'business';
    case Address = 'address';

    public function requiresDocument(): bool
    {
        return $this !== self::Phone;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
