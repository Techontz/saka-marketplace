<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Domain\Engagement\Enums\NotificationType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Engagement\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The notification centre.
 *
 * Rows live in Laravel's standard `notifications` table and are read straight
 * out of it rather than through Notification classes — see NotificationService
 * for why. The payload is already rendered at write time, so this endpoint does
 * no per-row lookups and a hundred notifications are one query.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unread' => ['nullable', 'boolean'],
            'type' => ['nullable', 'string', 'max:60'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();

        $query = DB::table('notifications')
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->when($validated['type'] ?? null, fn ($q, string $type) => $q->where('type', $type))
            ->orderByDesc('created_at');

        $page = $query->paginate(min((int) ($validated['per_page'] ?? 20), 100))->withQueryString();

        /** @var array<int, object{id: string, type: string, data: string, read_at: string|null, created_at: string}> $rows */
        $rows = $page->items();

        $items = [];

        foreach ($rows as $row) {
            $items[] = [
                'id' => $row->id,
                'type' => $row->type,
                'data' => json_decode((string) $row->data, true) ?: [],
                'read' => $row->read_at !== null,
                'read_at' => $row->read_at,
                'created_at' => $row->created_at,
            ];
        }

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                // Carried on every page so the bell badge never needs a second
                // request, and never disagrees with the list beneath it.
                'unread_count' => $this->notifications->unreadCount($user),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['unread_count' => $this->notifications->unreadCount($request->user())],
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $updated = DB::table('notifications')
            ->where('id', $id)
            // Scoped to the caller: without this, any uuid could be marked read
            // on anyone's behalf.
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->update(['read_at' => now(), 'updated_at' => now()]);

        if ($updated === 0) {
            throw ApiException::notFound('Notification not found.');
        }

        return response()->json([
            'data' => ['read' => true, 'unread_count' => $this->notifications->unreadCount($user)],
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $marked = $this->notifications->markAllRead($request->user());

        return response()->json(['data' => ['marked' => $marked, 'unread_count' => 0]]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $deleted = DB::table('notifications')
            ->where('id', $id)
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->delete();

        if ($deleted === 0) {
            throw ApiException::notFound('Notification not found.');
        }

        return response()->json(['data' => ['message' => 'Notification removed.']]);
    }

    /** The switches a customer may set, with their current values. */
    public function preferences(Request $request): JsonResponse
    {
        $user = $request->user();
        $chosen = $user->notification_preferences ?? [];

        return response()->json([
            'data' => collect(NotificationType::preferenceDefaults())
                ->map(fn (bool $default, string $key): array => [
                    'key' => $key,
                    'enabled' => (bool) ($chosen[$key] ?? $default),
                    'default' => $default,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $keys = array_keys(NotificationType::preferenceDefaults());

        $rules = ['preferences' => ['required', 'array']];
        foreach ($keys as $key) {
            $rules["preferences.{$key}"] = ['nullable', 'boolean'];
        }

        $validated = $request->validate($rules);

        $user = $request->user();

        // Merged, not replaced: a client that sends one switch must not silently
        // reset the others to their defaults.
        $user->forceFill([
            'notification_preferences' => array_intersect_key(
                array_merge($user->notification_preferences ?? [], $validated['preferences']),
                array_flip($keys),
            ),
        ])->save();

        return $this->preferences($request);
    }
}
