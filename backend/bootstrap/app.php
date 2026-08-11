<?php

declare(strict_types=1);

use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsurePhoneIsVerified;
use App\Http\Middleware\EnsureTokenAbility;
use App\Http\Middleware\LogSlowRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Trusted proxies are configured in `config/trustedproxy.php`, NOT
         * here. Laravel already puts its TrustProxies middleware at the front
         * of the global stack and falls back to that config file, and this
         * closure runs before the configuration bootstrapper — so a
         * `$middleware->trustProxies(at: env(...))` here silently ignores
         * anything set in `.env`. That was verified, not assumed.
         */

        // Global, not api-only: an unmatched route never enters the `api`
        // group, and the error envelope promises a request_id on EVERY failure.
        $middleware->prepend(AssignRequestId::class);

        // Applied to every response, including error and 404 paths.
        $middleware->append(SecurityHeaders::class);
        $middleware->api(append: [LogSlowRequests::class]);

        $middleware->alias([
            'active' => EnsureAccountIsActive::class,
            'phone.verified' => EnsurePhoneIsVerified::class,
            'token.ability' => EnsureTokenAbility::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * ONE envelope for every failure, so clients have exactly one shape to
         * handle:  { "error": { code, message, details?, request_id } }
         *
         * Validation additionally keeps Laravel's `errors` map alongside it.
         * Anything that is not an ApiException is treated as unexpected: the
         * client gets a generic message, the details go to the log.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $render = static function (
            Request $request,
            ErrorCode $code,
            ?string $message = null,
            array $details = [],
            ?array $errors = null,
        ) {
            $payload = ['error' => array_filter([
                'code' => $code->value,
                'message' => $message ?? $code->message(),
                'details' => $details ?: null,
                'request_id' => $request->attributes->get('request_id'),
            ], static fn ($v) => $v !== null)];

            if ($errors !== null) {
                $payload['errors'] = $errors;
            }

            return response()->json($payload, $code->status());
        };

        // Domain failures render themselves.
        $exceptions->render(fn (ApiException $e, Request $request) => $e->render($request));

        $exceptions->render(fn (ValidationException $e, Request $request) => $render(
            $request, ErrorCode::ValidationFailed, $e->getMessage(), [], $e->errors(),
        ));

        $exceptions->render(fn (AuthenticationException $e, Request $request) => $render(
            $request, ErrorCode::Unauthenticated,
        ));

        $exceptions->render(fn (AuthorizationException $e, Request $request) => $render(
            $request, ErrorCode::Forbidden,
        ));

        // 404 both for a missing model and for anything the actor may not see —
        // never disclose that a resource exists.
        $exceptions->render(fn (ModelNotFoundException $e, Request $request) => $render(
            $request, ErrorCode::NotFound,
        ));

        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $render(
            $request, ErrorCode::NotFound,
        ));

        // Preserve Retry-After and X-RateLimit-* — a 429 without them tells the
        // client nothing about when to try again.
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) use ($render) {
            return $render($request, ErrorCode::RateLimited)
                ->withHeaders($e->getHeaders());
        });

        /*
         * Any other HTTP exception keeps its status but adopts the envelope.
         *
         * The unmapped statuses matter more than they look. Falling through to
         * ServerError turns a 405 or a 413 — both entirely the CLIENT's doing —
         * into a 500, which tells the caller the platform is broken and puts a
         * spike on the server-error dashboard for what was a wrong verb or an
         * oversized upload. `default` is now reserved for statuses that really
         * are server-side.
         */
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($render) {
            $code = match ($e->getStatusCode()) {
                400 => ErrorCode::MalformedRequest,
                401 => ErrorCode::Unauthenticated,
                403 => ErrorCode::Forbidden,
                404 => ErrorCode::NotFound,
                405 => ErrorCode::MethodNotAllowed,
                409 => ErrorCode::Conflict,
                413 => ErrorCode::PayloadTooLarge,
                415 => ErrorCode::UnsupportedMediaType,
                422 => ErrorCode::ValidationFailed,
                429 => ErrorCode::RateLimited,
                503 => ErrorCode::ServiceUnavailable,
                default => ErrorCode::ServerError,
            };

            return $render($request, $code);
        });

        /*
         * The catch-all. MUST stay last — callbacks are tried in registration
         * order, so anything above still wins.
         *
         * Without this, an exception nobody anticipated escapes to Laravel's
         * own handler and the client receives a bare `{"message":"Server
         * Error"}` — no code, no request_id. The envelope above promises a
         * request_id on EVERY failure, and that promise was quietly false for
         * exactly the failures where correlating a report to a log line matters
         * most.
         *
         * This is not hypothetical: registration against an unseeded database
         * threw Spatie's RoleDoesNotExist, which is neither an ApiException nor
         * an HttpException. Every visitor saw the browser's generic fallback
         * string and there was no identifier to trace it with.
         *
         * The exception message is NEVER sent when debug is off — it can carry
         * table names, file paths and query fragments. Reporting is untouched,
         * so the real exception is still logged in full against the same id.
         */
        $exceptions->render(function (Throwable $e, Request $request) use ($render) {
            // Not ours to render: these carry a response of their own.
            if ($e instanceof HttpResponseException) {
                return null;
            }

            // Non-JSON callers keep Laravel's HTML error page.
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return $render($request, ErrorCode::ServerError, details: config('app.debug') === true
                ? ['exception' => $e::class, 'message' => $e->getMessage()]
                : []);
        });
    })
    ->create();
