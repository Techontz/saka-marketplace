<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

enum InquiryStatus: string
{
    case New = 'new';
    case Read = 'read';
    case Replied = 'replied';
    case Spam = 'spam';
    case Closed = 'closed';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
