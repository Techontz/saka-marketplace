<?php

declare(strict_types=1);

namespace App\Domain\Listing\Enums;

/** Item condition. Applies to goods verticals, not to Property or Jobs. */
enum ListingCondition: string
{
    case New = 'new';
    case Used = 'used';
    case Refurbished = 'refurbished';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
