<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Enums\Permission;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\StoreAttributeRequest;
use App\Http\Requests\V1\Admin\StoreCategoryRequest;
use App\Http\Requests\V1\Admin\StoreTaxonomyTermRequest;
use App\Http\Resources\V1\AttributeResource;
use App\Http\Resources\V1\CategoryResource;
use App\Models\Amenity;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\Facility;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Category, attribute, amenity and facility administration.
 *
 * Cache invalidation is handled by CacheInvalidationObserver, so nothing here
 * needs to remember to flush — that coupling is exactly what drifts.
 */
class TaxonomyController extends Controller
{
    // ------------------------------------------------------------ categories

    public function storeCategory(StoreCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();

        $category = DB::transaction(function () use ($data): Category {
            $parent = isset($data['parent_id']) ? Category::findOrFail($data['parent_id']) : null;

            $category = Category::create([
                'parent_id' => $parent?->id,
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['slug'] ?? $data['name']),
                'icon' => $data['icon'] ?? null,
                'description' => $data['description'] ?? null,
                'position' => $data['position'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
                'depth' => $parent !== null ? $parent->depth + 1 : 0,
                'is_leaf' => true,
            ]);

            // Materialised path is only knowable after the id exists.
            $category->forceFill([
                'path' => $parent !== null ? $parent->path.'/'.$category->id : (string) $category->id,
            ])->save();

            // A parent that gains a child stops being a leaf, so it can no
            // longer hold listings directly.
            $parent?->forceFill(['is_leaf' => false])->save();

            return $category;
        });

        return response()->json(['data' => new CategoryResource($category)], Response::HTTP_CREATED);
    }

    public function updateCategory(StoreCategoryRequest $request, Category $category): JsonResponse
    {
        $data = $request->validated();
        unset($data['parent_id']); // reparenting would invalidate every path below

        $category->fill($data)->save();

        return response()->json(['data' => new CategoryResource($category->fresh())]);
    }

    public function destroyCategory(Request $request, Category $category): JsonResponse
    {
        $this->authorizePermission($request, Permission::CategoryManage);

        // RESTRICT on the FK would throw a raw SQL error; these produce a
        // useful message instead.
        if ($category->children()->exists()) {
            throw ApiException::make(ErrorCode::Conflict, 'Remove or move the subcategories first.');
        }

        if (Listing::where('category_id', $category->id)->exists()) {
            throw ApiException::make(
                ErrorCode::Conflict,
                'This category still has listings. Deactivate it instead of deleting it.',
            );
        }

        $parent = $category->parent;
        $category->delete();

        // A parent with no children left becomes a leaf again.
        if ($parent !== null && ! $parent->children()->exists()) {
            $parent->forceFill(['is_leaf' => true])->save();
        }

        return response()->json(['data' => ['message' => 'Category deleted.']]);
    }

    // ------------------------------------------------------------ attributes

    public function indexAttributes(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permission::AttributeManage);

