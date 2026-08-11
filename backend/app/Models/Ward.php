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
 * @property int $district_id
 * @property string $name
 * @property string $slug
 * @property string|null $postal_code
 * @property numeric|null $latitude
 * @property numeric|null $longitude
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read District $district
 * @property-read Collection<int, Listing> $listings
 * @property-read int|null $listings_count
 *
 * @method static Builder<static>|Ward active()
 * @method static Builder<static>|Ward newModelQuery()
 * @method static Builder<static>|Ward newQuery()
 * @method static Builder<static>|Ward query()
 * @method static Builder<static>|Ward whereCreatedAt($value)
 * @method static Builder<static>|Ward whereDistrictId($value)
 * @method static Builder<static>|Ward whereId($value)
 * @method static Builder<static>|Ward whereIsActive($value)
 * @method static Builder<static>|Ward whereLatitude($value)
 * @method static Builder<static>|Ward whereLongitude($value)
 * @method static Builder<static>|Ward whereName($value)
 * @method static Builder<static>|Ward wherePostalCode($value)
 * @method static Builder<static>|Ward whereSlug($value)
 * @method static Builder<static>|Ward whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Ward extends Model
{
    use HasFactory;

    protected $fillable = ['district_id', 'name', 'slug', 'postal_code', 'latitude', 'longitude', 'is_active'];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
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
