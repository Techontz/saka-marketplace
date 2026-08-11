<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Models\Listing;
use App\Models\SpecialistAvailability;
use App\Models\SpecialistAvailabilityBlock;
use App\Models\SpecialistBooking;
use App\Models\SpecialistService;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Slot generation and booking, including the concurrency handling.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY BOOKING IS HARD, AND WHAT ACTUALLY PREVENTS A DOUBLE BOOKING
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * The naive implementation reads "is 14:00 free?" and then inserts. Two
 * customers wanting the same slot both read `free`, both insert, and the
 * specialist has two people in the room. The window between the check and the
 * write is tiny and hit constantly, because the contended slot is precisely the
 * one two people want.
 *
 * THREE layers, in order of authority:
 *
 *   1. A UNIQUE INDEX on (listing_id, slot_key), where `slot_key` is a STORED
 *      generated column that is non-null only while the booking occupies time.
 *      This is the only layer that is not a race — MySQL serialises it. It
 *      catches identical start times absolutely, and because MySQL allows
 *      unlimited NULLs in a unique index, cancelling releases the slot with no
 *      cleanup job.
 *
 *   2. A ROW LOCK on the specialist's listing (`SELECT ... FOR UPDATE`) taken
 *      before the overlap check. Overlapping-but-not-identical starts — a
 *      30-minute service beginning inside a 60-minute one — produce different
 *      `slot_key`s, so layer 1 cannot see them. Locking the listing serialises
 *      every booking attempt for that specialist, which makes the overlap query
 *      trustworthy. Contention is per-specialist, which is exactly the right
 *      granularity: two people booking different lawyers never wait.
 *
 *   3. The unique-violation CATCH below, which turns the losing side of a race
 *      into "somebody just took that time" rather than a 500. Layer 2 should
 *      make this unreachable; it is kept because a constraint that only exists
 *      to be relied upon is a constraint nobody notices has been dropped.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * TIMEZONES
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * A booking is an agreement about a WALL CLOCK. "Tuesday at 14:00" is what both
 * people wrote down, so the local date, local time and the zone they are local
 * to are the stored truth. `starts_at_utc` is derived and exists only so that
 * ordering and range queries are correct once more than one zone is in play.
 *
 * Storing UTC alone would be right until a market changed its offset, at which
 * point every historical appointment would silently move.
 */
class BookingService
{
    /** Where a specialist lives if their profile does not say. */
    public const DEFAULT_TIMEZONE = 'Africa/Dar_es_Salaam';

    /**
     * How far ahead slots may be requested.
     *
     * Bounded because the generator walks days: an unbounded range is a request
     * that can be made to do arbitrary work.
     */
    private const MAX_HORIZON_DAYS = 90;

    /**
     * The bookable start times for one service on one day.
     *
     * Derived from real availability rows, real blocks and real bookings —
     * never a fixed grid. A specialist with no working hours configured gets an
     * empty list, which the UI renders as "no availability" rather than
     * inventing 09:00–17:00 on their behalf and taking bookings they cannot
     * keep.
     *
     * @return array<int, array{start: string, end: string}>
     */
    public function slotsFor(SpecialistService $service, Carbon $date, ?string $timezone = null): array
    {
        $zone = $timezone ?? $this->timezoneFor($service->listing);
        $day = $date->copy()->setTimezone($zone)->startOfDay();

        // The past is not bookable. Compared in the specialist's own zone,
        // because "today" is a local question.
        if ($day->lt(Carbon::now($zone)->startOfDay())) {
            return [];
        }

        $windows = SpecialistAvailability::query()
            ->where('listing_id', $service->listing_id)
            ->where('weekday', $day->dayOfWeek)
            ->orderBy('start_time')
            ->get();

        if ($windows->isEmpty()) {
            return [];
        }

        $blocks = SpecialistAvailabilityBlock::query()
            ->where('listing_id', $service->listing_id)
            ->where('ends_at', '>', $day->copy()->utc())
            ->where('starts_at', '<', $day->copy()->addDay()->utc())
            ->get();

        $taken = SpecialistBooking::query()
            ->where('listing_id', $service->listing_id)
            ->occupying()
            ->whereDate('scheduled_date', $day->toDateString())
            ->get(['start_time', 'end_time']);

        $blocked = $service->blockedMinutes();
        $slots = [];

        foreach ($windows as $window) {
            $cursor = $this->at($day, $window->start_time, $zone);
            $windowEnd = $this->at($day, $window->end_time, $zone);

            /*
             * Step by the service's OWN length, so a 30-minute service offers
             * twice the slots of a 60-minute one in the same window. A fixed
             * 30-minute grid would either waste half a long window or offer
             * start times a short service cannot use.
             */
            while ($cursor->copy()->addMinutes($blocked)->lte($windowEnd)) {
                $slotStart = $cursor->copy();
                // The CUSTOMER's appointment ends here; the buffer after it is
                // reserved but is not part of what they were sold.
                $slotEnd = $cursor->copy()->addMinutes($service->duration_minutes);
                $reservedUntil = $cursor->copy()->addMinutes($blocked);

                if (
                    $slotStart->gt(Carbon::now($zone))
                    && ! $this->isBlocked($slotStart, $reservedUntil, $blocks)
                    && ! $this->clashesWithBooking($slotStart, $reservedUntil, $taken, $day, $zone)
                ) {
                    $slots[] = [
                        'start' => $slotStart->format('H:i'),
                        'end' => $slotEnd->format('H:i'),
                    ];
                }

                $cursor->addMinutes($blocked);
            }
        }

        return $slots;
    }

