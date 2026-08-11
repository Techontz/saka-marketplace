<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AttributeResource;
use App\Http\Resources\V1\CategoryResource;
use App\Http\Resources\V1\LocationResource;
use App\Models\Amenity;
use App\Models\Category;
use App\Models\District;
use App\Models\Facility;
use App\Models\Region;
use App\Services\Catalog\CategoryListingCounts;
use App\Support\Cache\CacheableResource;
use App\Support\Cache\CacheKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Taxonomy and reference data.
 *
 * All of it is seeded, slow-changing and read on nearly every page, so it is
 * cached aggressively and invalidated by admin writes rather than by TTL alone.
 *
 * WHAT IS CACHED HERE IS THE RENDERED ARRAY, NOT ELOQUENT MODELS.
 *
 * That is not a style preference. Caching a model or an Eloquent collection
 * serialises the object graph into Redis, and reading it back in a different
 * process can produce `__PHP_Incomplete_Class` — which surfaces as a 500 on
 * every request after the first, with a message about unserialize() that says
 * nothing about the cause. Every endpoint on this controller did exactly that.
 *
 * It was invisible for two reasons worth remembering: the first (cold) request
 * always succeeds because the closure's return value is used directly, and the
 * test suite runs on the `array` cache store, which never serialises anything.
 *
 * `CacheableResource::render()` renders the resource — at every nesting depth —
 * to plain arrays, which round-trip through any cache driver safely. See that
 * class for why `->resolve()` alone is not enough.
 */
class CatalogController extends Controller
{
    public function __construct(private readonly CategoryListingCounts $counts) {}

    /**
     * The category tree.
     *
     * STRUCTURE and COUNTS are cached at completely different freshnesses, and
     * that split is the whole point of this method.
     *
     * The structure — which categories exist, their names, icons and artwork —
     * changes when an administrator edits the taxonomy, which is to say almost
     * never, so it is cached for a day.
     *
     * The counts change every time a listing is published, sold or moved. They
     * used to be baked into that day-long cache, on top of a `listing_count`
     * column that only an hourly command ever wrote. The two staleness windows
     * compounded: every subcategory on the homepage read "0 Listings" while the
     * database held two hundred, and nothing short of a manual cache flush
     * would correct it. Counts are now computed live and merged in afterwards.
     */
    public function categories(): JsonResponse
    {
        // CacheKeys, not a literal: the invalidation side already goes through
        // it, and a raw string here is exactly the drift that leaves an admin
        // edit invisible for a day.
        $tree = Cache::remember(CacheKeys::CATEGORY_TREE, now()->addDay(), function () {
            $categories = Category::query()
                ->active()
                ->roots()
                // `image` feeds the category browser's artwork; without it the
                // resource emits no image_url and the client has nothing to
                // render but a blank panel.
                ->with(['image', 'children' => fn ($q) => $q->active()->with('image')])
                ->orderBy('position')
                ->get();

            return CacheableResource::render(CategoryResource::collection($categories));
        });

        return response()->json([
            'data' => $this->counts->apply($tree, $this->counts->bySlug()),
        ]);
    }

    public function category(string $slug): JsonResponse
    {
        $category = Category::query()
            ->active()
            ->where('slug', $slug)
            ->with(['children' => fn ($q) => $q->active()])
            ->firstOrFail();

        // Same treatment as the tree: this endpoint backs a category landing
        // page, where the count sits directly above the results it describes.
        // Disagreeing with them by an hour is worse here than anywhere else.
        $rendered = $this->counts->apply(
            [CacheableResource::render(new CategoryResource($category))],
            $this->counts->bySlug(),
        );

        return response()->json(['data' => $rendered[0]]);
    }

    /**
     * The attribute set for a category, including everything inherited from its
     * ancestors. This is what the frontend builds its dynamic filter UI from.
     */
    public function categoryAttributes(string $slug): JsonResponse
    {
        $category = Category::query()->active()->where('slug', $slug)->firstOrFail();

        $attributes = Cache::remember(
            CacheKeys::categoryAttributes($category->id),
            now()->addDay(),
            fn () => CacheableResource::render(AttributeResource::collection(
                $category->resolvedAttributes()->load('options'),
            )),
        );

        return response()->json(['data' => $attributes]);
    }

    public function regions(): JsonResponse
    {
        $regions = Cache::remember(
            CacheKeys::REGIONS,
            now()->addWeek(),
            fn () => CacheableResource::render(LocationResource::collection(
                Region::query()->active()->orderBy('name')->get(),
            )),
        );

        return response()->json(['data' => $regions]);
    }

    public function districts(string $region): JsonResponse
    {
        $model = Region::query()->where('slug', $region)->firstOrFail();

        $districts = Cache::remember(
            CacheKeys::districtsOfRegion($model->id),
            now()->addWeek(),
            fn () => CacheableResource::render(LocationResource::collection(
                $model->districts()->where('is_active', true)->get(),
            )),
        );

        return response()->json(['data' => $districts]);
    }

    public function wards(string $district): JsonResponse
    {
        $model = District::query()->where('slug', $district)->firstOrFail();

        $wards = Cache::remember(
            CacheKeys::wardsOfDistrict($model->id),
            now()->addWeek(),
            fn () => CacheableResource::render(LocationResource::collection(
                $model->wards()->where('is_active', true)->get(),
            )),
        );

        return response()->json(['data' => $wards]);
    }

    public function amenities(): JsonResponse
    {
        $amenities = Cache::remember(
            CacheKeys::AMENITIES,
            now()->addDay(),
            fn () => Amenity::query()->active()->orderBy('position')->get()
                ->map(fn (Amenity $a) => [
                    'slug' => $a->slug, 'name' => $a->name, 'icon' => $a->icon,
                ])->all(),
        );

        return response()->json(['data' => $amenities]);
    }

    public function facilities(): JsonResponse
    {
        $facilities = Cache::remember(
            CacheKeys::FACILITIES,
            now()->addDay(),
            fn () => Facility::query()->active()->orderBy('position')->get()
                ->map(fn (Facility $f) => [
                    'slug' => $f->slug, 'name' => $f->name, 'icon' => $f->icon,
                ])->all(),
        );

        return response()->json(['data' => $facilities]);
    }
}
