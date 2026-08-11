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
 * Populates the Facilities tab the frontend already renders.
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
 * @method static Builder<static>|Facility active()
 * @method static Builder<static>|Facility newModelQuery()
 * @method static Builder<static>|Facility newQuery()
 * @method static Builder<static>|Facility query()
 * @method static Builder<static>|Facility whereCategoryId($value)
 * @method static Builder<static>|Facility whereCreatedAt($value)
 * @method static Builder<static>|Facility whereIcon($value)
 * @method static Builder<static>|Facility whereId($value)
 * @method static Builder<static>|Facility whereIsActive($value)
 * @method static Builder<static>|Facility whereName($value)
 * @method static Builder<static>|Facility wherePosition($value)
 * @method static Builder<static>|Facility whereSlug($value)
 * @method static Builder<static>|Facility whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Facility extends Model
{
    use HasFactory;

    protected $table = 'facilities';

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
        return $this->belongsToMany(Listing::class, 'listing_facility');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