    /**
     * Availability across a range of days, for the booking calendar.
     *
     * @return array<int, array{date: string, slots: array<int, array{start: string, end: string}>}>
     */
    public function calendar(SpecialistService $service, Carbon $from, int $days = 14): array
    {
        $days = max(1, min($days, self::MAX_HORIZON_DAYS));
        $zone = $this->timezoneFor($service->listing);
        $cursor = $from->copy()->setTimezone($zone)->startOfDay();

        $calendar = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $day = $cursor->copy()->addDays($offset);

            $calendar[] = [
                'date' => $day->toDateString(),
                'slots' => $this->slotsFor($service, $day, $zone),
            ];
        }

        return $calendar;
    }

    /**
     * Create a booking, or fail because the slot went.
     *
     * @param  array{name: string, email?: string|null, phone: string, note?: string|null}  $customer
     */
    public function book(
        SpecialistService $service,
        Carbon $date,
        string $startTime,
        array $customer,
        ?User $user = null,
    ): SpecialistBooking {
        if (! $service->is_active) {
            throw ApiException::make(ErrorCode::ValidationFailed, 'That service is not currently bookable.');
        }

        $zone = $this->timezoneFor($service->listing);
        $day = $date->copy()->setTimezone($zone)->startOfDay();

        $start = $this->at($day, $startTime, $zone);
        $end = $start->copy()->addMinutes($service->duration_minutes);
        $reservedUntil = $start->copy()->addMinutes($service->blockedMinutes());

        if ($start->lte(Carbon::now($zone))) {
            throw ApiException::make(ErrorCode::ValidationFailed, 'That time has already passed.');
        }

        return DB::transaction(function () use (
            $service, $day, $start, $end, $reservedUntil, $customer, $user, $zone
        ): SpecialistBooking {
            /*
             * THE LOCK. Everything after this is serialised per specialist.
             *
             * Taken on the listing rather than on any booking row because the
             * thing being protected is the ABSENCE of a booking, and you cannot
             * lock a row that does not exist. `lockForUpdate` on the parent is
             * the standard answer to that.
             */
            Listing::query()->whereKey($service->listing_id)->lockForUpdate()->first();

            // Re-checked INSIDE the lock. Checking before it would be the same
            // race in a different place.
            $this->assertSlotIsOfferedAndFree($service, $day, $start, $reservedUntil, $zone);

            $booking = new SpecialistBooking;
            $booking->fill([
                'listing_id' => $service->listing_id,
                'specialist_service_id' => $service->getKey(),
                'user_id' => $user?->getKey(),
                'customer_name' => $customer['name'],
                'customer_email' => $customer['email'] ?? $user?->email,
                'customer_phone' => $customer['phone'],
                'scheduled_date' => $day->toDateString(),
                'start_time' => $start->format('H:i:s'),
                'end_time' => $end->format('H:i:s'),
                'timezone' => $zone,
                'starts_at_utc' => $start->copy()->utc(),
                'ends_at_utc' => $end->copy()->utc(),
                'customer_note' => $customer['note'] ?? null,
            ]);

            $booking->forceFill(['status' => BookingStatus::Pending->value]);

            try {
                $booking->save();
            } catch (QueryException $exception) {
                /*
                 * 1062 — the unique index caught it.
                 *
                 * Should be unreachable behind the lock above. Kept because a
                 * constraint relied upon but never exercised is one nobody
                 * notices has been dropped, and because the honest message for
                 * the loser of a race is "somebody just took that time", not a
                 * 500.
                 */
                if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                    throw ApiException::make(
                        ErrorCode::Conflict,
                        'Somebody just booked that time. Please choose another.',
                    );
                }

                throw $exception;
            }

            return $booking;
        });
    }

    /**
     * Move a booking to a new status, enforcing the transition table.
     *
     * The ACTOR matters as much as the transition: a customer may cancel and
     * nothing else, and a specialist must not be able to cancel on a customer's
     * behalf and record it as the customer's decision.
     */
    public function transition(
        SpecialistBooking $booking,
        BookingStatus $target,
        string $actor,
        ?string $note = null,
    ): SpecialistBooking {
        if (! $booking->status->canTransitionTo($target)) {
            throw ApiException::make(
                ErrorCode::Conflict,
                "A {$booking->status->label()} booking cannot become {$target->label()}.",
                ['allowed' => array_map(
                    fn (BookingStatus $status): string => $status->value,
                    $booking->status->allowedTransitions(),
                )],
            );
        }

        $changes = ['status' => $target->value];

        if ($target === BookingStatus::Cancelled) {
            $changes['cancelled_at'] = Carbon::now();
            $changes['cancelled_by'] = $actor;
        } else {
            $changes['responded_at'] = Carbon::now();
        }

        if ($note !== null) {
            $changes['specialist_note'] = $note;
        }

        $booking->forceFill($changes)->save();

        /*
         * No explicit slot release. `slot_key` is generated from `status`, so
         * moving to cancelled or declined nulls it and frees the time in the
         * same write — there is no second statement that could fail and leave a
         * cancelled booking still holding its slot.
         */
        return $booking->refresh();
    }

    /**
     * Reschedule: cancel and rebook atomically.
     *
     * Implemented as a new booking rather than an UPDATE so the slot contest is
     * the same one every other booking goes through — an UPDATE would move the
     * row into a slot without re-running the availability and overlap checks.
     */
    public function reschedule(
        SpecialistBooking $booking,
        Carbon $date,
        string $startTime,
        string $actor,
    ): SpecialistBooking {
        if (! $booking->status->isCancellable()) {
            throw ApiException::make(
                ErrorCode::Conflict,
                'Only a pending or confirmed booking can be moved.',
            );
        }

        $service = $booking->service;

        if ($service === null) {
            throw ApiException::make(ErrorCode::ValidationFailed, 'That service no longer exists.');
        }

        return DB::transaction(function () use ($booking, $service, $date, $startTime, $actor): SpecialistBooking {
            /*
             * Released FIRST, inside the transaction.
             *
             * Without this, moving 14:00 to 15:00 on a day where the customer
             * already holds 14:00 would contend with their own booking. If the
             * rebook then fails, the transaction rolls the cancellation back
             * and they keep the original time.
             */
            $this->transition($booking, BookingStatus::Cancelled, $actor, 'Rescheduled.');

            return $this->book(
                $service,
                $date,
                $startTime,
                [
                    'name' => $booking->customer_name,
                    'email' => $booking->customer_email,
                    'phone' => $booking->customer_phone,
                    'note' => $booking->customer_note,
                ],
                $booking->customer,
            );
        });
    }

    // ------------------------------------------------------------- internals

    /** The specialist's zone, defaulting to Tanzania. */
    public function timezoneFor(?Listing $listing): string
    {
        $zone = $listing?->booking_timezone;

        return is_string($zone) && $zone !== '' ? $zone : self::DEFAULT_TIMEZONE;
    }

    private function at(Carbon $day, string $time, string $zone): Carbon
    {
        [$hour, $minute] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return $day->copy()->setTimezone($zone)->setTime($hour, $minute);
    }

    /**
     * The slot must be one the specialist actually offers AND still free.
     *
     * Both, not either. Checking only for a clash would let a customer post any
     * start time they liked — 03:00 on a Sunday — and get a booking, because
     * nothing else is scheduled then.
     */
    private function assertSlotIsOfferedAndFree(
        SpecialistService $service,
        Carbon $day,
        Carbon $start,
        Carbon $reservedUntil,
        string $zone,
    ): void {
        $offered = collect($this->slotsFor($service, $day, $zone))
            ->contains(fn (array $slot): bool => $slot['start'] === $start->format('H:i'));

        if (! $offered) {
            throw ApiException::make(
                ErrorCode::Conflict,
                'That time is no longer available. Please choose another.',
            );
        }

        /*
         * A second, explicit overlap check.
         *
         * `slotsFor` already excludes clashes, but it works from a snapshot
         * taken before the lock in one call path. This runs inside the lock and
         * is what actually closes the window for OVERLAPPING starts, which the
         * unique index cannot see because they produce different slot keys.
         */
        $clash = SpecialistBooking::query()
            ->where('listing_id', $service->listing_id)
            ->occupying()
            ->whereDate('scheduled_date', $day->toDateString())
            // Half-open intervals: a booking ending at exactly 15:00 does not
            // clash with one starting at 15:00.
            ->where('starts_at_utc', '<', $reservedUntil->copy()->utc())
            ->where('ends_at_utc', '>', $start->copy()->utc())
            ->exists();

        if ($clash) {
            throw ApiException::make(
                ErrorCode::Conflict,
                'Somebody just booked that time. Please choose another.',
            );
        }
    }

    /**
     * @param  Collection<int, SpecialistAvailabilityBlock>  $blocks
     */
    private function isBlocked(Carbon $start, Carbon $end, $blocks): bool
    {
        $startUtc = $start->copy()->utc();
        $endUtc = $end->copy()->utc();

        return $blocks->contains(
            fn (SpecialistAvailabilityBlock $block): bool => $block->starts_at->lt($endUtc)
                && $block->ends_at->gt($startUtc),
        );
    }

    /**
     * @param  Collection<int, SpecialistBooking>  $taken
     */
    private function clashesWithBooking(Carbon $start, Carbon $end, $taken, Carbon $day, string $zone): bool
    {
        return $taken->contains(function (SpecialistBooking $booking) use ($start, $end, $day, $zone): bool {
            $bookedStart = $this->at($day, $booking->start_time, $zone);
            $bookedEnd = $this->at($day, $booking->end_time, $zone);

            return $bookedStart->lt($end) && $bookedEnd->gt($start);
        });
    }
}
