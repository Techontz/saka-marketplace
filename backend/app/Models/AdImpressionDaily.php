<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Advertising\Enums\AdPlacement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Impressions for one creative, in one placement, on one day.
 *
 * The source of truth for reporting. The `impressions_count` columns on the
 * campaign and creative are a denormalised lifetime total kept for single-row
 * reads (the admin list, the impression-cap check); this is what a date-ranged
 * chart is built from.
 *
 * @property int $id
 * @property int $ad_creative_id
 * @property int $ad_campaign_id
 * @property AdPlacement $placement
 * @property Carbon $date
 * @property int $impressions
 */
class AdImpressionDaily extends Model
{
    protected $table = 'ad_impressions_daily';

    protected $fillable = [
        'ad_creative_id', 'ad_campaign_id', 'placement', 'date', 'impressions',
    ];

    protected function casts(): array
    {
        return [
            'placement' => AdPlacement::class,
            'date' => 'date',
            'impressions' => 'integer',
        ];
    }

    /** @return BelongsTo<AdCreative, $this> */
    public function creative(): BelongsTo
    {
        return $this->belongsTo(AdCreative::class, 'ad_creative_id');
    }

    /** @return BelongsTo<AdCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }
}
