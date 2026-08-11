<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Domain\Booking\Enums\BookingStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Booking\SpecialistBookingResource;
use App\Models\SpecialistBooking;
use App\Models\User;
use App\Services\Booking\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The customer's own appointments.
 *
 * A customer may CANCEL and nothing else. Confirming, declining, completing and
 * marking a no-show are all the specialist's calls — a customer who could
 * confirm their own booking would be marking a professional's diary on their
 * behalf.
 */
class BookingController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(BookingStatus::values())],
            'upcoming' => ['sometimes', 'boolean'],
        ]);

        $bookings = SpecialistBooking::query()
            // THE authorisation boundary: another customer's rows never enter
            // the result set, so there is nothing to leak through a serialiser.
            ->where('user_id', $request->user()->getKey())
            ->with(['service', 'listing'])
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($request->boolean('upcoming'), fn ($query) => $query->upcoming())
            ->orderByDesc('starts_at_utc')
            ->paginate(20);

        return SpecialistBookingResource::collection($bookings)->response();
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $booking = $this->owned($request->user(), $uuid);

        return response()->json([
            'data' => new SpecialistBookingResource($booking->load(['service', 'listing'])),
        ]);
    }

    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $booking = $this->owned($request->user(), $uuid);

        // `cancelled_by` records WHO called it off, so a specialist looking at
        // their diary can tell a customer's change of plan from their own.
        $updated = $this->bookings->transition($booking, BookingStatus::Cancelled, 'customer');

        return response()->json([
            'data' => new SpecialistBookingResource($updated->load(['service', 'listing'])),
        ]);
    }

    /** 404 rather than 403: a 403 confirms the uuid names a real booking. */
    private function owned(User $customer, string $uuid): SpecialistBooking
    {
        return SpecialistBooking::query()
            ->where('uuid', $uuid)
            ->where('user_id', $customer->getKey())
            ->firstOr(fn () => throw ApiException::notFound('Booking not found.'));
    }
}
