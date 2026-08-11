<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Listing\Enums\ListingStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Booking\SpecialistBookingResource;
use App\Models\SpecialistService;
use App\Services\Booking\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Making a booking.
 *
 * OPEN TO GUESTS, deliberately. Requiring an account before somebody can ask a
 * lawyer for an hour is how a marketplace loses the enquiry — the contact
 * fields are what make an anonymous booking actionable, and they are captured
 * for signed-in customers too so a later account change cannot orphan an
 * appointment.
 *
 * Everything that decides whether the slot is real happens in BookingService,
 * inside a transaction and behind a row lock. Nothing on this surface trusts a
 * time the client says is free.
 */
class BookingController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_uuid' => ['required', 'string'],
            'date' => ['required', 'date'],
            // Validated in full by the service against real availability; this
            // only rejects the obviously malformed.
            'start_time' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],

            'customer_name' => ['required', 'string', 'min:2', 'max:120'],
            // A phone number is REQUIRED and email is not: this is Tanzania,
            // where a specialist rings the customer back and a great many
            // people have no email address they read.
            'customer_phone' => ['required', 'string', 'min:7', 'max:30'],
            'customer_email' => ['sometimes', 'nullable', 'email:rfc', 'max:191'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $service = SpecialistService::query()
            ->with('listing')
            ->where('uuid', $validated['service_uuid'])
            ->firstOr(fn () => throw ApiException::notFound('That service was not found.'));

        $listing = $service->listing;

        // An unpublished or archived specialist must not be bookable, even by
        // somebody holding a service uuid from before it was taken down.
        if (
            $listing === null
            || $listing->status !== ListingStatus::Published
            || $listing->published_at === null
        ) {
            throw ApiException::notFound('That specialist is not currently taking bookings.');
        }

        $booking = $this->bookings->book(
            $service,
            Carbon::parse($validated['date']),
            $validated['start_time'],
            [
                'name' => $validated['customer_name'],
                'email' => $validated['customer_email'] ?? null,
                'phone' => $validated['customer_phone'],
                'note' => $validated['note'] ?? null,
            ],
            $request->user(),
        );

        return response()->json([
            'data' => new SpecialistBookingResource($booking->load(['service', 'listing'])),
            'meta' => [
                /*
                 * "Requested", not "confirmed".
                 *
                 * A new booking is PENDING until the specialist accepts it, and
                 * the API says so rather than letting the UI guess. Telling a
                 * customer their appointment is confirmed before a human has
                 * agreed is the single most damaging thing this flow could do.
                 */
                'message' => 'Booking requested. '.($listing->title ?? 'The specialist').' will confirm shortly.',
                'requires_confirmation' => $booking->status === BookingStatus::Pending,
            ],
        ], Response::HTTP_CREATED);
    }
}
