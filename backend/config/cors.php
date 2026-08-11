<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
|
| Laravel's default is `allowed_origins => ['*']`, which lets ANY site call
| this API from a browser. That is fine for a public read-only API and wrong
| for one with authenticated write endpoints, so origins are enumerated here.
|
| `supports_credentials` stays FALSE: the API authenticates with a Bearer
| token, not a cookie. Turning it on would be required only for Sanctum's
| SPA cookie mode, and combining it with a permissive origin list is the
| classic CORS misconfiguration.
|
*/

$origins = array_values(array_filter([
    config('saka.frontend_url'),
    config('saka.admin_url'),
    ...array_filter(explode(',', (string) env('CORS_EXTRA_ORIGINS', ''))),
]));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins,

    // Preview/branch deploys, e.g. https://saka-git-abc.vercel.app
    'allowed_origins_patterns' => array_filter(explode(',', (string) env('CORS_ORIGIN_PATTERNS', ''))),

    'allowed_headers' => [
        'Accept', 'Authorization', 'Content-Type', 'X-Requested-With',
        'X-Request-Id', 'X-Session-Id',
    ],

    // So a browser client can read the correlation id and its rate-limit budget.
    'exposed_headers' => [
        'X-Request-Id', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Retry-After',
    ],

    'max_age' => 86400,

    'supports_credentials' => false,
];
