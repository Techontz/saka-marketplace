<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared-secret gate for the metrics scrape endpoint.
 *
 * Metrics expose inventory volume and operational state, so this is not public.
 * hash_equals() to avoid a timing oracle on the token. When no token is
 * configured the endpoint is refused outright rather than left open — failing
 * closed is the only safe default for an unauthenticated route.
 */
class RequireMetricsToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('saka.observability.metrics_token');
        $provided = (string) $request->header('X-Metrics-Token', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            throw ApiException::notFound();
        }

        return $next($request);
    }
}
