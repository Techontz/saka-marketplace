<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Domain\Engagement\Enums\NotificationType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes to the notification centre.
 *
 * Rows go straight into Laravel's `notifications` table rather than through
 * Notification classes, because there is no mail or push channel to fan out to
 * yet — every notification in SAKA today is read in-app. Using the standard
 * table means the `Notifiable` trait, its relation and its read/unread
 * semantics all keep working, and adding a mail channel later is additive.
 *
 * PREFERENCES ARE ENFORCED HERE, at the single point where notifications are
 * created, so a silenced category cannot leak in through a new call site.
 * Moderation outcomes have no switch on purpose — see NotificationType.
 */
class NotificationService
{
    /**
     * @param  array<string, mixed>  $data  Rendered payload: title, body, and
     *                                      whatever the frontend needs to link
     *                                      to the thing this is about.
     */
    public function send(User $user, NotificationType $type, array $data): bool
    {
        if (! $user->wantsNotification($type->preferenceKey())) {
            return false;
        }

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid7(),
            'type' => $type->value,
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->getKey(),
            'data' => json_encode($data + ['type' => $type->value, 'title' => $data['title'] ?? $type->label()]),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    /**
     * Send the same notification to many people at once.
     *
     * Used by favourite alerts, where one price change fans out to everyone who
     * saved the listing. One insert rather than N, and preferences are still
     * applied per recipient.
     *
     * @param  iterable<User>  $users
     * @param  callable(User): array<string, mixed>  $payload
     */
    public function sendMany(iterable $users, NotificationType $type, callable $payload): int
    {
        $rows = [];

        foreach ($users as $user) {
            if (! $user->wantsNotification($type->preferenceKey())) {
                continue;
            }

            $data = $payload($user);

            $rows[] = [
                'id' => (string) Str::uuid7(),
                'type' => $type->value,
                'notifiable_type' => $user->getMorphClass(),
                'notifiable_id' => $user->getKey(),
                'data' => json_encode($data + ['type' => $type->value, 'title' => $data['title'] ?? $type->label()]),
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows === []) {
            return 0;
        }

        // Chunked: a popular listing can have thousands of watchers, and one
        // INSERT with thousands of rows exceeds max_allowed_packet.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('notifications')->insert($chunk);
        }

        return count($rows);
    }

    public function unreadCount(User $user): int
    {
        return DB::table('notifications')
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->whereNull('read_at')
            ->count();
    }

    public function markAllRead(User $user): int
    {
        return DB::table('notifications')
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }
}
