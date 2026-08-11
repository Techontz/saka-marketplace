<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Booking\Enums\ServiceMode;
use App\Domain\Listing\Enums\ListingStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Booking\SpecialistServiceResource;
use App\Models\Category;
use App\Models\Listing;
use App\Models\SpecialistService;
use App\Services\Booking\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The public specialist surface.
 *
 * Deliberately THIN. A specialist is a listing, so browsing, searching,
 * filtering by category-specific attributes, location and radius all go through
 * `/listings?category=specialists` — the existing endpoint, with the existing
 * EAV filters, which already work and are already tested. Duplicating them here
 * would produce a second search implementation to keep in step.
 *
 * What lives here is only what a listing cannot answer: what this specialist
 * sells, and when they are free.
 */
class SpecialistController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    /** The services on a specialist's menu. */
    public function services(Request $request, string $slug): JsonResponse
    {
        $listing = $this->specialist($slug);

        $validated = $request->validate([
            'mode' => ['sometimes', 'nullable', Rule::in(ServiceMode::values())],
        ]);

        $services = $listing->specialistServices()
            ->active()
            ->get()
            ->filter(function (SpecialistService $service) use ($validated): bool {
                $wanted = $validated['mode'] ?? null;

                // `Both` satisfies either, so this cannot be an equality check
                // — a service offered both ways would be invisible to somebody
                // filtering for online.
                return $wanted === null
                    || $service->mode->satisfies(ServiceMode::from($wanted));
            })
            ->values();

        return response()->json([
            'data' => SpecialistServiceResource::collection($services),
            'meta' => [
                'timezone' => $this->bookings->timezoneFor($listing),
                'modes' => array_map(
                    fn (ServiceMode $mode): array => $mode->toArray(),
                    ServiceMode::cases(),
                ),
            ],
        ]);
    }

    /**
     * Bookable start times for one service.
     *
     * Every slot here is derived from the specialist's real working hours,
     * their real blocked periods and their real existing bookings. A specialist
     * who has configured no availability returns an EMPTY list — never a
     * default nine-to-five, which would take bookings they never agreed to.
     */
    public function slots(Request $request, string $slug, string $serviceUuid): JsonResponse
    {
        $listing = $this->specialist($slug);

        $validated = $request->validate([
            'from' => ['sometimes', 'nullable', 'date'],
            // Bounded: the generator walks days, so an unbounded range is a
            // request that can be made to do arbitrary work.
            'days' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ]);

        $service = $listing->specialistServices()
            ->active()
            ->where('uuid', $serviceUuid)
            ->firstOr(fn () => throw ApiException::notFound('That service was not found.'));

        $timezone = $this->bookings->timezoneFor($listing);

        // Defaults to TODAY in the specialist's zone, not the server's — "what
        // is available today" is a local question.
        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'], $timezone)
            : Carbon::now($timezone);

        // Cast: a query-string integer arrives as a string, and `calendar()`
        // is typed `int`.
        $calendar = $this->bookings->calendar($service, $from, (int) ($validated['days'] ?? 14));

        return response()->json([
            'data' => $calendar,
            'meta' => [
                'timezone' => $timezone,
                'service' => new SpecialistServiceResource($service),
                // So a client can say "no availability in the next fortnight"
                // rather than rendering fourteen empty days.
                'has_availability' => collect($calendar)->contains(
                    fn (array $day): bool => $day['slots'] !== [],
                ),
            ],
        ]);
    }

    /**
     * A published specialist, by slug.
     *
     * Scoped to the specialists vertical AND to published: an unpublished
     * profile must not be reachable by guessing its slug, and neither must a
     * property listing whose slug happens to be passed here.
     */
    private function specialist(string $slug): Listing
    {
        $listing = Listing::query()
            ->with('category')
            ->where('slug', $slug)
            ->where('status', ListingStatus::Published->value)
            ->whereNotNull('published_at')
            ->first();

        if ($listing === null || ! $this->isSpecialist($listing)) {
            throw ApiException::notFound('Specialist not found.');
        }

        return $listing;
    }

    private function isSpecialist(Listing $listing): bool
    {
        $category = $listing->category;

        if ($category === null) {
            return false;
        }

        // The whole lineage, so a listing in any specialist subcategory counts
        // without naming each one here.
        return Category::query()
            ->whereIn('id', $category->pathIds() ?: [$category->getKey()])
            ->where('slug', 'specialists')
            ->exists();
    }
}
