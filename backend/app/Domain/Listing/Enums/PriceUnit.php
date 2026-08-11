<?php

declare(strict_types=1);

namespace App\Domain\Listing\Enums;

/** Billing period a price refers to. Frontend already carries `priceUnit`. */
enum PriceUnit: string
{
    case Total = 'total';
    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case Daily = 'daily';
    case Hourly = 'hourly';
    case Negotiable = 'negotiable';

    public function suffix(): string
    {
        return match ($this) {
            self::Total => '',
            self::Monthly => '/ monthly',
            self::Yearly => '/ yearly',
            self::Daily => '/ day',
            self::Hourly => '/ hour',
            self::Negotiable => '(negotiable)',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
