<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Populates the Amenities tab the frontend already renders.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $icon
 * @property int|null $category_id
 * @property int $position
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Category|null $category
 * @property-read Collection<int, Listing> $listings
 * @property-read int|null $listings_count
 *
 * @method static Builder<static>|Amenity active()
 * @method static Builder<static>|Amenity newModelQuery()
 * @method static Builder<static>|Amenity newQuery()
 * @method static Builder<static>|Amenity query()
 * @method static Builder<static>|Amenity whereCategoryId($value)
 * @method static Builder<static>|Amenity whereCreatedAt($value)
 * @method static Builder<static>|Amenity whereIcon($value)
 * @method static Builder<static>|Amenity whereId($value)
 * @method static Builder<static>|Amenity whereIsActive($value)
 * @method static Builder<static>|Amenity whereName($value)
 * @method static Builder<static>|Amenity wherePosition($value)
 * @method static Builder<static>|Amenity whereSlug($value)
 * @method static Builder<static>|Amenity whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Amenity extends Model
{
    use HasFactory;

    protected $table = 'amenities';

    protected $fillable = ['name', 'slug', 'icon', 'category_id', 'position', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'position' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function listings(): BelongsToMany
    {
        return $this->belongsToMany(Listing::class, 'listing_amenity');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
