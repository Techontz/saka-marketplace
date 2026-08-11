<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Identity\Enums\Permission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Booking\SpecialistBookingResource;
use App\Models\SpecialistBooking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Every booking on the platform, for support and oversight.
 *
 * READ-ONLY on purpose. An administrator can see an appointment to answer "the
 * customer says they booked and the lawyer says they did not", but confirming
 * or declining on a specialist's behalf would be marking somebody else's diary
 * — and the audit trail would record SAKA as having made a commitment it cannot
 * keep. Cancellation on request is done by the specialist or the customer
 * through their own surfaces.
 *
 * Gated on `inquiry.view_any`, the existing permission for reading customer
 * correspondence, rather than a new one: a booking is the same class of data
 * and the same people are trusted with it.
 */
class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeBookings($request);

        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(BookingStatus::values())],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'region' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $bookings = SpecialistBooking::query()
            ->with(['service', 'listing.region', 'customer'])
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['from'] ?? null, fn ($query, $from) => $query->whereDate('scheduled_date', '>=', $from))
            ->when($validated['to'] ?? null, fn ($query, $to) => $query->whereDate('scheduled_date', '<=', $to))
            ->when(
                $validated['q'] ?? null,
                fn ($query, $term) => $query->where(function ($inner) use ($term): void {
                    $inner->where('customer_name', 'like', "%{$term}%")
                        ->orWhereHas('listing', fn ($l) => $l->where('title', 'like', "%{$term}%"));
                }),
            )
            ->when(
                $validated['region'] ?? null,
                fn ($query, $slug) => $query->whereHas(
                    'listing.region',
                    fn ($r) => $r->where('slug', $slug),
                ),
            )
            ->orderByDesc('starts_at_utc')
            ->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'data' => $bookings->getCollection()->map(
                // Support needs the customer's number to be able to help at all.
                fn (SpecialistBooking $booking) => (new SpecialistBookingResource($booking))->forSpecialist(),
            ),
            'meta' => [
                'current_page' => $bookings->currentPage(),
                'last_page' => $bookings->lastPage(),
                'per_page' => $bookings->perPage(),
                'total' => $bookings->total(),
                'from' => $bookings->firstItem(),
                'to' => $bookings->lastItem(),
            ],
        ]);
    }

    /**
     * Counts per status.
     *
     * Straight from the table with a GROUP BY — no estimates, no cached
     * approximations. A status with no bookings is reported as 0 rather than
     * omitted, so the UI renders a stable set of tiles instead of a row that
     * changes shape as the platform fills up.
     */
    public function stats(Request $request): JsonResponse
    {
        $this->authorizeBookings($request);

        $counts = SpecialistBooking::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $byStatus = [];

        foreach (BookingStatus::cases() as $status) {
            $byStatus[] = [
                'status' => $status->value,
                'label' => $status->label(),
                'total' => (int) ($counts[$status->value] ?? 0),
            ];
        }

        return response()->json([
            'data' => [
                'by_status' => $byStatus,
                'total' => (int) $counts->sum(),
                'upcoming' => SpecialistBooking::query()
                    ->where('status', BookingStatus::Confirmed->value)
                    ->upcoming()
                    ->count(),
            ],
        ]);
    }

    private function authorizeBookings(Request $request): void
    {
        if (! $request->user()?->hasPermission(Permission::InquiryViewAny)) {
            throw ApiException::forbidden();
        }
    }
}
