<?php

declare(strict_types=1);

namespace App\Domain\Media\Enums;

enum MediaCollection: string
{
    case Gallery = 'gallery';
    case Avatar = 'avatar';
    case Logo = 'logo';
    case Document = 'document';
    case CategoryImage = 'category_image';

    /**
     * Advertisement artwork.
     *
     * Its own collection rather than reusing Gallery: these are uploaded by an
     * administrator against an `ad_creatives` row, and `saka:media:prune-orphans`
     * decides what to delete by walking owners per collection. Filed under
     * Gallery they would look like listing photos belonging to nothing.
     */
    case AdCreative = 'ad_creative';

    /**
     * Documents (ID scans, business registration) must never land on a public
     * disk — they are served only through short-lived signed URLs.
     */
    public function isPrivate(): bool
    {
        return $this === self::Document;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
