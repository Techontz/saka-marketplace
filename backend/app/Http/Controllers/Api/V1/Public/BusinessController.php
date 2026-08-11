<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Identity\Enums\BusinessType;
use App\Domain\Listing\Enums\ListingStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BusinessResource;
use App\Http\Resources\V1\ListingResource;
use App\Http\Resources\V1\ReviewResource;
use App\Models\Listing;
use App\Models\Review;
use App\Models\SellerProfile;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Public business pages.
 *
 * This whole surface was missing: the API could describe a listing's seller in
 * a summary, but there was no way to open a business, see its hours, its
 * gallery, its reviews or where it is — while the Vendor Portal was already
 * linking customers to `/sellers/{slug}`.
 *
 * A business is PUBLIC only once it has a slug and is not soft-deleted. A
 * half-onboarded profile with no display name is not a page worth serving, and
 * indexing one would put an empty business into search results.
 */
class BusinessController extends Controller
{
    private const EARTH_RADIUS_KM = 6371;

    /**
     * Search, browse and "near me", in one endpoint.
     *
     * The map needs all three from a single call — panning the map is a radius
     * search, typing is a keyword search, and the two combine.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'business_type' => ['nullable', Rule::in(BusinessType::values())],
            'region' => ['nullable', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'verified' => ['nullable', 'boolean'],
            'featured' => ['nullable', 'boolean'],

            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:radius'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:radius'],
            'radius' => ['nullable', 'numeric', 'min:0.1', 'max:500', 'required_with:lat'],

            'sort' => ['nullable', Rule::in(['relevance', 'rating', 'listings', 'newest', 'distance'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->publicQuery()
            ->with(['logo', 'region:id,name,slug', 'district:id,name,slug']);

        if (isset($validated['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $validated['q']).'%';
            $query->where(fn (Builder $q) => $q
                ->where('display_name', 'like', $term)
                ->orWhere('business_name', 'like', $term)
                ->orWhere('bio', 'like', $term));
        }

        if (isset($validated['business_type'])) {
            $query->where('business_type', $validated['business_type']);
        }

        foreach (['region' => 'region', 'district' => 'district'] as $param => $relation) {
            if (isset($validated[$param])) {
                $query->whereHas($relation, fn ($q) => $q->where('slug', $validated[$param]));
            }
        }

        if ($request->boolean('verified')) {
            $query->where('is_verified', true);
        }

        // "Featured" has no column of its own on a business; the honest reading
        // is a verified business with live stock, which is what a featured slot
        // is meant to surface.
        if ($request->boolean('featured')) {
            $query->where('is_verified', true)->where('active_listings', '>', 0);
        }

        $hasGeo = isset($validated['lat'], $validated['lng'], $validated['radius']);

        if ($hasGeo) {
            $this->applyRadius($query, (float) $validated['lat'], (float) $validated['lng'], (float) $validated['radius']);
        }

        match ($validated['sort'] ?? ($hasGeo ? 'distance' : 'rating')) {
            'distance' => $hasGeo
                ? $query->orderBy('distance_km')
                : $query->orderByDesc('rating_avg'),
            'listings' => $query->orderByDesc('active_listings'),
            'newest' => $query->latest('seller_profiles.created_at'),
            default => $query
                ->orderByDesc('is_verified')
                ->orderByDesc('rating_avg')
                ->orderByDesc('active_listings'),
        };

        return BusinessResource::collection(
            $query->paginate(min((int) ($validated['per_page'] ?? 24), 100))->withQueryString(),
        );
    }

    public function show(string $slug): JsonResponse
    {
        $business = $this->publicQuery()
            ->with(['logo', 'cover', 'region:id,name,slug', 'district:id,name,slug', 'ward:id,name,slug'])
            ->where('slug', $slug)
            ->first();

        if (! $business instanceof SellerProfile) {
            throw ApiException::notFound('Business not found.');
        }

        return response()->json(['data' => (new BusinessResource($business))->detailed()]);
    }

    /** The business's own listings — its shop window. */
    public function listings(Request $request, string $slug): AnonymousResourceCollection
    {
        $business = $this->findOrFail($slug);

        $listings = Listing::query()
            ->publiclyVisible()
            ->where('user_id', $business->user_id)
            ->with(['category:id,name,slug,icon,parent_id', 'region:id,name,slug', 'district:id,name,slug', 'primaryMedia'])
            ->latest('published_at')
            ->paginate(min((int) $request->integer('per_page', 24), 100))
            ->withQueryString();

        return ListingResource::collection($listings);
    }

