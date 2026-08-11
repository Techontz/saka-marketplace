<?php

declare(strict_types=1);

namespace App\Domain\Trust;

/**
 * Handling of national identity numbers.
 *
 * Small on purpose. Two operations, both of which were previously done ad hoc
 * at call sites or not at all:
 *
 *   NORMALISE — a NIDA is quoted with dashes ("19900101-12345-00001-23"), with
 *               spaces, or as bare digits, all for the same person. Stored as
 *               typed, the same identity produces three different strings, and
 *               a duplicate check comparing them finds nothing.
 *
 *   MASK      — the last four digits are enough for a human to confirm "yes,
 *               that is the one I submitted". Everything before them is
 *               identifying data that no screen needs to redisplay.
 */
final class IdentityNumber
{
    /**
     * Tanzanian NIDA numbers are 20 digits.
     *
     * A constant rather than an inline literal because it is asserted in two
     * places (the validation rule and the mask) and they must not drift.
     */
    public const NIDA_LENGTH = 20;

    /** Strip everything that is not a digit. */
    public static function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits === '' ? null : $digits;
    }

    public static function isValidNida(?string $value): bool
    {
        $digits = self::normalise($value);

        return $digits !== null && strlen($digits) === self::NIDA_LENGTH;
    }

    /**
     * The safe-to-display form: •••• plus the last four digits.
     *
     * Returns null for null so a caller can distinguish "not supplied" from
     * "supplied but hidden" — the vendor's own screen shows different copy for
     * each, and collapsing them would tell someone their NIDA was on file when
     * it was not.
     */
    public static function mask(?string $value): ?string
    {
        $digits = self::normalise($value);

        if ($digits === null) {
            return null;
        }

        // A short value is masked ENTIRELY rather than partially. Showing the
        // last four of a six-digit string reveals most of it.
        if (strlen($digits) <= 8) {
            return str_repeat('•', strlen($digits));
        }

        return '•••• •••• •••• '.substr($digits, -4);
    }
}
