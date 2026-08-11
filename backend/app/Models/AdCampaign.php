<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Advertising\Enums\AdCampaignStatus;
use App\Domain\Advertising\Enums\AdPlacement;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A booking of advertising inventory.
 *
 * @property int $id
 * @property string $uuid
 * @property int $advertiser_id
 * @property string $name
 * @property AdPlacement $placement
 * @property AdCampaignStatus $status
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int $priority
 * @property int|null $impression_cap
 * @property int $impressions_count
 * @property int $clicks_count
 */
class AdCampaign extends Model
{
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'advertiser_id', 'name', 'placement',
        'starts_at', 'ends_at', 'priority', 'impression_cap',
    ];

    /**
     * `status` is guarded deliberately.
     *
     * It is moved by explicit service calls and by the scheduler, never by a
     * mass assignment from a request body — otherwise "edit this campaign's
     * name" could activate it, which is the difference between a draft and a
     * live advert the advertiser is billed for.
     */
    protected $guarded = [
        'id', 'uuid', 'status', 'impressions_count', 'clicks_count', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'placement' => AdPlacement::class,
            'status' => AdCampaignStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'priority' => 'integer',
            'impression_cap' => 'integer',
            'impressions_count' => 'integer',
            'clicks_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Advertiser, $this> */
    public function advertiser(): BelongsTo
    {
        return $this->belongsTo(Advertiser::class);
    }

    /** @return HasMany<AdCreative, $this> */
    public function creatives(): HasMany
    {
        return $this->hasMany(AdCreative::class);
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'ad_campaign_category');
    }

    /** @return BelongsToMany<Region, $this> */
    public function regions(): BelongsToMany
    {
        return $this->belongsToMany(Region::class, 'ad_campaign_region');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Campaigns that may be served RIGHT NOW.
     *
     * The date window is evaluated here rather than trusted from `status`,
     * which is a cache the scheduler maintains. See AdCampaignStatus: if
     * serving read the column alone, a missed cron run would keep expired
     * campaigns live and hold back ones whose window had opened — a billing
     * incident caused by an unrelated outage.
     *
     * The impression cap is checked against the denormalised counter for the
     * same reason: it must hold even when nothing scheduled has run.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeServable(Builder $query, ?Carbon $now = null): Builder
    {
        $moment = $now ?? Carbon::now();

        return $query
            ->where('status', AdCampaignStatus::Active->value)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $moment))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $moment))
            ->where(fn (Builder $q) => $q->whereNull('impression_cap')
                ->orWhereColumn('impressions_count', '<', 'impression_cap'));
    }

    /**
     * What the status WOULD be if the schedule alone decided.
     *
     * Used by the scheduler and by the admin's optimistic display after an
     * edit, so both agree without either re-deriving the rule.
     */
    public function scheduledStatus(?Carbon $now = null): AdCampaignStatus
    {
        if (! $this->status->followsSchedule()) {
            return $this->status;
        }

        $moment = $now ?? Carbon::now();

        if ($this->ends_at !== null && $this->ends_at->lt($moment)) {
            return AdCampaignStatus::Expired;
        }

        if ($this->starts_at !== null && $this->starts_at->gt($moment)) {
            return AdCampaignStatus::Scheduled;
        }

        return AdCampaignStatus::Active;
    }

    /**
     * Click-through rate as a percentage, or null when nothing has been shown.
     *
     * Null rather than 0.0: "no data" and "shown and never clicked" are
     * different facts, and an advertiser reads them very differently.
     *
     * The cast is load-bearing. The counters are DATABASE defaults, so on a
     * model that has just been inserted and not reloaded they are null, not 0 —
     * and `null === 0` is false, which sent this straight into a
     * DivisionByZeroError on the response to every campaign creation. Guarding
     * on the cast value covers both the fresh-insert and the loaded case.
     */
    public function clickThroughRate(): ?float
    {
        $impressions = (int) $this->impressions_count;

        if ($impressions === 0) {
            return null;
        }

        return round(((int) $this->clicks_count / $impressions) * 100, 2);
    }
}
