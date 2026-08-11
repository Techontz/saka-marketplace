<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Which upstream hops may set X-Forwarded-For. Laravel's TrustProxies
    | middleware is already first in the global stack and reads this key; until
    | this file existed it found nothing, so the header was ignored and
    | `$request->ip()` was always the immediate peer.
    |
    | That is not cosmetic. The three SAKA frontends never call this API from
    | the browser — each Next server proxies server-side to keep the token in an
    | httpOnly cookie — so with no trusted proxy every browser-originated
    | request arrives from ONE address. `auth-register` is 3 per hour keyed on
    | that address, which makes account creation 3 per hour FOR THE ENTIRE
    | MARKETPLACE, and records the proxy's IP on every audit row, listing view
    | and ad click.
    |
    | Blank is the safe default and preserves the old behaviour: no proxy is
    | trusted. Trusting everything ("*") lets any caller forge its own IP and
    | walk around every per-IP limiter in RateLimitServiceProvider, so use it
    | only where the API is genuinely unreachable except through the proxy.
    |
    | Accepts a comma-separated list, a CIDR block, or the literal "*".
    | Behind LiteSpeed/nginx on the same host that is "127.0.0.1".
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
