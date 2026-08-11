<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Enums\Permission;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Support\Cache\CacheKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Operational controls: cache, maintenance mode, and a read-only system report.
 *
 * These are the settings screens that are ACTIONS rather than key/value rows —
 * "clear the cache" is not something you store, it is something you do.
 *
 * Every action here is super-admin only and audited. Flipping maintenance mode
 * takes the whole platform offline; clearing the cache briefly makes every page
 * slow. Neither belongs behind the same permission as editing an FAQ.
 */
class SystemController extends Controller
{
    /**
     * Cache groups an administrator can clear independently.
     *
     * `flush` is deliberately absent. Cache and queue share a Redis instance in
     * some deployments, and a full FLUSHDB from a settings screen would discard
     * queued jobs — the kind of button that causes an incident the first time
     * someone is curious about it.
     */
    private const CACHE_TARGETS = ['application', 'taxonomy', 'content', 'discovery', 'config'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Read-only environment and storage report.
     *
     * Deliberately narrow: versions, driver names, queue depth and disk usage.
     * NOT phpinfo(), not the resolved config, not environment variables — an
     * admin surface is a credential-harvesting target, and the value of
     * "which cache driver am I on?" does not justify exposing the rest.
     */
    public function info(Request $request): JsonResponse
    {
        $this->authorizeSettings($request);

        return response()->json([
            'data' => [
                'application' => [
                    'name' => config('app.name'),
                    'environment' => app()->environment(),
                    'debug' => (bool) config('app.debug'),
                    'url' => config('app.url'),
                    'timezone' => config('app.timezone'),
                    'maintenance' => app()->isDownForMaintenance(),
                ],
                'versions' => [
                    'php' => PHP_VERSION,
                    'laravel' => app()->version(),
                    'database' => $this->databaseVersion(),
                ],
                'drivers' => [
                    'cache' => config('cache.default'),
                    'queue' => config('queue.default'),
                    'session' => config('session.driver'),
                    'filesystem' => config('filesystems.default'),
                    'media_disk' => config('saka.media.disk', config('filesystems.default')),
                    'mail' => config('mail.default'),
                ],
                'queue' => $this->queueReport(),
                'storage' => $this->storageReport(),
            ],
        ]);
    }

    /**
     * Clear a cache group.
     *
     * Targeted rather than "clear everything": discarding the whole cache on a
     * warm production instance sends every subsequent request to the database
     * at once, which is a self-inflicted thundering herd. An administrator who
     * has just edited a category wants the taxonomy dropped, not the lot.
     */
    public function clearCache(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $validated = $request->validate([
            'target' => ['required', Rule::in(self::CACHE_TARGETS)],
        ]);

        $target = $validated['target'];

        match ($target) {
            'taxonomy' => CacheKeys::flushTaxonomy(),
            'content' => CacheKeys::flushContent(),
            'discovery' => CacheKeys::flushDiscovery(),
            'config' => $this->clearConfigCaches(),
            default => $this->clearApplicationCache(),
        };

        $this->audit->record('system.cache_cleared', $request->user(), null, [], ['target' => $target]);

        return response()->json([
            'data' => ['cleared' => $target, 'message' => "Cleared the {$target} cache."],
        ]);
    }

    /**
     * Put the platform into or out of maintenance mode.
     *
     * `APP_MAINTENANCE_DRIVER=cache` is what makes this safe across more than
     * one web node — the file driver would only take down the node that served
     * the request, leaving the platform half-up. That is asserted here rather
     * than assumed, because the failure is silent and extremely confusing.
     */
    public function maintenance(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:255'],
            'retry_after' => ['nullable', 'integer', 'min:10', 'max:86400'],
        ]);

        $enabled = (bool) $validated['enabled'];

        if ($enabled && config('app.maintenance.driver') === 'file' && $this->isMultiNode()) {
            throw ApiException::make(
                ErrorCode::Conflict,
                'Maintenance mode uses the file driver, which only affects the node handling this request. '.
                'Set APP_MAINTENANCE_DRIVER=cache before using this on a multi-node deployment.',
            );
        }

