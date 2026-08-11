<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string|null $icon
 * @property string|null $description
 * @property int|null $image_media_id
 * @property string $path
 * @property int $depth
 * @property int $position
 * @property int $listing_count
 * @property bool $is_active
 * @property bool $is_leaf
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Amenity> $amenities
 * @property-read int|null $amenities_count
 * @property-read Collection<int, Attribute> $attributes
 * @property-read int|null $attributes_count
 * @property-read Collection<int, Category> $children
 * @property-read int|null $children_count
 * @property-read Media|null $image
 * @property-read Collection<int, Listing> $listings
 * @property-read int|null $listings_count
 * @property-read Category|null $parent
 *
 * @method static Builder<static>|Category active()
 * @method static Builder<static>|Category inSubtreeOf(Category $category)
 * @method static Builder<static>|Category leaves()
 * @method static Builder<static>|Category newModelQuery()
 * @method static Builder<static>|Category newQuery()
 * @method static Builder<static>|Category query()
 * @method static Builder<static>|Category roots()
 * @method static Builder<static>|Category whereCreatedAt($value)
 * @method static Builder<static>|Category whereDepth($value)
 * @method static Builder<static>|Category whereDescription($value)
 * @method static Builder<static>|Category whereIcon($value)
 * @method static Builder<static>|Category whereId($value)
 * @method static Builder<static>|Category whereImageMediaId($value)
 * @method static Builder<static>|Category whereIsActive($value)
 * @method static Builder<static>|Category whereIsLeaf($value)
 * @method static Builder<static>|Category whereListingCount($value)
 * @method static Builder<static>|Category whereMetaDescription($value)
 * @method static Builder<static>|Category whereMetaTitle($value)
 * @method static Builder<static>|Category whereName($value)
 * @method static Builder<static>|Category whereParentId($value)
 * @method static Builder<static>|Category wherePath($value)
 * @method static Builder<static>|Category wherePosition($value)
 * @method static Builder<static>|Category whereSlug($value)
 * @method static Builder<static>|Category whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_id', 'name', 'slug', 'icon', 'description', 'image_media_id',
        'path', 'depth', 'position', 'is_active', 'is_leaf',
        'meta_title', 'meta_description',
    ];

    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'position' => 'integer',
            'listing_count' => 'integer',
            'is_active' => 'boolean',
            'is_leaf' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // ---------------------------------------------------------------- relations

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'category_attribute')
            ->withPivot(['is_required', 'is_filterable', 'position'])
            ->orderBy('category_attribute.position');
    }

    public function amenities(): HasMany
    {
        return $this->hasMany(Amenity::class);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_media_id');
    }

    // ------------------------------------------------------------------- scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function scopeLeaves(Builder $query): Builder
    {
        return $query->where('is_leaf', true);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Ancestor + self ids, read straight off the materialised path.
     * Avoids a recursive CTE on the hot browse path.
     *
     * @return array<int, int>
     */
    public function pathIds(): array
    {
        return array_values(array_filter(array_map('intval', explode('/', $this->path))));
    }

    /**
     * Subtree filter: matches this category and everything beneath it.
     * Used by category browse so /listings?category=property includes all of
     * its subcategories.
     */
    public function scopeInSubtreeOf(Builder $query, self $category): Builder
    {
        return $query->where(function (Builder $q) use ($category): void {
            $q->where('id', $category->id)
                ->orWhere('path', 'like', $category->path.'/%');
        });
    }

    /**
     * Attributes bound to this category AND inherited from its ancestors.
     * A `beds` attribute attached to "Property" applies to "Apartments" too.
     */
    /** @return Collection<int, Attribute> */
    public function resolvedAttributes(): Collection
    {
        return Attribute::query()
            ->join('category_attribute as ca', 'ca.attribute_id', '=', 'attributes.id')
            ->whereIn('ca.category_id', $this->pathIds() ?: [$this->id])
            ->select('attributes.*', 'ca.is_required', 'ca.is_filterable', 'ca.position')
            ->orderBy('ca.position')
            ->get()
            ->unique('id')
            ->values();
    }
}
