<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A directory entry — a SEPARATE entity from a listing.
 *
 * The frontend's Public Places section 404s today because it resolves its slugs
 * against the listings array. This model is what makes that section real.
 *
 * @property int $id
 * @property string $uuid
 * @property int $public_place_category_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int|null $image_media_id
 * @property int|null $region_id
 * @property int|null $district_id
 * @property int|null $ward_id
 * @property string|null $address_line
 * @property numeric|null $latitude
 * @property numeric|null $longitude
 * @property string|null $phone
 * @property string|null $website
 * @property array<array-key, mixed>|null $opening_hours
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PublicPlaceCategory $category
 * @property-read District|null $district
 * @property-read Media|null $image
 * @property-read Region|null $region
 * @property-read Ward|null $ward
 *
 * @method static Builder<static>|PublicPlace active()
 * @method static Builder<static>|PublicPlace newModelQuery()
 * @method static Builder<static>|PublicPlace newQuery()
 * @method static Builder<static>|PublicPlace query()
 * @method static Builder<static>|PublicPlace whereAddressLine($value)
 * @method static Builder<static>|PublicPlace whereCreatedAt($value)
 * @method static Builder<static>|PublicPlace whereDescription($value)
 * @method static Builder<static>|PublicPlace whereDistrictId($value)
 * @method static Builder<static>|PublicPlace whereId($value)
 * @method static Builder<static>|PublicPlace whereImageMediaId($value)
 * @method static Builder<static>|PublicPlace whereIsActive($value)
 * @method static Builder<static>|PublicPlace whereLatitude($value)
 * @method static Builder<static>|PublicPlace whereLongitude($value)
 * @method static Builder<static>|PublicPlace whereName($value)
 * @method static Builder<static>|PublicPlace whereOpeningHours($value)
 * @method static Builder<static>|PublicPlace wherePhone($value)
 * @method static Builder<static>|PublicPlace wherePublicPlaceCategoryId($value)
 * @method static Builder<static>|PublicPlace whereRegionId($value)
 * @method static Builder<static>|PublicPlace whereSlug($value)
 * @method static Builder<static>|PublicPlace whereUpdatedAt($value)
 * @method static Builder<static>|PublicPlace whereUuid($value)
 * @method static Builder<static>|PublicPlace whereWardId($value)
 * @method static Builder<static>|PublicPlace whereWebsite($value)
 *
 * @mixin \Eloquent
 */
class PublicPlace extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'public_place_category_id', 'name', 'slug', 'description', 'image_media_id',
        'region_id', 'district_id', 'ward_id', 'address_line',
        'latitude', 'longitude', 'phone', 'website', 'opening_hours', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'opening_hours' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PublicPlaceCategory::class, 'public_place_category_id');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_media_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
