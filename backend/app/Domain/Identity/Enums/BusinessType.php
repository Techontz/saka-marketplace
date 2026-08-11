<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * What kind of business a vendor runs.
 *
 * THIS IS THE AXIS THE WHOLE MULTI-VERTICAL DESIGN TURNS ON.
 *
 * Milestone 12 requires one vendor portal serving landlords, restaurants, car
 * dealers, clinics and six other trades, with "business type determines
 * available fields". Nothing like it existed — a seller profile had a display
 * name, a bio and a verification level, and no notion of what the business
 * actually was.
 *
 * The alternative — a `type` string column and per-type branching scattered
 * through controllers and components — is how a multi-vertical product becomes
 * ten products in a trenchcoat. Instead every per-type decision is answered
 * here, by a method, and both the API and the portal ask this enum rather than
 * carrying their own copy of the rules:
 *
 *   - which catalogue categories the vendor can post into;
 *   - whether opening hours are meaningful (a landlord has none; a pharmacy's
 *     are the single most-read fact about it);
 *   - whether a walk-in address is meaningful (a plumber works at YOUR house);
 *   - what to call a listing, because "listing" is a marketplace word and
 *     nobody who runs a hotel thinks of a room as one.
 *
 * Adding a vertical is a case here plus its mappings. It is deliberately NOT a
 * database table: these drive code paths and UI copy, so a row an administrator
 * could add would be a vertical with no behaviour behind it.
 */
enum BusinessType: string
{
    case Landlord = 'landlord';
    case Shop = 'shop';
    case Restaurant = 'restaurant';
    case Hotel = 'hotel';
    case CarDealer = 'car_dealer';
    case ServiceProvider = 'service_provider';
    case Pharmacy = 'pharmacy';
    case Clinic = 'clinic';
    case School = 'school';
    case EventOrganizer = 'event_organizer';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Landlord => 'Landlord or property agent',
            self::Shop => 'Shop or retailer',
            self::Restaurant => 'Restaurant or café',
            self::Hotel => 'Hotel or guest house',
            self::CarDealer => 'Car dealer',
            self::ServiceProvider => 'Service provider',
            self::Pharmacy => 'Pharmacy',
            self::Clinic => 'Clinic or health service',
            self::School => 'School or training centre',
            self::EventOrganizer => 'Event organiser',
            self::Other => 'Something else',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Landlord => 'You rent, lease or sell property.',
            self::Shop => 'You sell goods — electronics, fashion, furniture, anything.',
            self::Restaurant => 'You serve food or drink at a venue.',
            self::Hotel => 'You offer rooms or short-stay accommodation.',
            self::CarDealer => 'You sell or hire out vehicles.',
            self::ServiceProvider => 'You do work for customers — repairs, cleaning, construction.',
            self::Pharmacy => 'You dispense medicine and health products.',
            self::Clinic => 'You provide medical or dental care.',
            self::School => 'You teach or train.',
            self::EventOrganizer => 'You run or host events.',
            self::Other => "Your business doesn't fit the list.",
        };
    }

    /**
     * The catalogue verticals this business posts into.
     *
     * Used to pre-filter the category picker rather than to restrict it: a
     * hotel that also sells furniture is a real thing, and a portal that
     * refuses would be wrong. `Other` returns an empty array, meaning "no
     * pre-filter, show everything".
     *
     * @return array<int, string> root category slugs
     */
    public function categorySlugs(): array
    {
        return match ($this) {
            self::Landlord, self::Hotel => ['property'],
            self::Shop => ['electronics', 'fashion', 'furniture', 'agriculture', 'pets'],
            self::Restaurant => ['services'],
            self::CarDealer => ['vehicles'],
            self::ServiceProvider => ['services'],
            self::Pharmacy => ['services'],
            self::Clinic, self::School => ['services'],
            self::EventOrganizer => ['services'],
            self::Other => [],
        };
    }

    /**
     * Whether opening hours are part of this vendor's profile.
     *
     * False for a landlord and a car dealer working by appointment: asking them
     * to fill in a weekly schedule produces either blank fields or invented
     * ones, and a wrong "Open until 6pm" on a public profile is worse than none.
     */
    public function hasOpeningHours(): bool
    {
        return match ($this) {
            self::Shop, self::Restaurant, self::Hotel, self::Pharmacy,
            self::Clinic, self::School => true,
            default => false,
        };
    }

    /**
     * Whether customers come to a fixed address.
     *
     * A plumber and an event organiser work at the customer's location, so a
     * street address is optional for them — but a region still matters, because
     * that is how they are found.
     */
    public function hasWalkInAddress(): bool
    {
        return match ($this) {
            self::ServiceProvider, self::EventOrganizer, self::Landlord => false,
            default => true,
        };
    }

    /**
     * What this vendor calls the things they post.
     *
     * Nobody running a hotel thinks of a room as a "listing". Getting the noun
     * right is most of what makes one portal feel like it was built for each
     * trade rather than for none of them.
     *
     * @return array{0: string, 1: string} [singular, plural]
     */
    public function listingNoun(): array
    {
        return match ($this) {
            self::Landlord => ['property', 'properties'],
            self::Hotel => ['room', 'rooms'],
            self::CarDealer => ['vehicle', 'vehicles'],
            self::ServiceProvider, self::Pharmacy, self::Clinic, self::School => ['service', 'services'],
            self::Restaurant => ['menu item', 'menu items'],
            self::EventOrganizer => ['event', 'events'],
            self::Shop => ['product', 'products'],
            self::Other => ['listing', 'listings'],
        };
    }

    /**
     * Registration and tax identifiers.
     *
     * A licensed trade is expected to have them; a sole trader letting a spare
     * room is not, and demanding a TIN from them is how you lose the listing.
     */
    public function expectsRegistrationNumber(): bool
    {
        return match ($this) {
            self::Landlord, self::Other => false,
            default => true,
        };
    }

    /**
     * The full descriptor a client needs to render this type.
     *
     * Returned by the API so the portal never has to carry a second copy of
     * these rules — the classic way per-vertical behaviour drifts between the
     * server that enforces it and the UI that displays it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        [$singular, $plural] = $this->listingNoun();

        return [
            'value' => $this->value,
            'label' => $this->label(),
            'description' => $this->description(),
            'category_slugs' => $this->categorySlugs(),
            'has_opening_hours' => $this->hasOpeningHours(),
            'has_walk_in_address' => $this->hasWalkInAddress(),
            'expects_registration_number' => $this->expectsRegistrationNumber(),
            'listing_noun' => ['singular' => $singular, 'plural' => $plural],
        ];
    }
}
