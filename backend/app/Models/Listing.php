<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Listing\Enums\ListingCondition;
use App\Domain\Listing\Enums\ListingPurpose;
use App\Domain\Listing\Enums\ListingStatus;
use App\Domain\Listing\Enums\PriceUnit;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string $slug
 * @property int $user_id
 * @property int $category_id
 * @property string $title
 * @property string|null $description
 * @property ListingPurpose|null $purpose
 * @property int|null $price
 * @property string $currency
 * @property PriceUnit|null $price_unit
 * @property bool $is_negotiable
 * @property ListingCondition|null $condition
 * @property int|null $region_id
 * @property int|null $district_id
 * @property int|null $ward_id
 * @property string|null $address_line
 * @property string|null $postal_code
 * @property numeric|null $latitude
 * @property numeric|null $longitude
 * @property ListingStatus $status
 * @property string|null $rejection_reason
 * @property bool $is_verified
 * @property bool $is_featured
 * @property Carbon|null $featured_until
 * @property int $boost_score
 * @property Carbon|null $available_from
 * @property int $view_count
 * @property int $favorite_count
 * @property int $inquiry_count
 * @property numeric $popularity_score
 * @property array<array-key, mixed>|null $search_document
 * @property Carbon|null $published_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Amenity> $amenities
 * @property-read int|null $amenities_count
 * @property-read Collection<int, ListingAttributeValue> $attributeValues
 * @property-read int|null $attribute_values_count
 * @property-read Category $category
 * @property-read District|null $district
 * @property-read Collection<int, Facility> $facilities
 * @property-read int|null $facilities_count
 * @property-read Collection<int, Favorite> $favorites
 * @property-read int|null $favorites_count
 * @property-read Collection<int, Media> $gallery
 * @property-read int|null $gallery_count
 * @property-read Collection<int, Inquiry> $inquiries
 * @property-read int|null $inquiries_count
 * @property-read Collection<int, Media> $media
 * @property-read int|null $media_count
 * @property-read Media|null $primaryMedia
 * @property-read Region|null $region
 * @property-read Collection<int, Review> $reviews
 * @property-read int|null $reviews_count
 * @property-read User|null $seller
 * @property-read Collection<int, ListingStatusHistory> $statusHistories
 * @property-read int|null $status_histories_count
 * @property-read User|null $user
 * @property-read Collection<int, ListingView> $views
 * @property-read int|null $views_count
 * @property-read Ward|null $ward
 *
 * @method static \Database\Factories\ListingFactory factory($count = null, $state = [])
 * @method static Builder<static>|Listing featured()
 * @method static Builder<static>|Listing inCategory(\App\Models\Category $category)
 * @method static Builder<static>|Listing newModelQuery()
 * @method static Builder<static>|Listing newQuery()
 * @method static Builder<static>|Listing onlyTrashed()
 * @method static Builder<static>|Listing ownedBy(\App\Models\User $user)
 * @method static Builder<static>|Listing publiclyVisible()
 * @method static Builder<static>|Listing query()
 * @method static Builder<static>|Listing whereAddressLine($value)
 * @method static Builder<static>|Listing whereAvailableFrom($value)
 * @method static Builder<static>|Listing whereBoostScore($value)
 * @method static Builder<static>|Listing whereCategoryId($value)
 * @method static Builder<static>|Listing whereCondition($value)
 * @method static Builder<static>|Listing whereCreatedAt($value)
 * @method static Builder<static>|Listing whereCurrency($value)
 * @method static Builder<static>|Listing whereDeletedAt($value)
 * @method static Builder<static>|Listing whereDescription($value)
 * @method static Builder<static>|Listing whereDistrictId($value)
 * @method static Builder<static>|Listing whereExpiresAt($value)
 * @method static Builder<static>|Listing whereFavoriteCount($value)
 * @method static Builder<static>|Listing whereFeaturedUntil($value)
 * @method static Builder<static>|Listing whereId($value)
 * @method static Builder<static>|Listing whereInquiryCount($value)
 * @method static Builder<static>|Listing whereIsFeatured($value)
 * @method static Builder<static>|Listing whereIsNegotiable($value)
 * @method static Builder<static>|Listing whereIsVerified($value)
 * @method static Builder<static>|Listing whereLatitude($value)
 * @method static Builder<static>|Listing whereLongitude($value)
 * @method static Builder<static>|Listing wherePopularityScore($value)
 * @method static Builder<static>|Listing wherePostalCode($value)
 * @method static Builder<static>|Listing wherePrice($value)
 * @method static Builder<static>|Listing wherePriceUnit($value)
 * @method static Builder<static>|Listing wherePublishedAt($value)
 * @method static Builder<static>|Listing wherePurpose($value)
 * @method static Builder<static>|Listing whereRegionId($value)
 * @method static Builder<static>|Listing whereRejectionReason($value)
 * @method static Builder<static>|Listing whereSearchDocument($value)
 * @method static Builder<static>|Listing whereSlug($value)
 * @method static Builder<static>|Listing whereStatus($value)
 * @method static Builder<static>|Listing whereTitle($value)
 * @method static Builder<static>|Listing whereUpdatedAt($value)
 * @method static Builder<static>|Listing whereUserId($value)
 * @method static Builder<static>|Listing whereUuid($value)
 * @method static Builder<static>|Listing whereViewCount($value)
 * @method static Builder<static>|Listing whereWardId($value)
 * @method static Builder<static>|Listing withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Listing withinBoundingBox(float $lat, float $lng, float $radiusKm)
 * @method static Builder<static>|Listing withoutTrashed()
 *
 * @mixin \Eloquent
 */
