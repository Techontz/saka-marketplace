<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Social identity providers. Google is live from MVP (the frontend login
 * dialog renders a Google button); the others are reserved so adding them is
 * configuration rather than a migration.
 */
enum OAuthProvider: string
{
    case Google = 'google';
    case Apple = 'apple';
    case Facebook = 'facebook';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
