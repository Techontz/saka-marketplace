<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/**
 * Tanzanian region. Seeded reference data — never user-writable.
 *
 * @property int $id
 * @property string $country_code
 * @property string $name
 * @property string $slug
 * @property string|null $code
 * @property numeric|null $latitude
 * @property numeric|null $longitude
 * @property int $listing_count
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, District> $districts
 * @property-read int|null $districts_count
 * @property-read Collection<int, Listing> $listings
 * @property-read int|null $listings_count
 * @property-read Collection<int, Ward> $wards
 * @property-read int|null $wards_count
 *
 * @method static Builder<static>|Region active()
 * @method static Builder<static>|Region newModelQuery()
 * @method static Builder<static>|Region newQuery()
 * @method static Builder<static>|Region query()
 * @method static Builder<static>|Region whereCode($value)
 * @method static Builder<static>|Region whereCountryCode($value)
 * @method static Builder<static>|Region whereCreatedAt($value)
 * @method static Builder<static>|Region whereId($value)
 * @method static Builder<static>|Region whereIsActive($value)
 * @method static Builder<static>|Region whereLatitude($value)
 * @method static Builder<static>|Region whereListingCount($value)
 * @method static Builder<static>|Region whereLongitude($value)
 * @method static Builder<static>|Region whereName($value)
 * @method static Builder<static>|Region whereSlug($value)
 * @method static Builder<static>|Region whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Region extends Model
{
    use HasFactory;

    protected $fillable = ['country_code', 'name', 'slug', 'code', 'latitude', 'longitude', 'is_active'];

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

    /** @return HasMany<District, $this> */
    public function districts(): HasMany
    {
        return $this->hasMany(District::class)->orderBy('name');
    }

    public function wards(): HasManyThrough
    {
        return $this->hasManyThrough(Ward::class, District::class);
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
