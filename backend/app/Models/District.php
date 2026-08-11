<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $region_id
 * @property string $name
 * @property string $slug
 * @property numeric|null $latitude
 * @property numeric|null $longitude
 * @property int $listing_count
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Listing> $listings
 * @property-read int|null $listings_count
 * @property-read Region $region
 * @property-read Collection<int, Ward> $wards
 * @property-read int|null $wards_count
 *
 * @method static Builder<static>|District active()
 * @method static Builder<static>|District newModelQuery()
 * @method static Builder<static>|District newQuery()
 * @method static Builder<static>|District query()
 * @method static Builder<static>|District whereCreatedAt($value)
 * @method static Builder<static>|District whereId($value)
 * @method static Builder<static>|District whereIsActive($value)
 * @method static Builder<static>|District whereLatitude($value)
 * @method static Builder<static>|District whereListingCount($value)
 * @method static Builder<static>|District whereLongitude($value)
 * @method static Builder<static>|District whereName($value)
 * @method static Builder<static>|District whereRegionId($value)
 * @method static Builder<static>|District whereSlug($value)
 * @method static Builder<static>|District whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class District extends Model
{
    use HasFactory;

    protected $fillable = ['region_id', 'name', 'slug', 'latitude', 'longitude', 'is_active'];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_active' => 'boolean',
            'listing_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /** @return HasMany<Ward, $this> */
    public function wards(): HasMany
    {
        return $this->hasMany(Ward::class)->orderBy('name');
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