        return response()->json([
            'data' => AttributeResource::collection(
                Attribute::with('options')->orderBy('position')->get(),
            ),
        ]);
    }

    public function storeAttribute(StoreAttributeRequest $request): JsonResponse
    {
        $data = $request->validated();

        $attribute = DB::transaction(function () use ($data): Attribute {
            // `options` is a nested payload, not a column.
            $attribute = Attribute::create(Arr::except($data, ['options']));
            $this->syncOptions($attribute, $data['options'] ?? []);

            return $attribute;
        });

        return response()->json(
            ['data' => new AttributeResource($attribute->load('options'))],
            Response::HTTP_CREATED,
        );
    }

    public function updateAttribute(StoreAttributeRequest $request, Attribute $attribute): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($attribute, $data): void {
            $attribute->fill(Arr::except($data, ['options']))->save();

            if (array_key_exists('options', $data)) {
                $this->syncOptions($attribute, $data['options']);
            }
        });

        return response()->json(['data' => new AttributeResource($attribute->fresh()->load('options'))]);
    }

    public function destroyAttribute(Request $request, Attribute $attribute): JsonResponse
    {
        $this->authorizePermission($request, Permission::AttributeManage);

        if ($attribute->values()->exists()) {
            throw ApiException::make(
                ErrorCode::Conflict,
                'Listings still hold values for this attribute.',
            );
        }

        $attribute->delete();

        return response()->json(['data' => ['message' => 'Attribute deleted.']]);
    }

    /** Bind an attribute to a category, with its required/position metadata. */
    public function attachAttribute(Request $request, Category $category): JsonResponse
    {
        $this->authorizePermission($request, Permission::AttributeManage);

        $validated = $request->validate([
            'attributes' => ['required', 'array'],
            'attributes.*.code' => ['required', 'string', 'exists:attributes,code'],
            'attributes.*.is_required' => ['nullable', 'boolean'],
            'attributes.*.position' => ['nullable', 'integer', 'min:0'],
        ]);

        $sync = [];

        foreach ($validated['attributes'] as $index => $entry) {
            $attribute = Attribute::where('code', $entry['code'])->firstOrFail();
            $sync[$attribute->id] = [
                'is_required' => $entry['is_required'] ?? false,
                'is_filterable' => $attribute->is_filterable,
                'position' => $entry['position'] ?? ($index * 10),
            ];
        }

        $category->attributes()->sync($sync);

        return response()->json([
            'data' => AttributeResource::collection($category->fresh()->attributes()->with('options')->get()),
        ]);
    }

    // ------------------------------------------------ amenities / facilities

    public function storeTerm(StoreTaxonomyTermRequest $request, string $type): JsonResponse
    {
        $model = $this->termModel($type);
        $data = $request->validated();
        $data['slug'] = $this->uniqueTermSlug($model, $data['slug'] ?? $data['name']);

        $term = new $model;
        $term->fill($data)->save();

        return response()->json(['data' => $this->termPayload($term)], Response::HTTP_CREATED);
    }

    public function updateTerm(StoreTaxonomyTermRequest $request, string $type, string $slug): JsonResponse
    {
        $model = $this->termModel($type);
        $term = $model::where('slug', $slug)->firstOrFail();

        $data = $request->validated();
        unset($data['slug']); // the slug is a public filter value

        $term->fill($data)->save();

        return response()->json(['data' => $this->termPayload($term->fresh())]);
    }

    public function destroyTerm(Request $request, string $type, string $slug): JsonResponse
    {
        $this->authorizePermission($request, Permission::AmenityManage);

        $model = $this->termModel($type);
        $term = $model::where('slug', $slug)->firstOrFail();

        if ($term->listings()->exists()) {
            throw ApiException::make(
                ErrorCode::Conflict,
                'Listings still reference this term. Deactivate it instead.',
            );
        }

        $term->delete();

        return response()->json(['data' => ['message' => ucfirst($type).' deleted.']]);
    }

    // ------------------------------------------------------------- internals

    /** @param array<int, array<string, mixed>> $options */
    private function syncOptions(Attribute $attribute, array $options): void
    {
        $keep = [];

        foreach (array_values($options) as $index => $option) {
            $value = Str::slug((string) ($option['value'] ?? $option['label']));

            $row = AttributeOption::updateOrCreate(
                ['attribute_id' => $attribute->id, 'value' => $value],
                ['label' => $option['label'], 'position' => $option['position'] ?? $index * 10],
            );

            $keep[] = $row->id;
        }

        // Options in use by a listing are kept: deleting one would orphan an
        // EAV row and silently change what that listing says.
        $attribute->options()
            ->whereNotIn('id', $keep)
            ->whereDoesntHave('attribute.values', fn ($q) => $q->whereColumn('attribute_option_id', 'attribute_options.id'))
            ->delete();
    }

    /** @return class-string<Amenity|Facility> */
    private function termModel(string $type): string
    {
        return match ($type) {
            'amenities' => Amenity::class,
            'facilities' => Facility::class,
            default => throw ApiException::notFound(),
        };
    }

    /** @return array<string, mixed> */
    private function termPayload(Amenity|Facility $term): array
    {
        return [
            'slug' => $term->slug,
            'name' => $term->name,
            'icon' => $term->icon,
            'position' => $term->position,
            'is_active' => (bool) $term->is_active,
        ];
    }

    private function uniqueSlug(string $base): string
    {
        $slug = Str::slug($base);
        $candidate = $slug;
        $suffix = 2;

        while (Category::where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$suffix++;
        }

        return $candidate;
    }

    /** @param class-string $model */
    private function uniqueTermSlug(string $model, string $base): string
    {
        $slug = Str::slug($base);
        $candidate = $slug;
        $suffix = 2;

        while ($model::where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$suffix++;
        }

        return $candidate;
    }

    private function authorizePermission(Request $request, Permission $permission): void
    {
        if (! $request->user()?->hasPermission($permission)) {
            throw ApiException::forbidden();
        }
    }
}
