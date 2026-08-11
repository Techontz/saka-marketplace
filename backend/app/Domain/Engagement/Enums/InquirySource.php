<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/**
 * Where an inquiry originated. The frontend has two entry points:
 * "Contact Seller" on a listing, and the standalone /contact form.
 */
enum InquirySource: string
{
    case Listing = 'listing';
    case ContactPage = 'contact_page';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
