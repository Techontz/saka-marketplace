<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable trail of every listing status transition.
 *
 * @property int $id
 * @property int $listing_id
 * @property string|null $from_status
 * @property string $to_status
 * @property int|null $changed_by
 * @property string|null $reason
 * @property Carbon $created_at
 * @property-read User|null $changedBy
 * @property-read Listing|null $listing
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingStatusHistory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingStatusHistory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingStatusHistory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingStatusHistory whereChangedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingStatusHistory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingStatusHistory whereFromStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingStatusHistory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingStatusHistory whereListingId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingStatusHistory whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingStatusHistory whereToStatus($value)
 *
 * @mixin \Eloquent
 */
class ListingStatusHistory extends Model
{
    use HasFactory;

    protected $table = 'listing_status_histories';

    public $timestamps = false;

    protected $fillable = ['listing_id', 'from_status', 'to_status', 'changed_by', 'reason', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
