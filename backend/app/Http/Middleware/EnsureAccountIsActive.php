<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks suspended and banned accounts on every authenticated request.
 *
 * Checking only at login is not enough — a token issued before a ban would
 * otherwise stay valid until it expired.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($user->status === UserStatus::Suspended) {
            throw ApiException::make(ErrorCode::AccountSuspended);
        }

        if ($user->status === UserStatus::Banned) {
            throw ApiException::make(ErrorCode::AccountBanned);
        }

        return $next($request);
    }
}
