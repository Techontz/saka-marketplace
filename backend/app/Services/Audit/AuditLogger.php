<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records administrative actions to the audit trail.
 *
 * The `audit_events` table shipped in Milestone 5 with a hash-chain design and
 * nothing writing to it. This is the writer, and the reason the admin portal's
 * "Recent Activity" is a real feed rather than a placeholder.
 *
 * THREE DESIGN POINTS WORTH KNOWING
 *
 * 1. **Logging never fails the action.** Every write is wrapped: if the audit
 *    insert throws, the administrator's action still succeeds and the failure
 *    is logged. An audit trail that can take down user management is a worse
 *    outcome than a gap in the trail — and the gap is visible, because the hash
 *    chain does not care about missing rows, only altered ones.
 *
 * 2. **The chain is serialised with a row lock.** Two concurrent admin actions
 *    reading the same `prev_hash` would produce two entries claiming the same
 *    predecessor, which looks exactly like tampering. `lockForUpdate` on the
 *    tail makes appends sequential. Admin writes are low-volume, so the
 *    contention cost is irrelevant.
 *
 * 3. **`before`/`after` are filtered.** Only the attributes that actually
 *    changed are stored, and a deny-list keeps credentials and tokens out
 *    entirely — an audit log is a high-value target precisely because it
 *    accumulates everything.
 */
class AuditLogger
{
    /**
     * Never recorded, in either direction.
     *
     * A password hash in an audit row is a permanent offline-cracking target,
     * and a token is a live credential.
     */
    private const REDACTED = [
        'password', 'password_confirmation', 'remember_token',
        'token', 'plainTextToken', 'api_token', 'secret',
    ];

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(
        string $action,
        ?User $actor,
        ?Model $subject = null,
        array $before = [],
        array $after = [],
    ): ?AuditEvent {
        try {
            return $this->write($action, $actor, $subject, $before, $after);
        } catch (Throwable $e) {
            // See design point 1.
            Log::error('audit.write_failed', [
                'action' => $action,
                'actor_id' => $actor?->getKey(),
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Convenience for the common case: a model was changed.
     *
     * Reads the diff off the model itself, so a caller cannot forget to record
     * one side of it.
     */
    public function recordChange(string $action, ?User $actor, Model $subject): ?AuditEvent
    {
        $changes = $subject->getChanges();
        $original = array_intersect_key($subject->getRawOriginal(), $changes);

        return $this->record($action, $actor, $subject, $original, $changes);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function write(
        string $action,
        ?User $actor,
        ?Model $subject,
        array $before,
        array $after,
    ): AuditEvent {
        $request = request();

        return DB::transaction(function () use ($action, $actor, $subject, $before, $after, $request): AuditEvent {
            // See design point 2.
            $previous = AuditEvent::query()
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $event = new AuditEvent([
                'action' => $action,
                'actor_id' => $actor?->getKey(),
                // Denormalised on purpose: the FK is ON DELETE SET NULL, so
                // without this an entry loses all trace of who acted the moment
                // that account is removed — which is exactly when you need it.
                'actor_label' => $actor?->email,
                'subject_type' => $subject !== null ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'before' => $this->clean($before),
                'after' => $this->clean($after),
                'ip_address' => $request?->ip(),
                'user_agent' => substr((string) $request?->userAgent(), 0, 255) ?: null,
                'request_id' => $request?->attributes->get('request_id'),
                'prev_hash' => $previous?->hash,
            ]);

            $event->created_at = now();
            $event->hash = $this->hash($event);
            $event->save();

            return $event;
        });
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>|null
     */
    private function clean(array $values): ?array
    {
        $filtered = array_diff_key($values, array_flip(self::REDACTED));

        return $filtered === [] ? null : $filtered;
    }

    public function hash(AuditEvent $event): string
    {
        return hash('sha256', json_encode($event->hashPayload(), JSON_THROW_ON_ERROR));
    }
}
