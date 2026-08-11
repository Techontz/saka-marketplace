<?php

declare(strict_types=1);

namespace App\Domain\Booking\Enums;

/**
 * The lifecycle of a specialist booking.
 *
 * `occupying()` is the important method and is load-bearing in the SCHEMA, not
 * just in code: the `slot_key` generated column that enforces one booking per
 * slot is built from this list. A status added here without thinking about
 * whether it holds time will change what the database considers a clash.
 */
enum BookingStatus: string
{
    /** Requested, awaiting the specialist. Holds the slot — see occupying(). */
    case Pending = 'pending';

    case Confirmed = 'confirmed';

    /** The specialist said no. Releases the slot. */
    case Declined = 'declined';

    /** Either side called it off. Releases the slot. */
    case Cancelled = 'cancelled';

    case Completed = 'completed';

    /** Nobody came. Keeps the slot: the time was still spent. */
    case NoShow = 'no_show';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The statuses that OCCUPY a slot.
     *
     * Pending occupies deliberately. A request that did not hold its time would
     * let five people request the same 14:00, four of whom must then be
     * declined — the specialist does the sorting out, and four customers are
     * disappointed by a system that told them yes.
     *
     * @return array<int, self>
     */
    public static function occupying(): array
    {
        return [self::Pending, self::Confirmed, self::Completed, self::NoShow];
    }

    public function occupiesSlot(): bool
    {
        return in_array($this, self::occupying(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting confirmation',
            self::Confirmed => 'Confirmed',
            self::Declined => 'Declined',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
            self::NoShow => 'No show',
        };
    }

    /** Whether the specialist still has a decision to make. */
    public function awaitsSpecialist(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Whether this booking can still be called off.
     *
     * A completed or no-show appointment is history — cancelling it would be
     * rewriting the diary rather than changing a plan.
     */
    public function isCancellable(): bool
    {
        return $this === self::Pending || $this === self::Confirmed;
    }

    /**
     * What this status may become.
     *
     * A total map rather than scattered `if`s, so an illegal move is impossible
     * to express rather than merely unlikely to be attempted.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Declined, self::Cancelled],
            // Completed and no-show are only reachable from confirmed: neither
            // is meaningful for an appointment nobody agreed to.
            self::Confirmed => [self::Completed, self::NoShow, self::Cancelled],
            self::Declined, self::Cancelled, self::Completed, self::NoShow => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
            'occupies_slot' => $this->occupiesSlot(),
            'is_cancellable' => $this->isCancellable(),
            'allowed_transitions' => array_map(
                fn (self $status): string => $status->value,
                $this->allowedTransitions(),
            ),
        ];
    }
}