class Listing extends Model
{
    use HasFactory;
    use HasMedia;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'category_id', 'title', 'description', 'purpose',
        'price', 'currency', 'price_unit', 'is_negotiable', 'condition',
        'region_id', 'district_id', 'ward_id', 'address_line', 'postal_code',
        'latitude', 'longitude', 'available_from', 'booking_timezone',
    ];

    /**
     * Never mass-assignable — these are set by services, moderators or
     * scheduled jobs, never by a client payload.
     */
    protected $guarded = [
        'id', 'uuid', 'slug', 'status', 'is_verified', 'is_featured',
        'featured_until', 'boost_score', 'view_count', 'favorite_count',
        'inquiry_count', 'popularity_score', 'published_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => ListingPurpose::class,
            'price_unit' => PriceUnit::class,
            'condition' => ListingCondition::class,
            'status' => ListingStatus::class,
            'price' => 'integer',
            'is_negotiable' => 'boolean',
            'is_verified' => 'boolean',
            'is_featured' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'view_count' => 'integer',
            'favorite_count' => 'integer',
            'inquiry_count' => 'integer',
            'popularity_score' => 'decimal:4',
            'search_document' => 'array',
            'available_from' => 'date',
            'featured_until' => 'datetime',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // ---------------------------------------------------------------- relations

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
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

    public function attributeValues(): HasMany
    {
        return $this->hasMany(ListingAttributeValue::class);
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'listing_amenity');
    }

    public function facilities(): BelongsToMany
    {
        return $this->belongsToMany(Facility::class, 'listing_facility');
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(ListingView::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ListingStatusHistory::class);
    }

    /**
     * The surveyed parcel outline, for land listings.
     *
     * HasOne rather than a column: see the listing_boundaries migration.
     */
    /**
     * ---- specialist vertical -------------------------------------------
     *
     * A specialist IS a listing (see the specialist migration for why), so
     * their services, working hours and appointments hang off this model
     * rather than off a parallel profile table.
     */

    /** @return HasMany<SpecialistService, $this> */
    public function specialistServices(): HasMany
    {
        return $this->hasMany(SpecialistService::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<SpecialistAvailability, $this> */
    public function specialistAvailability(): HasMany
    {
        return $this->hasMany(SpecialistAvailability::class)->orderBy('weekday')->orderBy('start_time');
    }

    /** @return HasMany<SpecialistAvailabilityBlock, $this> */
    public function specialistBlocks(): HasMany
    {
        return $this->hasMany(SpecialistAvailabilityBlock::class)->orderBy('starts_at');
    }

    /** @return HasMany<SpecialistBooking, $this> */
    public function specialistBookings(): HasMany
    {
        return $this->hasMany(SpecialistBooking::class);
    }

    public function boundary(): HasOne
    {
        return $this->hasOne(ListingBoundary::class);
    }

    /**
     * May this listing carry a parcel outline?
     *
     * Checked against the leaf category and its vertical, so `agriculture` in
     * config covers every crop and livestock subcategory without listing them.
     * Callers must eager-load `category.parent` or accept the lazy load.
     */
    public function supportsBoundary(): bool
    {
        $allowed = (array) config('saka.listings.boundary_categories', []);

        if ($allowed === []) {
            return false;
        }

        $category = $this->category;

        if ($category === null) {
            return false;
        }

        return in_array($category->slug, $allowed, true)
            || ($category->parent !== null && in_array($category->parent->slug, $allowed, true));
    }

    // ------------------------------------------------------------------- scopes

    /**
     * The ONLY scope guests and buyers may read through.
     *
     * Applied unconditionally by the repository — a policy that runs after the
     * query has already loaded rows is too late for a collection response.
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            fn (ListingStatus $s) => $s->value,
            ListingStatus::publiclyVisible()
        ))->whereNotNull('published_at');
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeInCategory(Builder $query, Category $category): Builder
    {
        return $query->whereIn(
            'category_id',
            Category::query()
                ->where('id', $category->id)
                ->orWhere('path', 'like', $category->path.'/%')
                ->select('id')
        );
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true)
            ->where(function (Builder $q): void {
                $q->whereNull('featured_until')->orWhere('featured_until', '>', now());
            });
    }

    /**
     * Bounding-box prefilter so the (latitude, longitude) index is actually
     * used, before the more expensive distance term is applied.
     */
    public function scopeWithinBoundingBox(
        Builder $query,
        float $lat,
        float $lng,
        float $radiusKm
    ): Builder {
        $latDelta = $radiusKm / 111.0;
        $lngDelta = $radiusKm / max(0.000001, 111.0 * cos(deg2rad($lat)));

        return $query
            ->whereBetween('latitude', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('longitude', [$lng - $lngDelta, $lng + $lngDelta]);
    }

    // ------------------------------------------------------------------ helpers

    public function isPublished(): bool
    {
        return $this->status === ListingStatus::Published;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function canTransitionTo(ListingStatus $target): bool
    {
        return $this->status->canTransitionTo($target);
    }
}
