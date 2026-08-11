<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * Every machine-readable error code the API can emit.
 *
 * Clients switch on `error.code`, never on the human message — the message is
 * free to change or be translated; the code is a contract.
 */
enum ErrorCode: string
{
    // 400 / 422
    case ValidationFailed = 'VALIDATION_FAILED';
    case MalformedRequest = 'MALFORMED_REQUEST';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case PayloadTooLarge = 'PAYLOAD_TOO_LARGE';
    case UnsupportedMediaType = 'UNSUPPORTED_MEDIA_TYPE';

    // 401
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case Unauthenticated = 'UNAUTHENTICATED';
    case TokenExpired = 'TOKEN_EXPIRED';
    case InvalidOAuthToken = 'INVALID_OAUTH_TOKEN';

    // 403
    case Forbidden = 'FORBIDDEN';
    case AccountSuspended = 'ACCOUNT_SUSPENDED';
    case AccountBanned = 'ACCOUNT_BANNED';
    case EmailNotVerified = 'EMAIL_NOT_VERIFIED';
    case PhoneNotVerified = 'PHONE_NOT_VERIFIED';

    // 404
    case NotFound = 'NOT_FOUND';

    // 409
    case Conflict = 'CONFLICT';
    case EmailAlreadyRegistered = 'EMAIL_ALREADY_REGISTERED';
    case PhoneAlreadyRegistered = 'PHONE_ALREADY_REGISTERED';
    case InvalidStateTransition = 'INVALID_STATE_TRANSITION';

    // 422 (domain)
    case OtpInvalid = 'OTP_INVALID';
    case OtpExpired = 'OTP_EXPIRED';
    case OtpMaxAttempts = 'OTP_MAX_ATTEMPTS';
    case PasswordResetInvalid = 'PASSWORD_RESET_INVALID';
    case NoPasswordSet = 'NO_PASSWORD_SET';

    // 429
    case RateLimited = 'RATE_LIMITED';

    // 500 / 503
    case ServerError = 'SERVER_ERROR';
    case ServiceUnavailable = 'SERVICE_UNAVAILABLE';

    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed,
            self::OtpInvalid,
            self::OtpExpired,
            self::OtpMaxAttempts,
            self::PasswordResetInvalid,
            self::NoPasswordSet => Response::HTTP_UNPROCESSABLE_ENTITY,

            self::MalformedRequest => Response::HTTP_BAD_REQUEST,

            self::InvalidCredentials,
            self::Unauthenticated,
            self::TokenExpired,
            self::InvalidOAuthToken => Response::HTTP_UNAUTHORIZED,

            self::Forbidden,
            self::AccountSuspended,
            self::AccountBanned,
            self::EmailNotVerified,
            self::PhoneNotVerified => Response::HTTP_FORBIDDEN,

            self::NotFound => Response::HTTP_NOT_FOUND,

            self::MethodNotAllowed => Response::HTTP_METHOD_NOT_ALLOWED,
            self::PayloadTooLarge => Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            self::UnsupportedMediaType => Response::HTTP_UNSUPPORTED_MEDIA_TYPE,

            self::Conflict,
            self::EmailAlreadyRegistered,
            self::PhoneAlreadyRegistered,
            self::InvalidStateTransition => Response::HTTP_CONFLICT,

            self::RateLimited => Response::HTTP_TOO_MANY_REQUESTS,

            self::ServiceUnavailable => Response::HTTP_SERVICE_UNAVAILABLE,
            self::ServerError => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    /** Default human message. Overridable per throw site. */
    public function message(): string
    {
        return match ($this) {
            self::ValidationFailed => 'The given data was invalid.',
            self::MalformedRequest => 'The request could not be understood.',
            self::InvalidCredentials => 'These credentials do not match our records.',
            self::Unauthenticated => 'Authentication is required.',
            self::TokenExpired => 'Your session has expired. Please sign in again.',
            self::InvalidOAuthToken => 'The social sign-in token could not be verified.',
            self::Forbidden => 'You are not allowed to perform this action.',
            self::AccountSuspended => 'This account is suspended.',
            self::AccountBanned => 'This account has been banned.',
            self::EmailNotVerified => 'Please verify your email address first.',
            self::PhoneNotVerified => 'Please verify your phone number first.',
            self::NotFound => 'The requested resource was not found.',
            self::Conflict => 'The request conflicts with the current state.',
            self::EmailAlreadyRegistered => 'An account with this email already exists.',
            self::PhoneAlreadyRegistered => 'An account with this phone number already exists.',
            self::MethodNotAllowed => 'That method is not supported for this endpoint.',
            self::PayloadTooLarge => 'That upload is too large.',
            self::UnsupportedMediaType => 'That content type is not supported.',
            self::InvalidStateTransition => 'That change is not allowed from the current state.',
            self::OtpInvalid => 'That verification code is not correct.',
            self::OtpExpired => 'That verification code has expired.',
            self::OtpMaxAttempts => 'Too many incorrect attempts. Request a new code.',
            self::PasswordResetInvalid => 'This password reset link is invalid or has expired.',
            self::NoPasswordSet => 'This account uses social sign-in and has no password.',
            self::RateLimited => 'Too many requests. Please slow down.',
            self::ServiceUnavailable => 'The service is temporarily unavailable.',
            self::ServerError => 'Something went wrong on our end.',
        };
    }
}
