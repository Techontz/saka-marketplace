<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
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

    /** @return array{ok: bool, latency_ms: float, error: ?string} */
    private function check(callable $probe): array
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
            return [
                'ok' => false,
                'latency_ms' => round((microtime(true) - $start) * 1000, 2),
                // The class name only — an exception message can carry
                // credentials or host names.
                'error' => class_basename($e),
            ];
        }
    }
}
