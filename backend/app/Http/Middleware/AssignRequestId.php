<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation id for every request.
 *
 * Accepts an inbound X-Request-Id from the edge (so a CDN/load-balancer trace
 * stays joined up) and otherwise mints one. It is attached to the request, put
 * into the log context, echoed on the response and included in every error
 * envelope — so a user reporting "it failed" can be traced end to end.
 */
class AssignRequestId
{
    private const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $inbound = $request->headers->get(self::HEADER);

        // Only trust an inbound value that looks like an id — never echo
        // arbitrary client input into logs and response headers.
        $requestId = is_string($inbound) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $inbound) === 1
            ? $inbound
            : (string) Str::ulid();

        $request->attributes->set('request_id', $requestId);
        Log::shareContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
