<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One-time phone codes.
 *
 * The plaintext code NEVER touches the database — only a hash. A database leak
 * must not hand out live OTPs.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $phone
 * @property string $code_hash
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property string|null $ip_address
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 *
 * @method static Builder<static>|PhoneVerificationCode newModelQuery()
 * @method static Builder<static>|PhoneVerificationCode newQuery()
 * @method static Builder<static>|PhoneVerificationCode query()
 * @method static Builder<static>|PhoneVerificationCode usable()
 * @method static Builder<static>|PhoneVerificationCode whereAttempts($value)
 * @method static Builder<static>|PhoneVerificationCode whereCodeHash($value)
 * @method static Builder<static>|PhoneVerificationCode whereConsumedAt($value)
 * @method static Builder<static>|PhoneVerificationCode whereCreatedAt($value)
 * @method static Builder<static>|PhoneVerificationCode whereExpiresAt($value)
 * @method static Builder<static>|PhoneVerificationCode whereId($value)
 * @method static Builder<static>|PhoneVerificationCode whereIpAddress($value)
 * @method static Builder<static>|PhoneVerificationCode wherePhone($value)
 * @method static Builder<static>|PhoneVerificationCode whereUpdatedAt($value)
 * @method static Builder<static>|PhoneVerificationCode whereUserId($value)
 *
 * @mixin \Eloquent
 */
class PhoneVerificationCode extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'phone', 'code_hash', 'expires_at', 'ip_address'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', config('saka.otp.max_attempts'));
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < config('saka.otp.max_attempts');
    }
}
