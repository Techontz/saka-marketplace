<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ErrorCode;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every ErrorCode maps to the HTTP status its name claims.
 *
 * This exists because of a real, self-inflicted bug: adding three new cases to
 * `ErrorCode::status()` inserted them INTO an existing multi-case arm, so
 * `Conflict`, `EmailAlreadyRegistered` and `PhoneAlreadyRegistered` silently
 * started returning 405 instead of 409. Nothing failed to compile, the enum was
 * still exhaustive, and only one unrelated assertion happened to notice.
 *
 * Grouped match arms make that mistake invisible on review, so the mapping is
 * pinned here instead — one explicit expectation per case. A new code has to be
 * added to this table, which is the moment to check its status is right.
 */
class ErrorCodeStatusTest extends TestCase
{
    /** @return array<string, int> */
    private const EXPECTED = [
        'VALIDATION_FAILED' => 422,
        'MALFORMED_REQUEST' => 400,
        'METHOD_NOT_ALLOWED' => 405,
        'PAYLOAD_TOO_LARGE' => 413,
        'UNSUPPORTED_MEDIA_TYPE' => 415,
        'INVALID_CREDENTIALS' => 401,
        'UNAUTHENTICATED' => 401,
        'TOKEN_EXPIRED' => 401,
        'INVALID_OAUTH_TOKEN' => 401,
        'FORBIDDEN' => 403,
        'ACCOUNT_SUSPENDED' => 403,
        'ACCOUNT_BANNED' => 403,
        'EMAIL_NOT_VERIFIED' => 403,
        'PHONE_NOT_VERIFIED' => 403,
        'NOT_FOUND' => 404,
        'CONFLICT' => 409,
        'EMAIL_ALREADY_REGISTERED' => 409,
        'PHONE_ALREADY_REGISTERED' => 409,
        'INVALID_STATE_TRANSITION' => 409,
        'OTP_INVALID' => 422,
        'OTP_EXPIRED' => 422,
        'OTP_MAX_ATTEMPTS' => 422,
        'PASSWORD_RESET_INVALID' => 422,
        'NO_PASSWORD_SET' => 422,
        'RATE_LIMITED' => 429,
        'SERVER_ERROR' => 500,
        'SERVICE_UNAVAILABLE' => 503,
    ];

    #[Test]
    public function every_error_code_maps_to_its_declared_status(): void
    {
        foreach (ErrorCode::cases() as $code) {
            $this->assertArrayHasKey(
                $code->value,
                self::EXPECTED,
                "[{$code->value}] is a new ErrorCode with no expected status. Add it here and check the mapping.",
            );

            $this->assertSame(
                self::EXPECTED[$code->value],
                $code->status(),
                "[{$code->value}] returns {$code->status()}, not the expected ".self::EXPECTED[$code->value].'. '.
                'Check whether it landed inside a grouped match arm.',
            );
        }
    }

    #[Test]
    public function every_error_code_has_a_message(): void
    {
        foreach (ErrorCode::cases() as $code) {
            $this->assertNotEmpty($code->message(), "[{$code->value}] has no default message.");
        }
    }
}
