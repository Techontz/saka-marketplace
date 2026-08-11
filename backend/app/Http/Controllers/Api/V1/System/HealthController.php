<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Health endpoints.
 *
 * Two distinct checks, because load balancers need to tell them apart:
 *
 *  - /health/live  LIVENESS. Is the process up? Never touches a dependency —
 *                  a failing database must not make the orchestrator kill and
 *                  restart every healthy app container.
 *  - /health/ready READINESS. Can this instance serve traffic? Checks the
 *                  dependencies it genuinely cannot work without.
 *
 * Conflating them is how a brief database blip turns into a full outage.
 */
class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json([
            'data' => [
                'status' => 'alive',
                'service' => config('app.name'),
                'version' => 'v1',
                'time' => now()->toAtomString(),
            ],
        ]);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('SELECT 1')),
            'cache' => $this->check(function (): void {
                Cache::put('health:probe', 1, 5);
                Cache::get('health:probe');
            }),
            'redis' => $this->check(fn () => Redis::connection()->ping()),
            'queue' => $this->check(fn () => Redis::connection('default')->connect ?? true),

            /*
             * Reachable is not the same as usable.
             *
             * A migrated but unseeded database passes every check above and
             * then fails every registration: AuthService assigns the `buyer`
             * role to each new account, and with no roles that throws. This
             * endpoint reported "ready" throughout, which is how a deployment
             * where nobody could create an account looked healthy.
             *
             * Roles specifically, not a general "is it seeded" heuristic —
             * they are the one row set the write path cannot run without.
             */
            'reference_data' => $this->check(
                function (): void {
                    if (! DB::table('roles')->exists()) {
                        throw new RuntimeException('roles table is empty');
                    }
                },
                hint: 'No roles — run `php artisan db:seed --force`.',
            ),
        ];

        $healthy = ! in_array(false, array_column($checks, 'ok'), true);

        return response()->json([
            'data' => [
                'status' => $healthy ? 'ready' : 'degraded',
                'checks' => $checks,
                'time' => now()->toAtomString(),
            ],
        ], $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }

    /**
     * @param  string|null  $hint  A static, author-written remediation line.
     *                             Never derived from the exception, which is
     *                             why it is safe to return where the message
     *                             is not.
     * @return array{ok: bool, latency_ms: float, error: ?string, hint?: string}
     */
    private function check(callable $probe, ?string $hint = null): array
    {
        $start = microtime(true);

        try {
            $probe();

            return [
                'ok' => true,
                'latency_ms' => round((microtime(true) - $start) * 1000, 2),
                'error' => null,
            ];
        } catch (Throwable $e) {
            return array_filter([
                'ok' => false,
                'latency_ms' => round((microtime(true) - $start) * 1000, 2),
                // The class name only — an exception message can carry
                // credentials or host names.
                'error' => class_basename($e),
                'hint' => $hint,
            ], static fn ($value) => $value !== null);
        }
    }
}
