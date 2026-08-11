<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Identity\Enums\BusinessType;
use App\Domain\Trust\Enums\VerificationLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Seller identity. Also the seed of the v1.1 Storefront feature — slug, bio and
 * logo already live here, so storefronts become routes and UI, not a migration.
 *
 * @property int $id
 * @property int $user_id
 * @property string $display_name
 * @property string $slug
 * @property string|null $bio
 * @property string|null $business_name
 * @property BusinessType|null $business_type
 * @property string $country_code
 * @property int|null $region_id
 * @property int|null $district_id
 * @property int|null $ward_id
 * @property string|null $street
 * @property numeric|null $latitude
 * @property numeric|null $longitude
 * @property string|null $business_reg_no
 * @property string|null $tin
 * @property int|null $logo_media_id
 * @property int|null $cover_media_id
 * @property string|null $whatsapp
 * @property string|null $public_email
 * @property string|null $public_phone
 * @property array<array-key, mixed>|null $opening_hours
 * @property array<array-key, mixed>|null $social_links
 * @property string|null $website
 * @property bool $is_verified
 * @property Carbon|null $verified_at
 * @property Carbon|null $onboarding_completed_at
 * @property VerificationLevel $verification_level
 * @property int $total_listings
 * @property int $active_listings
 * @property numeric|null $rating_avg
 * @property int $rating_count
 * @property int|null $response_rate_pct
 * @property int|null $response_time_minutes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Media|null $cover
 * @property-read District|null $district
 * @property-read Media|null $logo
 * @property-read Region|null $region
 * @property-read User|null $user
 * @property-read Ward|null $ward
 *
 * @method static Builder<static>|SellerProfile newModelQuery()
 * @method static Builder<static>|SellerProfile newQuery()
 * @method static Builder<static>|SellerProfile onlyTrashed()
 * @method static Builder<static>|SellerProfile query()
 * @method static Builder<static>|SellerProfile verified()
 * @method static Builder<static>|SellerProfile whereActiveListings($value)
 * @method static Builder<static>|SellerProfile whereBio($value)
 * @method static Builder<static>|SellerProfile whereBusinessName($value)
 * @method static Builder<static>|SellerProfile whereBusinessRegNo($value)
 * @method static Builder<static>|SellerProfile whereBusinessType($value)
 * @method static Builder<static>|SellerProfile whereCountryCode($value)
 * @method static Builder<static>|SellerProfile whereCoverMediaId($value)
 * @method static Builder<static>|SellerProfile whereCreatedAt($value)
 * @method static Builder<static>|SellerProfile whereDeletedAt($value)
 * @method static Builder<static>|SellerProfile whereDisplayName($value)
 * @method static Builder<static>|SellerProfile whereDistrictId($value)
 * @method static Builder<static>|SellerProfile whereId($value)
 * @method static Builder<static>|SellerProfile whereIsVerified($value)
 * @method static Builder<static>|SellerProfile whereLatitude($value)
 * @method static Builder<static>|SellerProfile whereLogoMediaId($value)
 * @method static Builder<static>|SellerProfile whereLongitude($value)
 * @method static Builder<static>|SellerProfile whereOnboardingCompletedAt($value)
 * @method static Builder<static>|SellerProfile whereOpeningHours($value)
 * @method static Builder<static>|SellerProfile wherePublicEmail($value)
 * @method static Builder<static>|SellerProfile wherePublicPhone($value)
 * @method static Builder<static>|SellerProfile whereRatingAvg($value)
 * @method static Builder<static>|SellerProfile whereRatingCount($value)
 * @method static Builder<static>|SellerProfile whereRegionId($value)
 * @method static Builder<static>|SellerProfile whereResponseRatePct($value)
 * @method static Builder<static>|SellerProfile whereResponseTimeMinutes($value)
 * @method static Builder<static>|SellerProfile whereSlug($value)
 * @method static Builder<static>|SellerProfile whereSocialLinks($value)
 * @method static Builder<static>|SellerProfile whereStreet($value)
 * @method static Builder<static>|SellerProfile whereTin($value)
 * @method static Builder<static>|SellerProfile whereTotalListings($value)
 * @method static Builder<static>|SellerProfile whereUpdatedAt($value)
 * @method static Builder<static>|SellerProfile whereUserId($value)
 * @method static Builder<static>|SellerProfile whereVerificationLevel($value)
 * @method static Builder<static>|SellerProfile whereVerifiedAt($value)
 * @method static Builder<static>|SellerProfile whereWardId($value)
 * @method static Builder<static>|SellerProfile whereWebsite($value)
 * @method static Builder<static>|SellerProfile whereWhatsapp($value)
 * @method static Builder<static>|SellerProfile withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|SellerProfile withoutTrashed()
 *
 * @mixin \Eloquent
 */
class SellerProfile extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'display_name', 'slug', 'bio',
        'business_name', 'business_reg_no', 'tin',
        'logo_media_id', 'whatsapp', 'website',
        // Milestone 12 — the business profile.
        'business_type', 'country_code', 'region_id', 'district_id', 'ward_id',
        'street', 'latitude', 'longitude', 'public_email', 'public_phone',
        'opening_hours', 'social_links',
    ];

    protected $guarded = [
        'id', 'is_verified', 'verified_at', 'verification_level',
        'total_listings', 'active_listings', 'rating_avg', 'rating_count',
        'response_rate_pct', 'response_time_minutes',
    ];

    protected function casts(): array
    {
        return [
            'business_type' => BusinessType::class,
            'opening_hours' => 'array',
            'social_links' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'onboarding_completed_at' => 'datetime',
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
            'verification_level' => VerificationLevel::class,
            'total_listings' => 'integer',
            'active_listings' => 'integer',
            'rating_avg' => 'decimal:2',
            'rating_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /** @return BelongsTo<District, $this> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /** @return BelongsTo<Ward, $this> */
    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    public function logo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'logo_media_id');
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('is_verified', true);
    }
}
