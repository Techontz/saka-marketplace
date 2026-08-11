<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Advertising\Enums\AdPlacement;
use App\Domain\Advertising\Enums\PromotionRequestStatus;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A vendor asking to promote something they own.
 *
 * Approval mints an `AdCampaign` from this; the request itself is never served
 * and carries no delivery figures. Anything a vendor wants to know about
 * performance is read from the campaign, which is the only thing the beacons
 * write to.
 *
 * PAYMENT-READINESS lives in what is ABSENT here. There are no amount, currency
 * or paid_at columns, because SAKA cannot currently take money and a permanently
 * null `paid_at` is not a feature — it is a column every query ignores and a UI
 * that has to guess what "not paid" means. When payment arrives it attaches as
 * `promotion_payments` keyed on this row, `PromotionRequestStatus` keeps meaning
 * review and only review, and nothing here is rewritten.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $promotable_type
 * @property int $promotable_id
 * @property AdPlacement $placement
 * @property Carbon $requested_start
 * @property Carbon $requested_end
 * @property PromotionRequestStatus $status
 * @property string $headline
 * @property string|null $body
 * @property string|null $cta_label
 * @property int|null $image_media_id
 * @property int|null $mobile_media_id
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $rejection_reason
 * @property int|null $ad_campaign_id
 */
class PromotionRequest extends Model
{
    use HasFactory;
    use HasMedia;
    use HasUuid;
    use SoftDeletes;

    /**
     * Only what a VENDOR supplies.
     *
     * `status`, `reviewed_by`, `reviewed_at`, `rejection_reason` and
     * `ad_campaign_id` are all guarded: a vendor who could mass-assign any of
     * them could approve their own promotion. `user_id` is guarded for the same
     * reason in reverse — it is taken from the authenticated caller, never from
     * the body, or one vendor could file a request in another's name.
     */
    protected $fillable = [
        'promotable_type', 'promotable_id', 'placement',
        'requested_start', 'requested_end',
        'headline', 'body', 'cta_label',
        'image_media_id', 'mobile_media_id',
    ];

    protected $guarded = [
        'id', 'uuid', 'user_id', 'status',
        'reviewed_by', 'reviewed_at', 'rejection_reason', 'ad_campaign_id',
    ];

    protected function casts(): array
    {
        return [
            'placement' => AdPlacement::class,
            'status' => PromotionRequestStatus::class,
            'requested_start' => 'date',
            'requested_end' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<User, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** The listing, business profile or specialist profile being promoted. */
    public function promotable(): MorphTo
    {
        return $this->morphTo();
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

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Null until approval. The seam between a request and served inventory.
     *
     * @return BelongsTo<AdCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }

    /** @param  Builder<static>  $query */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PromotionRequestStatus::Pending->value);
    }

    /**
     * Whether this request has everything approval will need.
     *
     * Checked at SUBMISSION so a vendor is told immediately, and again at
     * APPROVAL because time passes in between — artwork can be deleted, and a
     * requested window can close while the request sits in the queue.
     */
    public function hasArtwork(): bool
    {
        return $this->image_media_id !== null;
    }

    /**
     * Whether the requested window is still usable.
     *
     * A request for "next Monday to Friday" reviewed the following Saturday
     * cannot be approved into a window that has already closed — the campaign
     * would be minted expired and never serve, and the vendor would see
     * "Approved" against something that did nothing.
     */
    public function windowHasClosed(?Carbon $now = null): bool
    {
        return $this->requested_end->endOfDay()->isBefore($now ?? Carbon::now());
    }
}
