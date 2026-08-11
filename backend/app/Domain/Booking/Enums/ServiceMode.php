<?php

declare(strict_types=1);

namespace App\Domain\Booking\Enums;

/**
 * Where a service is delivered.
 *
 * Matters to a customer before anything else: a Dar es Salaam tutor is no use
 * to someone in Mwanza unless they teach online, and a structural survey cannot
 * be done over a video call. It is therefore a filter on the specialist
 * directory, not merely a label on the service.
 */
enum ServiceMode: string
{
    case Online = 'online';
    case InPerson = 'in_person';
    case Both = 'both';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::InPerson => 'In person',
            self::Both => 'Online or in person',
        };
    }

    /**
     * Whether this mode satisfies a customer asking for `$wanted`.
     *
     * `Both` satisfies either, which is why the filter cannot be a plain
     * equality check — a service offered both ways would otherwise be invisible
     * to somebody who filtered for online.
     */
    public function satisfies(self $wanted): bool
    {
        return $this === self::Both || $this === $wanted;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['value' => $this->value, 'label' => $this->label()];
    }
}
