<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Advertising\Enums\AdPlacement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One click on one advertisement.
 *
 * Stored individually — unlike impressions, which are rolled up daily. Clicks
 * are what an advertiser is billed for, and a billing dispute is answered by
 * pointing at rows: "when, from where, how many times" is not a question a
 * daily total can answer.
 *
 * There is no `updated_at`: a click is an event, not a record that changes.
 *
 * @property int $id
 * @property int $ad_creative_id
 * @property int $ad_campaign_id
 * @property AdPlacement $placement
 * @property int|null $user_id
 * @property string|null $ip_hash
 * @property string|null $referrer
 */
class AdClick extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'ad_creative_id', 'ad_campaign_id', 'placement',
        'user_id', 'ip_hash', 'referrer', 'clicked_at',
    ];

    protected function casts(): array
    {
        return [
            'placement' => AdPlacement::class,
            'clicked_at' => 'datetime',
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
