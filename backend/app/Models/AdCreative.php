<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The artwork and copy of one advertisement.
 *
 * A campaign holds several so an advertiser can rotate creatives without
 * re-booking the placement, and so swapping a banner mid-flight does not reset
 * the campaign totals they are being invoiced against.
 *
 * @property int $id
 * @property string $uuid
 * @property int $ad_campaign_id
 * @property string $headline
 * @property string|null $body
 * @property string|null $cta_label
 * @property string $click_url
 * @property int|null $image_media_id
 * @property int|null $mobile_media_id
 * @property string|null $alt_text
 * @property bool $is_active
 * @property int $position
 * @property int $impressions_count
 * @property int $clicks_count
 */
class AdCreative extends Model
{
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'ad_campaign_id', 'headline', 'body', 'cta_label', 'click_url',
        'image_media_id', 'mobile_media_id', 'alt_text', 'is_active', 'position',
    ];

    protected $guarded = ['id', 'uuid', 'impressions_count', 'clicks_count'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
            'impressions_count' => 'integer',
            'clicks_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<AdCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_media_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function mobileImage(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'mobile_media_id');
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Click-through rate as a percentage, or null when nothing has been shown.
     *
     * Same shape and the same null-versus-zero distinction as
     * `AdCampaign::clickThroughRate()`, and the same reason for the cast: these
     * counters are database defaults and read as null on a freshly inserted
     * model, which is exactly the moment the create response serialises them.
     */
    public function clickThroughRate(): ?float
    {
        $impressions = (int) $this->impressions_count;

        if ($impressions === 0) {
            return null;
        }

        return round(((int) $this->clicks_count / $impressions) * 100, 2);
    }

    /**
     * The alt text a screen reader should hear.
     *
     * Falls back to the headline rather than to an empty string: this is a
     * LINK, so `alt=""` would leave it announced as an unlabelled anchor —
     * a control the user is told exists but not what it does.
     */
    public function accessibleAltText(): string
    {
        return $this->alt_text ?: $this->headline;
    }
}
