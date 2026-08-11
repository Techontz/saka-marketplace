<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PublicPlaceCategoryResource;
use App\Http\Resources\V1\PublicPlaceResource;
use App\Models\Faq;
use App\Models\Page;
use App\Models\PublicPlace;
use App\Models\PublicPlaceCategory;
use App\Models\Setting;
use App\Support\Cache\CacheableResource;
use App\Support\Cache\CacheKeys;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

/**
 * Directory + editorial content.
 *
 * Public Places is a SEPARATE entity from listings — the frontend's version
 * 404s on every card because it resolves its slugs against the listings array.
 * These endpoints are what make that section real.
 */
class ContentController extends Controller
{
    public function publicPlaceCategories(): JsonResponse
    {
        $categories = Cache::remember(
            CacheKeys::PLACE_CATEGORIES,
            now()->addHour(),
            // `image` feeds the directory tiles; without it the resource emits
            // no image_url and every tile renders empty.
            //
            // CacheableResource caches the fully-rendered ARRAY. Caching the
            // Eloquent collection instead serialises models into Redis and
            // returns __PHP_Incomplete_Class on every subsequent request — see
            // App\Support\Cache\CacheableResource.
            fn () => CacheableResource::render(PublicPlaceCategoryResource::collection(
                PublicPlaceCategory::query()->active()->with('image')->orderBy('position')->get(),
            )),
        );

        return response()->json(['data' => $categories]);
    }

    /**
     * Browse places, optionally near a point.
     *
     * The geo parameters are what make this usable from a map: panning is a
     * radius search around the new centre, and `distance_km` comes back so the
     * list beside the map can be sorted and labelled without a second call.
     */
    public function publicPlaces(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'category' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            // District, so the location picker can offer the landmarks that
            // are actually in the area a customer has narrowed to. Region
            // alone is too coarse: every landmark in Dar es Salaam is in one.
            'district' => ['nullable', 'string', 'max:120'],
            'q' => ['nullable', 'string', 'max:200'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:radius'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:radius'],
            'radius' => ['nullable', 'numeric', 'min:0.1', 'max:500', 'required_with:lat'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PublicPlace::query()
            ->active()
            ->with(['category:id,name,slug,icon', 'region:id,name,slug', 'district:id,name,slug', 'image']);

        if (isset($validated['category'])) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $validated['category']));
        }

        if (isset($validated['region'])) {
            $query->whereHas('region', fn ($q) => $q->where('slug', $validated['region']));
        }

        if (isset($validated['district'])) {
            $query->whereHas('district', fn ($q) => $q->where('slug', $validated['district']));
        }

        if (isset($validated['q'])) {
            $query->where('name', 'like', '%'.$validated['q'].'%');
        }

        $hasGeo = isset($validated['lat'], $validated['lng'], $validated['radius']);

        if ($hasGeo) {
            $this->applyRadius(
                $query,
                (float) $validated['lat'],
                (float) $validated['lng'],
                (float) $validated['radius'],
            );
        }

        // Nearest first when a point was given; alphabetical is only sensible
        // for a full directory.
        $hasGeo ? $query->orderBy('distance_km') : $query->orderBy('name');

        return PublicPlaceResource::collection(
            $query->paginate($validated['per_page'] ?? 24)->withQueryString(),
        );
    }

    /**
     * Radius search over places.
     *
     * Bounding box first so the (latitude, longitude) index can be used; only
     * the surviving rows pay for the Haversine term. Same shape as the listing
     * and business filters.
     *
     * @param  Builder<PublicPlace>  $query
     */
    private function applyRadius(Builder $query, float $lat, float $lng, float $radius): void
    {
        $latDelta = $radius / 111.045;
        $lngDelta = $radius / (111.045 * max(cos(deg2rad($lat)), 0.01));

        $query
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('longitude', [$lng - $lngDelta, $lng + $lngDelta])
            ->selectRaw(
                'public_places.*, (6371 * acos(
                    least(1.0, greatest(-1.0,
                        cos(radians(?)) * cos(radians(latitude))
                        * cos(radians(longitude) - radians(?))
                        + sin(radians(?)) * sin(radians(latitude))
                    ))
                )) as distance_km',
                [$lat, $lng, $lat],
            )
            ->havingRaw('distance_km <= ?', [$radius]);
    }

    public function publicPlace(string $slug): JsonResponse
    {
        $place = PublicPlace::query()
            ->active()
            ->where('slug', $slug)
            ->with(['category', 'region', 'district', 'ward', 'image'])
            ->first();

        if ($place === null) {
            throw ApiException::notFound('Place not found.');
        }

        return response()->json(['data' => new PublicPlaceResource($place)]);
    }

    public function faqs(): JsonResponse
    {
        $faqs = Cache::remember(
            CacheKeys::FAQS,
            now()->addHour(),
            fn () => Faq::query()->active()->orderBy('position')->get()
                ->map(fn (Faq $f) => [
                    'question' => $f->question,
                    'answer' => $f->answer,
                    'group' => $f->group,
                ])->all(),
        );

        return response()->json(['data' => $faqs]);
    }

    public function page(string $slug): JsonResponse
    {
        $page = Page::query()->published()->where('slug', $slug)->first();

        // Terms and Privacy are seeded UNPUBLISHED pending real legal copy, so
        // this correctly 404s for them today rather than serving placeholders.
        if ($page === null) {
            throw ApiException::notFound('Page not found.');
        }

        return response()->json([
            'data' => [
                'slug' => $page->slug,
                'title' => $page->title,
                'body' => $page->body,
                'meta_title' => $page->meta_title,
                'meta_description' => $page->meta_description,
                'published_at' => $page->published_at?->toAtomString(),
            ],
        ]);
    }

    public function settings(): JsonResponse
    {
        $settings = Cache::remember(
            CacheKeys::PUBLIC_SETTINGS,
            now()->addHour(),
            fn () => Setting::query()->public()->get()->pluck('value', 'key')->all(),
        );

        return response()->json(['data' => $settings]);
    }
}
