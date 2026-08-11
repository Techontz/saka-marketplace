<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Structured access logging.
 *
 * Logs one line per API request with the correlation id, duration and status.
 * Query strings are recorded as KEY NAMES ONLY — a search query string carries
 * user intent and coordinates, and the path alone is enough to find the route.
 */
class LogSlowRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        $response = $next($request);

        $durationMs = (microtime(true) - $start) * 1000;
        $threshold = (int) config('saka.observability.slow_request_ms');

        $context = [
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => round($durationMs, 1),
            'user_id' => $request->user()?->getKey(),
            'query_keys' => array_keys($request->query()),
        ];

        if ($durationMs >= $threshold) {
            Log::warning('http.slow_request', $context);
        } elseif ($response->getStatusCode() >= 500) {
            Log::error('http.server_error', $context);
        }

        return $response;
    }
}