    /** Reviews written about this business, across all of its listings. */
    public function reviews(Request $request, string $slug): AnonymousResourceCollection
    {
        $business = $this->findOrFail($slug);

        $reviews = Review::query()
            ->where('seller_id', $business->user_id)
            ->approved()
            ->with(['reviewer:id,uuid,first_name', 'listing:id,slug,title'])
            ->latest()
            ->paginate(min((int) $request->integer('per_page', 10), 50))
            ->withQueryString();

        return ReviewResource::collection($reviews);
    }

    /**
     * Businesses a customer looking at this one would also consider.
     *
     * Same trade first, then nearest, then best rated. Deliberately NOT
     * "customers also viewed" — there is no view history on businesses yet, and
     * inventing a recommendation from nothing would just rank by id.
     */
    public function similar(Request $request, string $slug): AnonymousResourceCollection
    {
        $business = $this->findOrFail($slug);

        $query = $this->publicQuery()
            ->with(['logo', 'region:id,name,slug', 'district:id,name,slug'])
            ->whereKeyNot($business->getKey())
            ->when(
                $business->business_type !== null,
                fn (Builder $q) => $q->where('business_type', $business->business_type->value),
            );

        if ($business->latitude !== null && $business->longitude !== null) {
            // Wide radius: "similar" is a browsing aid, not a proximity search,
            // and an empty rail is worse than a slightly distant suggestion.
            $this->applyRadius($query, (float) $business->latitude, (float) $business->longitude, 100);
            $query->orderBy('distance_km');
        } else {
            $query
                ->when($business->region_id !== null, fn (Builder $q) => $q->where('region_id', $business->region_id))
                ->orderByDesc('rating_avg');
        }

        return BusinessResource::collection(
            $query->limit(min((int) $request->integer('limit', 8), 24))->get(),
        );
    }

    // ------------------------------------------------------------- internals

    /**
     * Only businesses that are actually presentable.
     *
     * A profile row is created the moment a vendor first opens the portal,
     * pre-filled with their personal name — so "has a profile" is not the same
     * as "is a business", and a directory built on the row's existence would be
     * full of people who signed up and stopped.
     *
     * Two things qualify a profile, and either is enough:
     *
     *   - onboarding is COMPLETE, so they have described themselves; or
     *   - they have a publicly visible LISTING, in which case a customer can
     *     already reach them and the page has to exist.
     *
     * The listing arm is a whereHas rather than the denormalised
     * `active_listings` counter: the counter is flushed from Redis and can lag,
     * and a business whose page 404s for a minute after publishing is worse
     * than a slightly more expensive query.
     */
    private function publicQuery(): Builder
    {
        return SellerProfile::query()->where(
            fn (Builder $q) => $q
                ->whereNotNull('onboarding_completed_at')
                ->orWhereHas('user', fn (Builder $u) => $u->whereHas(
                    'listings',
                    fn (Builder $l) => $l->where('status', ListingStatus::Published->value)
                        ->whereNotNull('published_at'),
                )),
        );
    }

    private function findOrFail(string $slug): SellerProfile
    {
        $business = $this->publicQuery()->where('slug', $slug)->first();

        if (! $business instanceof SellerProfile) {
            throw ApiException::notFound('Business not found.');
        }

        return $business;
    }

    /**
     * Radius search over businesses.
     *
     * Same shape as the listing filter: a bounding box first so the
     * (latitude, longitude) index can be used, and only surviving rows pay for
     * the Haversine term.
     */
    private function applyRadius(Builder $query, float $lat, float $lng, float $radius): void
    {
        // 1 degree of latitude is ~111km everywhere; longitude narrows with
        // latitude, so the box is widened by 1/cos(lat).
        $latDelta = $radius / 111.045;
        $lngDelta = $radius / (111.045 * max(cos(deg2rad($lat)), 0.01));

        $query
            ->whereNotNull('seller_profiles.latitude')
            ->whereNotNull('seller_profiles.longitude')
            ->whereBetween('seller_profiles.latitude', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('seller_profiles.longitude', [$lng - $lngDelta, $lng + $lngDelta])
            ->selectRaw(
                'seller_profiles.*, ('.self::EARTH_RADIUS_KM.' * acos(
                    least(1.0, greatest(-1.0,
                        cos(radians(?)) * cos(radians(seller_profiles.latitude))
                        * cos(radians(seller_profiles.longitude) - radians(?))
                        + sin(radians(?)) * sin(radians(seller_profiles.latitude))
                    ))
                )) as distance_km',
                [$lat, $lng, $lat],
            )
            ->havingRaw('distance_km <= ?', [$radius]);
    }
}
