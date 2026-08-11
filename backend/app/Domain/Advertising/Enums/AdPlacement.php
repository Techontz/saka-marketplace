<?php

declare(strict_types=1);

namespace App\Domain\Advertising\Enums;

/**
 * Where an advertisement can appear.
 *
 * An ENUM, not a table — the same call made for BusinessType, and for the same
 * reason. Every placement here has a component rendering it at a known size in
 * a known slot. A row an administrator could add would be inventory that can be
 * sold and scheduled but never displayed, and the failure would be silent: the
 * campaign would look active in the admin, report zero impressions, and nobody
 * would know why for a month.
 *
 * Adding inventory is a case here plus the component that renders it.
 *
 * The dimensions are not decoration. `AdSlot` reserves the box BEFORE the
 * creative loads, and a slot that reserves the wrong height is a layout shift
 * on the most valuable screen in the product — so the ratio each placement
 * promises is stated once, here, and both the admin's upload guidance and the
 * frontend's reserved box are derived from it.
 */
enum AdPlacement: string
{
    case HomepageHero = 'homepage_hero';
    case HomepageStrip = 'homepage_strip';
    case ListingsTop = 'listings_top';
    case ListingsInline = 'listings_inline';
    case Businesses = 'businesses';
    case Specialists = 'specialists';
    case CategoryPage = 'category_page';
    case Footer = 'footer';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::HomepageHero => 'Homepage — hero promotion',
            self::HomepageStrip => 'Homepage — between sections',
            self::ListingsTop => 'Listings — below the filters',
            self::ListingsInline => 'Listings — between grid rows',
            self::Businesses => 'Business directory',
            self::Specialists => 'Specialist directory',
            self::CategoryPage => 'Category page',
            self::Footer => 'Footer promotion',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::HomepageHero => 'The largest unit on the site. One at a time, above the fold.',
            self::HomepageStrip => 'A thin strip between homepage sections.',
            self::ListingsTop => 'A single strip under the search and filter bar.',
            self::ListingsInline => 'Woven between rows of the listing grid as the visitor scrolls.',
            self::Businesses => 'Between sections of the business directory.',
            self::Specialists => 'Between sections of the specialist directory.',
            self::CategoryPage => 'On a category landing page. Target the category to match it.',
            self::Footer => 'Below the page content, above the footer proper.',
        };
    }

    /**
     * Width-to-height ratio of the reserved box, desktop and mobile.
     *
     * Mobile is consistently taller-per-width because the same message has to
     * fit a 360px column. A placement that reused the desktop ratio on a phone
     * would either crop the headline out or leave a 40px-tall sliver.
     *
     * @return array{desktop: float, mobile: float}
     */
    public function aspectRatio(): array
    {
        return match ($this) {
            self::HomepageHero => ['desktop' => 1600 / 420, 'mobile' => 720 / 480],
            self::HomepageStrip, self::ListingsTop, self::ListingsInline,
            self::Businesses, self::Specialists, self::CategoryPage,
            self::Footer => ['desktop' => 1600 / 200, 'mobile' => 720 / 240],
        };
    }

    /**
     * How many campaigns may be shown in this slot at once.
     *
     * The hero is exclusive: two heroes stacked is not a homepage, it is a
     * billboard. The inline placements return more than one because the grid
     * has several insertion points on a long scroll and repeating the same
     * banner at each of them is what makes a site feel spammy.
     */
    public function maxConcurrent(): int
    {
        return match ($this) {
            self::HomepageHero => 1,
            self::ListingsInline => 3,
            default => 1,
        };
    }

    /**
     * Whether targeting a category is meaningful here.
     *
     * A category page ad that ignores the category is worse than no ad — it is
     * an ad for apartments on the page for used cars. The admin marks the
     * targeting field required for these.
     */
    public function expectsCategoryTargeting(): bool
    {
        return $this === self::CategoryPage;
    }

    /**
     * Whether a VENDOR may request this placement for themselves.
     *
     * The homepage hero is excluded. It is the single most valuable unit on the
     * site, shows one campaign at a time, and is what a direct sale is actually
     * selling — putting it in a self-service request queue would mean a
     * TZS 50,000 promotion request competing for the slot an agency has
     * committed to a quarter of. An administrator can still book it directly.
     *
     * Everything else is fair game: those placements show alongside organic
     * results, and a vendor promoting their own listing into them is exactly
     * the product.
     *
     * A rule in code rather than a column, for the same reason the placements
     * themselves are an enum — it decides who may buy what, and a row an
     * administrator could toggle would change commercial policy silently.
     */
    public function isVendorRequestable(): bool
    {
        return $this !== self::HomepageHero;
    }

    /** @return array<int, self> */
    public static function vendorRequestable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $placement): bool => $placement->isVendorRequestable(),
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
            'description' => $this->description(),
            'aspect_ratio' => $this->aspectRatio(),
            'max_concurrent' => $this->maxConcurrent(),
            'expects_category_targeting' => $this->expectsCategoryTargeting(),
            'vendor_requestable' => $this->isVendorRequestable(),
        ];
    }
}
