<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Engagement\Enums\InquirySource;
use App\Domain\Engagement\Enums\InquiryStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Serves BOTH frontend entry points: "Contact Seller" on a listing
 * (listing_id set) and the standalone /contact form (listing_id null).
 *
 * Guests may submit, so sender_user_id is nullable.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $listing_id
 * @property int|null $seller_id
 * @property int|null $sender_user_id
 * @property string $first_name
 * @property string|null $last_name
 * @property string $email
 * @property string|null $phone
 * @property string $message
 * @property InquirySource $source
 * @property InquiryStatus $status
 * @property string|null $reply_body
 * @property Carbon|null $replied_at
 * @property Carbon|null $read_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Listing|null $listing
 * @property-read User|null $seller
 * @property-read User|null $sender
 *
 * @method static Builder<static>|Inquiry forSeller(\App\Models\User $seller)
 * @method static Builder<static>|Inquiry newModelQuery()
 * @method static Builder<static>|Inquiry newQuery()
 * @method static Builder<static>|Inquiry query()
 * @method static Builder<static>|Inquiry unread()
 * @method static Builder<static>|Inquiry whereCreatedAt($value)
 * @method static Builder<static>|Inquiry whereEmail($value)
 * @method static Builder<static>|Inquiry whereFirstName($value)
 * @method static Builder<static>|Inquiry whereId($value)
 * @method static Builder<static>|Inquiry whereIpAddress($value)
 * @method static Builder<static>|Inquiry whereLastName($value)
 * @method static Builder<static>|Inquiry whereListingId($value)
 * @method static Builder<static>|Inquiry whereMessage($value)
 * @method static Builder<static>|Inquiry wherePhone($value)
 * @method static Builder<static>|Inquiry whereReadAt($value)
 * @method static Builder<static>|Inquiry whereRepliedAt($value)
 * @method static Builder<static>|Inquiry whereReplyBody($value)
 * @method static Builder<static>|Inquiry whereSellerId($value)
 * @method static Builder<static>|Inquiry whereSenderUserId($value)
 * @method static Builder<static>|Inquiry whereSource($value)
 * @method static Builder<static>|Inquiry whereStatus($value)
 * @method static Builder<static>|Inquiry whereUpdatedAt($value)
 * @method static Builder<static>|Inquiry whereUserAgent($value)
 * @method static Builder<static>|Inquiry whereUuid($value)
 *
 * @mixin \Eloquent
 */
class Inquiry extends Model
{
    use HasFactory;
    use HasUuid;

    protected $table = 'inquiries';

    protected $fillable = [
        'listing_id', 'seller_id', 'sender_user_id',
        'first_name', 'last_name', 'email', 'phone', 'message',
        'source', 'ip_address', 'user_agent',
    ];

    protected $guarded = ['id', 'uuid', 'status', 'reply_body', 'replied_at', 'read_at'];

    protected function casts(): array
    {
        return [
            'source' => InquirySource::class,
            'status' => InquiryStatus::class,
            'replied_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('status', InquiryStatus::New);
    }

    public function scopeForSeller(Builder $query, User $seller): Builder
    {
        return $query->where('seller_id', $seller->id);
    }
}
