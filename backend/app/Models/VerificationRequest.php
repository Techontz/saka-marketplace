<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Trust\Enums\VerificationStatus;
use App\Domain\Trust\Enums\VerificationType;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property VerificationType $type
 * @property VerificationStatus $status
 * @property int|null $document_media_id
 * @property string|null $document_number
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $rejection_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Media|null $document
 * @property-read User|null $reviewer
 * @property-read User|null $user
 *
 * @method static \Database\Factories\VerificationRequestFactory factory($count = null, $state = [])
 * @method static Builder<static>|VerificationRequest newModelQuery()
 * @method static Builder<static>|VerificationRequest newQuery()
 * @method static Builder<static>|VerificationRequest pending()
 * @method static Builder<static>|VerificationRequest query()
 * @method static Builder<static>|VerificationRequest whereCreatedAt($value)
 * @method static Builder<static>|VerificationRequest whereDocumentMediaId($value)
 * @method static Builder<static>|VerificationRequest whereDocumentNumber($value)
 * @method static Builder<static>|VerificationRequest whereId($value)
 * @method static Builder<static>|VerificationRequest whereRejectionReason($value)
 * @method static Builder<static>|VerificationRequest whereReviewedAt($value)
 * @method static Builder<static>|VerificationRequest whereReviewedBy($value)
 * @method static Builder<static>|VerificationRequest whereStatus($value)
 * @method static Builder<static>|VerificationRequest whereType($value)
 * @method static Builder<static>|VerificationRequest whereUpdatedAt($value)
 * @method static Builder<static>|VerificationRequest whereUserId($value)
 * @method static Builder<static>|VerificationRequest whereUuid($value)
 *
 * @mixin \Eloquent
 */
class VerificationRequest extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = ['user_id', 'type', 'document_media_id', 'document_number'];

    protected $guarded = ['id', 'uuid', 'status', 'reviewed_by', 'reviewed_at', 'rejection_reason'];

    protected function casts(): array
    {
        return [
            'type' => VerificationType::class,
            'status' => VerificationStatus::class,
            'reviewed_at' => 'datetime',
            /*
             * ENCRYPTED AT REST.
             *
             * This holds a NIDA number — a national identity number that is not
             * reissued and identifies a person for life. In plaintext it would
             * sit in every backup, replica and console `SELECT *`. Encrypted,
             * a leaked dump is useless without APP_KEY, which lives in the
             * environment rather than the database.
             *
             * The column is TEXT for this reason: Laravel's encrypter emits a
             * base64 JSON envelope, so a 20-digit number becomes ~250 chars.
             */
            'document_number' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'document_media_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', VerificationStatus::Pending);
    }
}
