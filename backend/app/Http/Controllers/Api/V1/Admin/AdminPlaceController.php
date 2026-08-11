<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Enums\Permission;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Http\Controllers\Controller;
use App\Models\PublicPlace;
use App\Models\PublicPlaceCategory;
use App\Services\Audit\AuditLogger;
use App\Support\Cache\CacheKeys;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public-place directory administration.
 *
 * The directory (hospitals, banks, schools near a listing) was seeded and
 * read-only — there was no way to add a hospital without a migration. This is
 * the write side.
 *
 * `place_count` on the category is denormalised and is recomputed here on every
 * write rather than incremented. The same reasoning as `listing_count`: a place
 * can move between categories or be deactivated, and a counter that is adjusted
 * in three places is a counter that drifts. Recomputing one category is a
 * single indexed COUNT.
 */
class AdminPlaceController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    // ------------------------------------------------------------ categories

    public function indexCategories(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $categories = PublicPlaceCategory::query()
            ->with('image')
            ->orderBy('position')
            ->get();

        return response()->json([
            'data' => $categories->map(fn (PublicPlaceCategory $c): array => [
                // The id, not just the slug: places reference their category by
                // id, so without this a client cannot build a category picker.
                'id' => $c->id,
                'slug' => $c->slug,
                'name' => $c->name,
                'icon' => $c->icon,
                'position' => $c->position,
                'is_active' => (bool) $c->is_active,
                'place_count' => (int) $c->place_count,
                'image_url' => $c->image?->url('card'),
            ])->all(),
        ]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'icon' => ['nullable', 'string', 'max:30'],
            'position' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $category = new PublicPlaceCategory;
        $category->fill($data)->forceFill([
            'slug' => $this->uniqueSlug(PublicPlaceCategory::class, $data['name']),
            'is_active' => $data['is_active'] ?? true,
            'position' => $data['position'] ?? 0,
        ])->save();

        $this->audit->record('place_category.created', $request->user(), $category, [], $data);
        CacheKeys::flushContent();

        return response()->json(['data' => $this->categoryPayload($category)], Response::HTTP_CREATED);
    }

    public function updateCategory(Request $request, string $slug): JsonResponse
    {
        $this->authorizeManage($request);

        $category = PublicPlaceCategory::where('slug', $slug)->firstOrFail();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'icon' => ['nullable', 'string', 'max:30'],
            'position' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // The slug is a public URL segment; renaming the category must not
        // break every link that points at it.
        $category->fill($data)->save();

        $this->audit->recordChange('place_category.updated', $request->user(), $category);
        CacheKeys::flushContent();

        return response()->json(['data' => $this->categoryPayload($category->fresh())]);
    }

    public function destroyCategory(Request $request, string $slug): JsonResponse
    {
        $this->authorizeManage($request);

        $category = PublicPlaceCategory::where('slug', $slug)->firstOrFail();

        if ($category->places()->exists()) {
            throw ApiException::make(
                ErrorCode::Conflict,
                'This category still holds places. Move or remove them first, or deactivate the category instead.',
            );
        }

        $this->audit->record('place_category.deleted', $request->user(), $category, [
            'slug' => $category->slug,
            'name' => $category->name,
        ]);

        $category->delete();
        CacheKeys::flushContent();

        return response()->json(['data' => ['message' => 'Category deleted.']]);
    }

    // ---------------------------------------------------------------- places

    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:191'],
            'category' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $places = PublicPlace::query()
            ->with(['category:id,name,slug,icon', 'region:id,name,slug', 'district:id,name,slug', 'image'])
            ->when(
                $validated['q'] ?? null,
                fn ($q, string $term) => $q->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%'),
            )
            ->when(
                $validated['category'] ?? null,
                fn ($q, string $slug) => $q->whereHas('category', fn ($c) => $c->where('slug', $slug)),
            )
            ->when(
                $validated['region'] ?? null,
                fn ($q, string $slug) => $q->whereHas('region', fn ($r) => $r->where('slug', $slug)),
            )
            ->when(isset($validated['active']), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('name')
            ->paginate($validated['per_page'] ?? 25)
            ->withQueryString();

        return response()->json([
            'data' => collect($places->items())->map(fn (PublicPlace $p) => $this->placePayload($p))->all(),
            'meta' => [
                'current_page' => $places->currentPage(),
                'last_page' => $places->lastPage(),
                'per_page' => $places->perPage(),
                'total' => $places->total(),
                'from' => $places->firstItem(),
                'to' => $places->lastItem(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $data = $this->validatePlace($request, creating: true);

        $place = DB::transaction(function () use ($data): PublicPlace {
            $place = new PublicPlace;
            $place->fill($data)->forceFill([
                'slug' => $this->uniqueSlug(PublicPlace::class, $data['name']),
                'is_active' => $data['is_active'] ?? true,
            ])->save();

            $this->recount($place->public_place_category_id);

            return $place;
        });

        $this->audit->record('place.created', $request->user(), $place, [], $data);
        CacheKeys::flushContent();

        return response()->json(['data' => $this->placePayload($place->fresh())], Response::HTTP_CREATED);
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        $this->authorizeManage($request);

        $place = PublicPlace::where('slug', $slug)->firstOrFail();
        $data = $this->validatePlace($request, creating: false);

        // Captured before the write: recounting only the new category would
        // leave the old one overstated.
        $previousCategory = $place->public_place_category_id;

        DB::transaction(function () use ($place, $data, $previousCategory): void {
            $place->fill($data)->save();

            $this->recount($previousCategory);

            if ($place->public_place_category_id !== $previousCategory) {
                $this->recount($place->public_place_category_id);
            }
        });

        $this->audit->recordChange('place.updated', $request->user(), $place);
        CacheKeys::flushContent();

        return response()->json(['data' => $this->placePayload($place->fresh())]);
    }

    public function destroy(Request $request, string $slug): JsonResponse
    {
        $this->authorizeManage($request);

        $place = PublicPlace::where('slug', $slug)->firstOrFail();
        $categoryId = $place->public_place_category_id;

        $this->audit->record('place.deleted', $request->user(), $place, [
            'slug' => $place->slug,
            'name' => $place->name,
        ]);

        $place->delete();
        $this->recount($categoryId);
        CacheKeys::flushContent();

        return response()->json(['data' => ['message' => 'Place deleted.']]);
    }

    // ------------------------------------------------------------- internals

    /** @return array<string, mixed> */
    private function validatePlace(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'min:2', 'max:191'],
            'public_place_category_id' => [$required, 'integer', 'exists:public_place_categories,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'ward_id' => ['nullable', 'integer', 'exists:wards,id'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', 'string', 'max:20'],
            // A place's website is rendered as a link, so the scheme is
            // constrained — `javascript:` in an href is a stored XSS vector.
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'opening_hours' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function recount(?int $categoryId): void
    {
        if ($categoryId === null) {
            return;
        }

        PublicPlaceCategory::whereKey($categoryId)->update([
            'place_count' => PublicPlace::where('public_place_category_id', $categoryId)
                ->where('is_active', true)
                ->count(),
        ]);
    }

    /** @param class-string<Model> $model */
    private function uniqueSlug(string $model, string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while ($model::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /** @return array<string, mixed> */
    private function categoryPayload(PublicPlaceCategory $category): array
    {
        return [
            'id' => $category->id,
            'slug' => $category->slug,
            'name' => $category->name,
            'icon' => $category->icon,
            'position' => $category->position,
            'is_active' => (bool) $category->is_active,
            'place_count' => (int) $category->place_count,
        ];
    }

    /** @return array<string, mixed> */
    private function placePayload(PublicPlace $place): array
    {
        return [
            'uuid' => $place->uuid,
            'slug' => $place->slug,
            'name' => $place->name,
            'description' => $place->description,
            'category' => $place->relationLoaded('category') && $place->category !== null ? [
                'id' => $place->category->id,
                'slug' => $place->category->slug,
                'name' => $place->category->name,
                'icon' => $place->category->icon,
            ] : null,
            'region' => $place->relationLoaded('region') ? $place->region?->name : null,
            'district' => $place->relationLoaded('district') ? $place->district?->name : null,
            'address_line' => $place->address_line,
            'latitude' => $place->latitude !== null ? (float) $place->latitude : null,
            'longitude' => $place->longitude !== null ? (float) $place->longitude : null,
            'phone' => $place->phone,
            'website' => $place->website,
            'opening_hours' => $place->opening_hours,
            'is_active' => (bool) $place->is_active,
            'image_url' => $place->relationLoaded('image') ? $place->image?->url('card') : null,
        ];
    }

    private function authorizeManage(Request $request): void
    {
        // Reuses `location.manage` — the public-place directory is reference
        // geography, managed by the same people who own regions and wards.
        if (! $request->user()?->hasPermission(Permission::LocationManage)) {
            throw ApiException::forbidden();
        }
    }
}
