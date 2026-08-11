<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Domain\Identity\Enums\OAuthProvider;
use App\Domain\Identity\Enums\RoleSlug;
use App\Domain\Identity\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Models\OAuthIdentity;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Registration and credential verification.
 *
 * Controllers stay thin: they validate shape, this owns the rules and the
 * transaction boundary.
 */
class AuthService
{
    /**
     * @param  array{first_name: string, last_name?: string|null, email: string, phone?: string|null, password: string}  $data
     */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? null,
                'email' => strtolower(trim($data['email'])),
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'status' => UserStatus::Active,
            ]);

            // Everyone starts as a buyer. The seller role is granted when they
            // first list something — there is no separate "seller signup".
            $user->assignRole(RoleSlug::Buyer->value);

            event(new Registered($user));

            return $user->fresh();
        });
    }

    /**
     * Verifies credentials and returns the user.
     *
     * Deliberately returns the SAME error for "no such account" and "wrong
     * password" — distinguishing them turns the login form into an account
     * enumeration oracle.
     */
    public function attemptLogin(string $email, string $password): User
    {
        $user = User::where('email', strtolower(trim($email)))->first();

        if ($user === null) {
            // Hash anyway so the response time does not reveal whether the
            // address exists.
            Hash::make($password);

            throw ApiException::invalidCredentials();
        }

        if ($user->password === null) {
            throw ApiException::make(ErrorCode::NoPasswordSet);
        }

        if (! Hash::check($password, $user->password)) {
            throw ApiException::invalidCredentials();
        }

        $this->assertCanAuthenticate($user);

        return $user;
    }

    /**
     * Resolves a social identity to a user, creating and linking one if needed.
     *
     * The caller MUST have already verified the provider token server-side —
     * this method trusts its input, so it must never be handed raw client data.
     *
     * @param  array{provider_user_id: string, email: ?string, first_name: string, last_name: ?string, payload?: array<string,mixed>}  $profile
     */
    public function resolveOAuthUser(OAuthProvider $provider, array $profile): User
    {
        return DB::transaction(function () use ($provider, $profile): User {
            $identity = OAuthIdentity::where('provider', $provider->value)
                ->where('provider_user_id', $profile['provider_user_id'])
                ->first();

            if ($identity !== null) {
                $user = $identity->user;
                $this->assertCanAuthenticate($user);

                return $user;
            }

            $email = $profile['email'] !== null ? strtolower(trim($profile['email'])) : null;

            // Only link by email when the local account has a VERIFIED address.
            // Linking to an unverified one would let anyone who registered that
            // address take over the social account.
            $user = $email !== null
                ? User::where('email', $email)->whereNotNull('email_verified_at')->first()
                : null;

            if ($user === null) {
                $user = User::create([
                    'first_name' => $profile['first_name'],
                    'last_name' => $profile['last_name'] ?? null,
                    'email' => $email ?? $this->placeholderEmail($provider, $profile['provider_user_id']),
                    // Providers assert the address; treat it as verified.
                    'email_verified_at' => $email !== null ? now() : null,
                    'password' => null,
                    'status' => UserStatus::Active,
                ]);

                $user->assignRole(RoleSlug::Buyer->value);
                event(new Registered($user));
            }

            $this->assertCanAuthenticate($user);

            OAuthIdentity::create([
                'user_id' => $user->id,
                'provider' => $provider->value,
                'provider_user_id' => $profile['provider_user_id'],
                'email' => $email,
                'payload' => $profile['payload'] ?? null,
            ]);

            return $user->fresh();
        });
    }

    public function recordLogin(User $user): void
    {
        $user->forceFill([
            'last_login_at' => now(),
            'last_seen_at' => now(),
        ])->saveQuietly();
    }

    private function assertCanAuthenticate(User $user): void
    {
        if ($user->status === UserStatus::Suspended) {
            throw ApiException::make(ErrorCode::AccountSuspended);
        }

        if ($user->status === UserStatus::Banned) {
            throw ApiException::make(ErrorCode::AccountBanned);
        }
    }

    /**
     * A stand-in address for a provider that returns none.
     *
     * DO NOT change this domain on a populated database. The result is stored
     * as the user's email, so the same Google account would synthesise a
     * different address on its next sign-in and create a duplicate user with an
     * orphaned history. It was safe to move to saka.africa only because the
     * production database was still empty at the cutover.
     */
    private function placeholderEmail(OAuthProvider $provider, string $providerUserId): string
    {
        return sprintf('%s_%s@users.noreply.saka.africa', $provider->value, $providerUserId);
    }
}
