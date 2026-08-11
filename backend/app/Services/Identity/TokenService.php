<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Issues Sanctum session tokens.
 *
 * CORRECTION to a Milestone 6 claim: these tokens are issued with `*` and
 * authorization of record is the POLICY layer, which reads current roles from
 * the database on every request.
 *
 * Encoding the permission list as token abilities was tried and removed. It
 * added no security — a stolen token belonging to a seller carries seller
 * abilities either way, and a stolen token belonging to a buyer is already
 * refused by the policies — while introducing a real trap: ListingService
 * promotes a buyer to seller on their first listing, so their existing token
 * would lack the new abilities and they would be locked out of publishing
 * until they re-authenticated.
 *
 * Scoped abilities are the right tool for USER-GENERATED API keys (v1.1+),
 * where the user deliberately narrows what a key may do. `EnsureTokenAbility`
 * exists for that; it is intentionally applied to nothing today.
 */
class TokenService
{
    public function issue(User $user, string $deviceName = 'api'): NewAccessToken
    {
        return $user->createToken(
            name: $this->normaliseDeviceName($deviceName),
            abilities: $this->abilitiesFor($user),
            expiresAt: $this->expiry(),
        );
    }

    /**
     * Revokes the token currently authenticating the request.
     * Falls back to nothing when called outside an authenticated context.
     */
    public function revokeCurrent(User $user): void
    {
        $token = $user->currentAccessToken();

        // TransientToken (used by actingAs) has no row to delete.
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }

    public function revokeAll(User $user): void
    {
        $user->tokens()->delete();
    }

    /**
     * Rotates the current token: issues a new one, then revokes the old.
     * Order matters — if issuing fails the caller is not left unauthenticated.
     */
    public function refresh(User $user, string $deviceName = 'api'): NewAccessToken
    {
        $new = $this->issue($user, $deviceName);
        $this->revokeCurrent($user);

        return $new;
    }

    public function expiry(): Carbon
    {
        return now()->addMinutes((int) config('saka.auth.token_ttl_minutes'));
    }

    /**
     * Session tokens are unscoped; the policy layer decides everything.
     * See the class docblock for why permission-derived abilities were removed.
     *
     * @return array<int, string>
     */
    private function abilitiesFor(User $user): array
    {
        return ['*'];
    }

    private function normaliseDeviceName(string $deviceName): string
    {
        $clean = trim(strip_tags($deviceName));

        return $clean === '' ? 'api' : mb_substr($clean, 0, 100);
    }
}
