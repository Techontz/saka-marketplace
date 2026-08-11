<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Seller;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\ServiceMode;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Booking\SpecialistBookingResource;
use App\Http\Resources\V1\Booking\SpecialistServiceResource;
use App\Models\Listing;
use App\Models\SpecialistAvailability;
use App\Models\SpecialistAvailabilityBlock;
use App\Models\SpecialistBooking;
use App\Models\SpecialistService;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Booking\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * A specialist managing their own practice.
 *
 * Services, working hours, blocked periods and the appointment diary. Every
 * route resolves the specialist's listing by uuid AND by owner, so a vendor
 * cannot touch another's diary by guessing an identifier — the ownership check
 * is a WHERE clause, not a policy applied after loading.
 */
class SpecialistController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly AuditLogger $audit,
    ) {}

    /** Reference data the portal's forms need. */
    public function options(): JsonResponse
    {
        return response()->json([
            'data' => [
                'modes' => array_map(fn (ServiceMode $mode): array => $mode->toArray(), ServiceMode::cases()),
                'statuses' => array_map(fn (BookingStatus $s): array => $s->toArray(), BookingStatus::cases()),
                'default_timezone' => BookingService::DEFAULT_TIMEZONE,
                // 0 = Sunday, matching Carbon::dayOfWeek, so the portal never
                // has to translate between two weekday conventions.
                'weekdays' => [
                    ['value' => 0, 'label' => 'Sunday'],
                    ['value' => 1, 'label' => 'Monday'],
                    ['value' => 2, 'label' => 'Tuesday'],
                    ['value' => 3, 'label' => 'Wednesday'],
                    ['value' => 4, 'label' => 'Thursday'],
                    ['value' => 5, 'label' => 'Friday'],
                    ['value' => 6, 'label' => 'Saturday'],
                ],
            ],
        ]);
    }

    // -------------------------------------------------------------- services

    public function indexServices(Request $request, string $listingUuid): JsonResponse
    {
        $listing = $this->ownedListing($request->user(), $listingUuid);

        $services = $listing->specialistServices()->withCount('bookings')->get();

        return response()->json([
            'data' => $services->map(
                fn (SpecialistService $service) => (new SpecialistServiceResource($service))->forOwner(),
            ),
            'meta' => ['timezone' => $this->bookings->timezoneFor($listing)],
        ]);
    }

    public function storeService(Request $request, string $listingUuid): JsonResponse
    {
        $listing = $this->ownedListing($request->user(), $listingUuid);
        $validated = $this->validateService($request, creating: true);

        $service = new SpecialistService;
        $service->fill($validated);
        $service->listing_id = $listing->getKey();
        $service->save();

        return response()->json(
            ['data' => (new SpecialistServiceResource($service))->forOwner()],
            Response::HTTP_CREATED,
        );
    }

    public function updateService(Request $request, string $uuid): JsonResponse
    {
        $service = $this->ownedService($request->user(), $uuid);
        $validated = $this->validateService($request, creating: false);

        $service->fill($validated)->save();

        return response()->json(['data' => (new SpecialistServiceResource($service->fresh()))->forOwner()]);
    }

    /**
     * Remove a service.
     *
     * REFUSED while appointments exist against it. Somebody has a Thursday, and
     * the foreign key is `restrictOnDelete` for exactly this reason — the honest
     * action is to deactivate, which stops new bookings without erasing the
     * ones already agreed.
     */
    public function destroyService(Request $request, string $uuid): JsonResponse
    {
        $service = $this->ownedService($request->user(), $uuid);

        $live = $service->bookings()->occupying()->exists();

        if ($live) {
            throw ApiException::make(
                ErrorCode::Conflict,
                'This service has bookings. Deactivate it instead — that stops new bookings without cancelling the ones you have.',
            );
        }

        $service->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    // ---------------------------------------------------------- availability

    public function availability(Request $request, string $listingUuid): JsonResponse
    {
        $listing = $this->ownedListing($request->user(), $listingUuid);

        return response()->json([
            'data' => [
                'timezone' => $this->bookings->timezoneFor($listing),
                'hours' => $listing->specialistAvailability()->get()->map(fn (SpecialistAvailability $window): array => [
                    'id' => $window->id,
                    'weekday' => $window->weekday,
                    'start_time' => substr($window->start_time, 0, 5),
                    'end_time' => substr($window->end_time, 0, 5),
                ]),
                'blocks' => $listing->specialistBlocks()->get()->map(fn (SpecialistAvailabilityBlock $block): array => [
                    'id' => $block->id,
                    'starts_at' => $block->starts_at->toIso8601String(),
                    'ends_at' => $block->ends_at->toIso8601String(),
                    'reason' => $block->reason,
                ]),
            ],
        ]);
    }

    /**
     * Replace the whole weekly schedule.
     *
     * A wholesale REPLACE rather than per-row edits: a week is edited as a
     * whole in the UI, and diffing individual windows client-side is how a
     * lunch break silently disappears. Sending an empty array is how a
     * specialist closes their diary, and that has to be expressible.
     */
    public function updateAvailability(Request $request, string $listingUuid): JsonResponse
    {
        $listing = $this->ownedListing($request->user(), $listingUuid);

        $validated = $request->validate([
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64', 'timezone'],
            'hours' => ['present', 'array'],
            'hours.*.weekday' => ['required', 'integer', 'between:0,6'],
            'hours.*.start_time' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'hours.*.end_time' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
        ]);

        foreach ($validated['hours'] as $index => $window) {
            if ($window['end_time'] <= $window['start_time']) {
                throw ApiException::make(
                    ErrorCode::ValidationFailed,
                    'A working window must end after it starts.',
                    ["hours.{$index}.end_time" => ['The end time must be after the start time.']],
                );
            }
        }

        DB::transaction(function () use ($listing, $validated): void {
            if (array_key_exists('timezone', $validated)) {
                $listing->forceFill(['booking_timezone' => $validated['timezone']])->save();
            }

            $listing->specialistAvailability()->delete();

            foreach ($validated['hours'] as $window) {
                SpecialistAvailability::query()->create([
                    'listing_id' => $listing->getKey(),
                    'weekday' => $window['weekday'],
                    'start_time' => $window['start_time'].':00',
                    'end_time' => $window['end_time'].':00',
                ]);
            }
        });

        return $this->availability($request, $listingUuid);
    }

    public function storeBlock(Request $request, string $listingUuid): JsonResponse
    {
        $listing = $this->ownedListing($request->user(), $listingUuid);

        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);

        SpecialistAvailabilityBlock::query()->create([
            'listing_id' => $listing->getKey(),
            'starts_at' => Carbon::parse($validated['starts_at']),
            'ends_at' => Carbon::parse($validated['ends_at']),
            'reason' => $validated['reason'] ?? null,
        ]);

        return $this->availability($request, $listingUuid);
    }

    public function destroyBlock(Request $request, string $listingUuid, int $blockId): JsonResponse
    {
        $listing = $this->ownedListing($request->user(), $listingUuid);

        // Scoped to the listing, so an id from another specialist's diary
        // simply does not match.
        $listing->specialistBlocks()->whereKey($blockId)->delete();

        return $this->availability($request, $listingUuid);
    }

    // -------------------------------------------------------------- bookings

    public function indexBookings(Request $request, string $listingUuid): JsonResponse
    {
        $listing = $this->ownedListing($request->user(), $listingUuid);

        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(BookingStatus::values())],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'upcoming' => ['sometimes', 'boolean'],
        ]);

        $bookings = SpecialistBooking::query()
            ->where('listing_id', $listing->getKey())
            ->with(['service', 'listing'])
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['from'] ?? null, fn ($query, $from) => $query->whereDate('scheduled_date', '>=', $from))
            ->when($validated['to'] ?? null, fn ($query, $to) => $query->whereDate('scheduled_date', '<=', $to))
            ->when($request->boolean('upcoming'), fn ($query) => $query->upcoming())
            ->orderBy('starts_at_utc')
            ->paginate(50);

        $bookings->getCollection()->transform(
            fn (SpecialistBooking $booking) => $booking,
        );

        return response()->json([
            'data' => $bookings->getCollection()->map(
                fn (SpecialistBooking $booking) => (new SpecialistBookingResource($booking))->forSpecialist(),
            ),
            'meta' => [
                'current_page' => $bookings->currentPage(),
                'last_page' => $bookings->lastPage(),
                'per_page' => $bookings->perPage(),
                'total' => $bookings->total(),
                'from' => $bookings->firstItem(),
                'to' => $bookings->lastItem(),
                'timezone' => $this->bookings->timezoneFor($listing),
                /*
                 * Real counts from real rows. A dashboard tile showing a number
                 * nobody can click through to is how fake statistics start.
                 */
                'counts' => [
                    'pending' => SpecialistBooking::query()
                        ->where('listing_id', $listing->getKey())
                        ->where('status', BookingStatus::Pending->value)->count(),
                    'upcoming' => SpecialistBooking::query()
                        ->where('listing_id', $listing->getKey())
                        ->where('status', BookingStatus::Confirmed->value)
                        ->upcoming()->count(),
                ],
            ],
        ]);
    }

    /**
     * Confirm, decline, complete, cancel or mark a no-show.
     *
     * The legal moves come from `BookingStatus::allowedTransitions()`, so an
     * illegal one is refused with the list of what IS possible rather than
     * silently ignored.
     */
    public function transitionBooking(Request $request, string $uuid): JsonResponse
    {
        $booking = $this->ownedBooking($request->user(), $uuid);

        $validated = $request->validate([
            'status' => ['required', Rule::in(BookingStatus::values())],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $updated = $this->bookings->transition(
            $booking,
            BookingStatus::from($validated['status']),
            'specialist',
            $validated['note'] ?? null,
        );

        $this->audit->record('booking.transitioned', $request->user(), $updated, [], [
            'status' => $updated->status->value,
        ]);

        return response()->json([
            'data' => (new SpecialistBookingResource($updated->load(['service', 'listing'])))->forSpecialist(),
        ]);
    }

    // ------------------------------------------------------------- internals

    /** @return array<string, mixed> */
    private function validateService(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'min:2', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Bounded at eight hours: anything longer is a project, not an
            // appointment, and a 40-hour "slot" would break the day grid.
            'duration_minutes' => [$required, 'integer', 'min:5', 'max:480'],
            'buffer_minutes' => ['sometimes', 'integer', 'min:0', 'max:120'],
            // Minor units, matching listings. Nullable is "price on enquiry".
            'price_amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'mode' => ['sometimes', Rule::in(ServiceMode::values())],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);
    }

    /**
     * The vendor's own specialist listing.
     *
     * Ownership is a WHERE clause rather than a check after loading, so there
     * is no window in which another vendor's row is in memory. 404, not 403 —
     * a 403 confirms the uuid names a real listing.
     */
    private function ownedListing(User $vendor, string $uuid): Listing
    {
        return Listing::query()
            ->where('uuid', $uuid)
            ->where('user_id', $vendor->getKey())
            ->firstOr(fn () => throw ApiException::notFound('That profile was not found.'));
    }

    private function ownedService(User $vendor, string $uuid): SpecialistService
    {
        return SpecialistService::query()
            ->where('uuid', $uuid)
            ->whereHas('listing', fn ($query) => $query->where('user_id', $vendor->getKey()))
            ->firstOr(fn () => throw ApiException::notFound('That service was not found.'));
    }

    private function ownedBooking(User $vendor, string $uuid): SpecialistBooking
    {
        return SpecialistBooking::query()
            ->where('uuid', $uuid)
            ->whereHas('listing', fn ($query) => $query->where('user_id', $vendor->getKey()))
            ->firstOr(fn () => throw ApiException::notFound('Booking not found.'));
    }
}
