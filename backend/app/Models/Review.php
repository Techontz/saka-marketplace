<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Engagement\Enums\ReviewStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Moderated seller/listing review. Promoted to MVP by Milestone 4 decision 2.
 *
 * Only Approved rows feed the seller rating rollup.
 *
 * @property int $id
 * @property string $uuid
 * @property int $seller_id
 * @property int|null $listing_id
 * @property int $reviewer_id
 * @property int $rating
 * @property string|null $title
 * @property string|null $body
 * @property ReviewStatus $status
 * @property string|null $moderation_note
 * @property int|null $moderated_by
 * @property Carbon|null $moderated_at
 * @property string|null $reply_body
 * @property Carbon|null $replied_at
 * @property int $helpful_count
 * @property bool $is_verified_purchase
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Listing|null $listing
 * @property-read User|null $moderator
 * @property-read User|null $reviewer
 * @property-read User|null $seller
 *
 * @method static Builder<static>|Review approved()
 * @method static \Database\Factories\ReviewFactory factory($count = null, $state = [])
 * @method static Builder<static>|Review newModelQuery()
 * @method static Builder<static>|Review newQuery()
 * @method static Builder<static>|Review onlyTrashed()
 * @method static Builder<static>|Review pending()
 * @method static Builder<static>|Review query()
 * @method static Builder<static>|Review whereBody($value)
 * @method static Builder<static>|Review whereCreatedAt($value)
 * @method static Builder<static>|Review whereDeletedAt($value)
 * @method static Builder<static>|Review whereHelpfulCount($value)
 * @method static Builder<static>|Review whereId($value)
 * @method static Builder<static>|Review whereIsVerifiedPurchase($value)
 * @method static Builder<static>|Review whereListingId($value)
 * @method static Builder<static>|Review whereModeratedAt($value)
 * @method static Builder<static>|Review whereModeratedBy($value)
 * @method static Builder<static>|Review whereModerationNote($value)
 * @method static Builder<static>|Review whereRating($value)
 * @method static Builder<static>|Review whereRepliedAt($value)
 * @method static Builder<static>|Review whereReplyBody($value)
 * @method static Builder<static>|Review whereReviewerId($value)
 * @method static Builder<static>|Review whereSellerId($value)
 * @method static Builder<static>|Review whereStatus($value)
 * @method static Builder<static>|Review whereTitle($value)
 * @method static Builder<static>|Review whereUpdatedAt($value)
 * @method static Builder<static>|Review whereUuid($value)
 * @method static Builder<static>|Review withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Review withoutTrashed()
 *
 * @mixin \Eloquent
 */
class Review extends Model
{
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = ['seller_id', 'listing_id', 'reviewer_id', 'rating', 'title', 'body'];

    protected $guarded = [
        'id', 'uuid', 'status', 'moderation_note', 'moderated_by', 'moderated_at',
        'reply_body', 'replied_at', 'helpful_count', 'is_verified_purchase',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'rating' => 'integer',
            'helpful_count' => 'integer',
            'is_verified_purchase' => 'boolean',
            'moderated_at' => 'datetime',
            'replied_at' => 'datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', ReviewStatus::Approved);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ReviewStatus::Pending);
    }
}
