<?php

declare(strict_types=1);

namespace App\Domain\Listing\Enums;

/**
 * What the seller intends to do with the item.
 *
 * Lower-cased for storage. Property and Vehicles use rent/sale/lease; Jobs and
 * Services use `hire`, which was added once those verticals were populated and
 * every listing in them turned out to have no honest purpose to store.
 */
enum ListingPurpose: string
{
    case Rent = 'rent';
    case Sale = 'sale';
    case Lease = 'lease';

    /** A vacancy to fill or a service to book — not a thing that changes hands. */
    case Hire = 'hire';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * The phrase a customer reads on a card or a detail page.
     *
     * "For hire" rather than "For Hire" so callers can place it mid-sentence;
     * the UI capitalises where it needs to.
     */
    public function phrase(): string
    {
        return match ($this) {
            self::Rent => 'for rent',
            self::Sale => 'for sale',
            self::Lease => 'to lease',
            self::Hire => 'for hire',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
