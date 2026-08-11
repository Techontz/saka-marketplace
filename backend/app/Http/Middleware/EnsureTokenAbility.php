<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects a request whose token lacks the required ability.
 *
 * Reserved for USER-GENERATED API keys (v1.1+), where a user deliberately
 * issues a narrowed key. First-party session tokens are unscoped (`*`) and are
 * authorized by policies instead — see TokenService for why.
 *
 * Registered but deliberately unused: it exists so scoped keys are a routing
 * change rather than a redesign.
 */
class EnsureTokenAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token === null) {
            throw ApiException::unauthenticated();
        }

        foreach ($abilities as $ability) {
            if ($token->can($ability)) {
                return $next($request);
            }
        }

        throw ApiException::forbidden('This token is not permitted to perform that action.');
    }
}
