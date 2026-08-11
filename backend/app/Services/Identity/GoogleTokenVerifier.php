<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifies a Google ID token SERVER-SIDE.
 *
 * The client sends only the raw `id_token`; the profile is derived here from a
 * cryptographically verified JWT. Accepting a client-supplied email or name
 * would let anyone sign in as anyone.
 *
 * Verification is local (JWKS cached in Redis) rather than a call to Google's
 * tokeninfo endpoint on every login — that would put a third-party round trip
 * on the critical path of every sign-in.
 */
class GoogleTokenVerifier
{
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const CACHE_KEY = 'google:jwks';

    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    /**
     * @return array{provider_user_id: string, email: ?string, first_name: string, last_name: ?string, payload: array<string,mixed>}
     */
    public function verify(string $idToken): array
    {
        $clientId = config('services.google.client_id');

        if (empty($clientId)) {
            throw ApiException::make(
                ErrorCode::ServiceUnavailable,
                'Google sign-in is not configured.',
            );
        }

        try {
            $keys = JWK::parseKeySet($this->jwks());
            $claims = (array) JWT::decode($idToken, $keys);
        } catch (Throwable $e) {
            // Any signature/format failure is the same answer to the client.
            throw ApiException::make(ErrorCode::InvalidOAuthToken, previous: $e);
        }

        $this->assertClaims($claims, (string) $clientId);

        $name = (string) ($claims['name'] ?? '');
        [$firstName, $lastName] = $this->splitName(
            (string) ($claims['given_name'] ?? ''),
            (string) ($claims['family_name'] ?? ''),
            $name,
        );

        return [
            'provider_user_id' => (string) $claims['sub'],
            'email' => isset($claims['email']) ? (string) $claims['email'] : null,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'payload' => [
                'picture' => $claims['picture'] ?? null,
                'email_verified' => $claims['email_verified'] ?? null,
            ],
        ];
    }

    /** @param array<string, mixed> $claims */
    private function assertClaims(array $claims, string $clientId): void
    {
        $aud = (string) ($claims['aud'] ?? '');
        $iss = (string) ($claims['iss'] ?? '');

        // `aud` must be OUR client id — otherwise a token minted for a
        // different application would be accepted here.
        if (! hash_equals($clientId, $aud)) {
            throw ApiException::make(ErrorCode::InvalidOAuthToken);
        }

        if (! in_array($iss, self::ISSUERS, true)) {
            throw ApiException::make(ErrorCode::InvalidOAuthToken);
        }

        if (empty($claims['sub'])) {
            throw ApiException::make(ErrorCode::InvalidOAuthToken);
        }

        // Never link an account on an address Google itself has not verified.
        if (isset($claims['email']) && ($claims['email_verified'] ?? false) !== true) {
            throw ApiException::make(
                ErrorCode::InvalidOAuthToken,
                'The Google account email is not verified.',
            );
        }
    }

    /** @return array<string, mixed> */
    private function jwks(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHours(6), function (): array {
            $response = Http::timeout(5)->retry(2, 200)->get(self::JWKS_URL);

            if (! $response->successful()) {
                throw ApiException::make(
                    ErrorCode::ServiceUnavailable,
                    'Could not reach the Google sign-in service.',
                );
            }

            return $response->json();
        });
    }

    /** @return array{0: string, 1: ?string} */
    private function splitName(string $given, string $family, string $full): array
    {
        if ($given !== '') {
            return [$given, $family !== '' ? $family : null];
        }

        $parts = preg_split('/\s+/', trim($full)) ?: [];

        if ($parts === [] || $parts[0] === '') {
            return ['Google', null];
        }

        $first = array_shift($parts);

        return [$first, $parts === [] ? null : implode(' ', $parts)];
    }
}
