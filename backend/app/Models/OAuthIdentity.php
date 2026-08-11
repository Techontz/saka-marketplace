<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Identity\Enums\OAuthProvider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property OAuthProvider $provider
 * @property string $provider_user_id
 * @property string|null $email
 * @property array<array-key, mixed>|null $payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OAuthIdentity newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OAuthIdentity newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OAuthIdentity query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OAuthIdentity whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OAuthIdentity whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OAuthIdentity whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OAuthIdentity wherePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OAuthIdentity whereProvider($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OAuthIdentity whereProviderUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OAuthIdentity whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OAuthIdentity whereUserId($value)
 *
 * @mixin \Eloquent
 */
class OAuthIdentity extends Model
{
    use HasFactory;

    protected $table = 'oauth_identities';

    protected $fillable = ['user_id', 'provider', 'provider_user_id', 'email', 'payload'];

    protected function casts(): array
    {
        return [
            'provider' => OAuthProvider::class,
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