        try {
            if ($enabled) {
                Artisan::call('down', array_filter([
                    '--retry' => $validated['retry_after'] ?? 60,
                    // The bypass secret is NOT generated here: it would have to
                    // be returned in this response and would then live in
                    // browser history and any proxy log in between.
                ]));
            } else {
                Artisan::call('up');
            }
        } catch (Throwable $e) {
            throw ApiException::make(
                ErrorCode::ServerError,
                'Could not change maintenance mode: '.$e->getMessage(),
            );
        }

        $this->audit->record(
            $enabled ? 'system.maintenance_enabled' : 'system.maintenance_disabled',
            $request->user(),
            null,
            [],
            ['message' => $validated['message'] ?? null],
        );

        return response()->json([
            'data' => [
                'maintenance' => $enabled,
                'message' => $enabled
                    ? 'The platform is now in maintenance mode.'
                    : 'The platform is live again.',
            ],
        ]);
    }

    // ------------------------------------------------------------- internals

    private function clearApplicationCache(): void
    {
        CacheKeys::flushTaxonomy();
        CacheKeys::flushContent();
        CacheKeys::flushDiscovery();
        CacheKeys::flushLocations();
    }

    private function clearConfigCaches(): void
    {
        // Not `config:cache`: rebuilding the config from inside a request would
        // rebuild it from THIS request's environment, which is not necessarily
        // the deploy's. Clearing is safe; rebuilding belongs in the deploy.
        Artisan::call('config:clear');
        Artisan::call('route:clear');
        Artisan::call('permission:cache-reset');
    }

    /** @return array<string, mixed> */
    private function queueReport(): array
    {
        try {
            return [
                'connection' => config('queue.default'),
                'failed_jobs' => (int) DB::table('failed_jobs')->count(),
                'pending_jobs' => (int) DB::table('jobs')->count(),
                'failed_last_24h' => (int) DB::table('failed_jobs')
                    ->where('failed_at', '>=', now()->subDay())
                    ->count(),
            ];
        } catch (Throwable) {
            // The report is diagnostics; it must not 500 the settings page.
            return ['connection' => config('queue.default'), 'available' => false];
        }
    }

    /** @return array<string, mixed> */
    private function storageReport(): array
    {
        $disk = (string) config('saka.media.disk', 'public');

        $report = [
            'media_disk' => $disk,
            'driver' => config("filesystems.disks.{$disk}.driver"),
            'media_rows' => (int) DB::table('media')->count(),
            // From the media table, not by walking the disk: a recursive
            // listing of an S3 bucket to render a settings page is a bill.
            'tracked_bytes' => (int) DB::table('media')->sum('size_bytes'),
        ];

        // Free space is only meaningful for a local disk, and only there is it
        // cheap to ask.
        if ($report['driver'] === 'local') {
            try {
                $root = (string) config("filesystems.disks.{$disk}.root");
                $free = @disk_free_space($root);
                $total = @disk_total_space($root);

                if ($free !== false && $total !== false) {
                    $report['disk_free_bytes'] = (int) $free;
                    $report['disk_total_bytes'] = (int) $total;
                }
            } catch (Throwable) {
                // Reporting free space is not worth an error page.
            }
        }

        // An actual round trip. `exists()` on a directory tells you nothing
        // about whether the process can WRITE, which is the thing that breaks
        // uploads at 2am.
        try {
            $probe = '.saka-write-probe';
            Storage::disk($disk)->put($probe, (string) now()->timestamp);
            $report['writable'] = Storage::disk($disk)->exists($probe);
            Storage::disk($disk)->delete($probe);
        } catch (Throwable) {
            $report['writable'] = false;
        }

        return $report;
    }

    private function databaseVersion(): ?string
    {
        try {
            return (string) DB::selectOne('SELECT VERSION() as version')->version;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A cheap heuristic: more than one host has reported a scheduled run.
     *
     * Only used to decide whether to WARN about the file maintenance driver, so
     * a false negative costs a warning, not correctness.
     */
    private function isMultiNode(): bool
    {
        return (bool) config('saka.multi_node', false);
    }

    private function authorizeSettings(Request $request): void
    {
        if (! $request->user()?->hasPermission(Permission::SettingsManage)) {
            throw ApiException::forbidden();
        }
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        $this->authorizeSettings($request);
    }
}
