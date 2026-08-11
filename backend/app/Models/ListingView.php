<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Raw view event. The fastest-growing table in the system: rolled up nightly
 * into listing_view_daily and pruned at 90 days.
 *
 * @property int $id
 * @property int $listing_id
 * @property int|null $user_id
 * @property string|null $session_id
 * @property string $ip_hash
 * @property string|null $referrer
 * @property Carbon $viewed_at
 * @property string|null $viewed_on
 * @property-read Listing|null $listing
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingView newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingView newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingView query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingView whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingView whereIpHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingView whereListingId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingView whereReferrer($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingView whereSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingView whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingView whereViewedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingView whereViewedOn($value)
 *
 * @mixin \Eloquent
 */
class ListingView extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['listing_id', 'user_id', 'session_id', 'ip_hash', 'referrer', 'viewed_at'];

    protected function casts(): array
    {
        return ['viewed_at' => 'datetime'];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
