<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Enums\Permission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Services\Admin\AdminStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Numbers for the admin dashboard and analytics screens.
 *
 * CACHING. Every payload here is cached for a short, explicitly chosen window.
 * A dashboard is refreshed by every open tab and polled by anyone leaving it up
 * on a wall display, and these are the heaviest aggregate queries on the
 * platform. The windows differ by how fast the number actually moves:
 *
 *   - overview  60s  — moderators watch the pending count and expect it to move
 *                      after they act, so this is short enough to feel live;
 *   - growth   600s  — day-granularity series; a ten-minute-old chart is
 *                      indistinguishable from a live one;
 *   - activity   0   — never cached. It is an audit feed, and a stale audit
 *                      feed is worse than a slow one.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly AdminStatsService $stats) {}

    public function overview(Request $request): JsonResponse
    {
        $this->authorizeAnalytics($request);

        $data = Cache::remember(
            'admin:stats:overview',
            now()->addSeconds(60),
            fn (): array => $this->stats->overview(),
        );

        return response()->json(['data' => $data]);
    }

    public function growth(Request $request): JsonResponse
    {
        $this->authorizeAnalytics($request);

        $validated = $request->validate([
            // Capped at a year: the series is gap-filled to one point per day,
            // so an unbounded range is both a slow query and a chart with more
            // points than pixels.
            'days' => ['nullable', 'integer', 'min:7', 'max:365'],
        ]);

        $days = (int) ($validated['days'] ?? 30);

        $data = Cache::remember(
            "admin:stats:growth:{$days}",
            now()->addSeconds(600),
            fn (): array => $this->stats->growth($days),
        );

        return response()->json(['data' => $data]);
    }

    public function categoryPopularity(Request $request): JsonResponse
    {
        $this->authorizeAnalytics($request);

        $data = Cache::remember(
            'admin:stats:categories',
            now()->addSeconds(600),
            fn (): array => $this->stats->categoryPopularity(),
        );

        return response()->json(['data' => $data]);
    }

    public function topVendors(Request $request): JsonResponse
    {
        $this->authorizeAnalytics($request);

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $limit = (int) ($validated['limit'] ?? 10);

        $data = Cache::remember(
            "admin:stats:vendors:{$limit}",
            now()->addSeconds(600),
            fn (): array => $this->stats->topVendors($limit),
        );

        return response()->json(['data' => $data]);
    }

    /**
     * The audit trail — "Recent Activity" on the dashboard.
     *
     * Requires `activity_log.view` rather than `analytics.view`: this is who
     * did what to whom, which is a materially more sensitive read than a chart
     * of signups.
     */
    public function activity(Request $request): JsonResponse
    {
        if (! $request->user()?->hasPermission(Permission::ActivityLogView)) {
            throw ApiException::forbidden();
        }

        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'actor' => ['nullable', 'string', 'max:191'],
            'subject_type' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $events = AuditEvent::query()
            ->with('actor:id,uuid,first_name,last_name,email')
            ->when($validated['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when(
                $validated['actor'] ?? null,
                fn ($q, $actor) => $q->where('actor_label', 'like', $actor.'%'),
            )
            ->when(
                $validated['subject_type'] ?? null,
                fn ($q, $type) => $q->where('subject_type', 'like', '%'.$type),
            )
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 25)
            ->withQueryString();

        return response()->json([
            'data' => collect($events->items())->map(fn (AuditEvent $event): array => [
                'id' => $event->id,
                'action' => $event->action,
                'actor' => $event->actor !== null ? [
                    'uuid' => $event->actor->uuid,
                    'name' => $event->actor->fullName(),
                    'email' => $event->actor->email,
                ] : null,
                // Survives deletion of the account, unlike the relation above.
                'actor_label' => $event->actor_label,
                'subject' => $event->subject_type !== null ? [
                    'type' => class_basename($event->subject_type),
                    'id' => $event->subject_id,
                ] : null,
                'changes' => $event->after,
                'previous' => $event->before,
                'ip_address' => $event->ip_address,
                'request_id' => $event->request_id,
                'created_at' => $event->created_at?->toAtomString(),
            ])->all(),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
                'from' => $events->firstItem(),
                'to' => $events->lastItem(),
            ],
        ]);
    }

    private function authorizeAnalytics(Request $request): void
    {
        if (! $request->user()?->hasPermission(Permission::AnalyticsView)) {
            throw ApiException::forbidden();
        }
    }
}
