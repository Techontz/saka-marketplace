<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

enum UserStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Banned = 'banned';

    /** Only these may authenticate. */
    public function canAuthenticate(): bool
    {
        return in_array($this, [self::Active, self::Pending], strict: true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
