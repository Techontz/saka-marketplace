<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Milestone 4 decision 5: publishing requires a verified phone number.
 *
 * Applied ONLY to publish-capable routes. Browsing, favouriting and inquiring
 * stay open — guests included.
 */
class EnsurePhoneIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->canPublishListings()) {
            throw ApiException::phoneNotVerified();
        }

        return $next($request);
    }
}
