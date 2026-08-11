<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One administrative action, recorded.
 *
 * Append-only by contract: there is no `updated_at`, and nothing in the
 * application updates or deletes a row. `prev_hash`/`hash` chain each entry to
 * the one before it, so a row edited or removed directly in the database breaks
 * the chain from that point on and `saka:audit:verify` reports where.
 *
 * That is tamper-EVIDENT, not tamper-proof — anyone with write access to the
 * table could recompute the whole chain. It raises the cost of a quiet edit
 * from "one UPDATE" to "rewrite every subsequent row", which is the realistic
 * bar for an audit log that lives in the same database as the data.
 *
 * @property int $id
 * @property string $action
 * @property int|null $actor_id
 * @property string|null $actor_label
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<array-key, mixed>|null $before
 * @property array<array-key, mixed>|null $after
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property string|null $prev_hash
 * @property string|null $hash
 * @property Carbon $created_at
 * @property-read User|null $actor
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEvent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEvent whereAction($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEvent whereActorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEvent whereActorLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEvent whereAfter($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEvent whereBefore($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEvent whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEvent whereHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEvent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEvent whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEvent wherePrevHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEvent whereRequestId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEvent whereSubjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEvent whereSubjectType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEvent whereUserAgent($value)
 *
 * @mixin \Eloquent
 */
class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'audit_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The payload the chain hash is computed over.
     *
     * Deliberately excludes `hash` itself and includes `prev_hash`, which is
     * what links each entry to its predecessor.
     *
     * @return array<string, mixed>
     */
    public function hashPayload(): array
    {
        return [
            'action' => $this->action,
            'actor_id' => $this->actor_id,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'before' => $this->before,
            'after' => $this->after,
            'created_at' => $this->created_at?->toIso8601String(),
            'prev_hash' => $this->prev_hash,
        ];
    }
}
