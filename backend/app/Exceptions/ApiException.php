<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Base for every deliberate, client-facing failure.
 *
 * Domain code throws these; the exception handler stays a thin mapper instead
 * of a growing switch statement. Anything NOT an ApiException is treated as an
 * unexpected server error and its details are never sent to the client.
 */
class ApiException extends Exception
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly ErrorCode $errorCode,
        ?string $message = null,
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message ?? $errorCode->message(), $errorCode->status(), $previous);
    }

    /** @param array<string, mixed> $details */
    public static function make(
        ErrorCode $code,
        ?string $message = null,
        array $details = [],
        ?Throwable $previous = null,
    ): self {
        return new self($code, $message, $details, $previous);
    }

    public function status(): int
    {
        return $this->errorCode->status();
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => array_filter([
                'code' => $this->errorCode->value,
                'message' => $this->getMessage(),
                'details' => $this->details ?: null,
                'request_id' => $request->attributes->get('request_id'),
            ], static fn ($value) => $value !== null),
        ], $this->status());
    }

    // ------------------------------------------------------------- convenience

    public static function unauthenticated(?string $message = null): self
    {
        return self::make(ErrorCode::Unauthenticated, $message);
    }

    public static function invalidCredentials(): self
    {
        return self::make(ErrorCode::InvalidCredentials);
    }

    public static function forbidden(?string $message = null): self
    {
        return self::make(ErrorCode::Forbidden, $message);
    }

    public static function notFound(?string $message = null): self
    {
        return self::make(ErrorCode::NotFound, $message);
    }

    public static function conflict(ErrorCode $code, ?string $message = null): self
    {
        return self::make($code, $message);
    }

    public static function phoneNotVerified(): self
    {
        return self::make(ErrorCode::PhoneNotVerified);
    }
}
