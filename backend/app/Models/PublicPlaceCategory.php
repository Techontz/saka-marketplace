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
 * @property string $name
 * @property string $slug
 * @property string|null $icon
 * @property int|null $image_media_id
 * @property int $place_count
 * @property int $position
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Media|null $image
 * @property-read Collection<int, PublicPlace> $places
 * @property-read int|null $places_count
 *
 * @method static Builder<static>|PublicPlaceCategory active()
 * @method static Builder<static>|PublicPlaceCategory newModelQuery()
 * @method static Builder<static>|PublicPlaceCategory newQuery()
 * @method static Builder<static>|PublicPlaceCategory query()
 * @method static Builder<static>|PublicPlaceCategory whereCreatedAt($value)
 * @method static Builder<static>|PublicPlaceCategory whereIcon($value)
 * @method static Builder<static>|PublicPlaceCategory whereId($value)
 * @method static Builder<static>|PublicPlaceCategory whereImageMediaId($value)
 * @method static Builder<static>|PublicPlaceCategory whereIsActive($value)
 * @method static Builder<static>|PublicPlaceCategory whereName($value)
 * @method static Builder<static>|PublicPlaceCategory wherePlaceCount($value)
 * @method static Builder<static>|PublicPlaceCategory wherePosition($value)
 * @method static Builder<static>|PublicPlaceCategory whereSlug($value)
 * @method static Builder<static>|PublicPlaceCategory whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class PublicPlaceCategory extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'icon', 'image_media_id', 'position', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'position' => 'integer', 'place_count' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function places(): HasMany
    {
        return $this->hasMany(PublicPlace::class);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_media_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
