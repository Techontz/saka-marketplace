<?php

namespace Database\Factories;

use App\Domain\Identity\Enums\RoleSlug;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /** Define the model's default state. */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'uuid' => (string) Str::uuid7(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** Phone verified — required before a seller may publish. */
    public function withVerifiedPhone(): static
    {
        return $this->state(fn () => [
            'phone' => '+2557'.fake()->unique()->numerify('########'),
            'phone_verified_at' => now(),
        ]);
    }

    /**
     * A seller who can create AND publish.
     *
     * Also creates the SellerProfile, because in production ListingService
     * creates one before a seller can own a listing — a factory that skipped it
     * would let tests pass against a state that cannot occur.
     */
    public function seller(): static
    {
        return $this->withVerifiedPhone()->afterCreating(function (User $user): void {
            $user->assignRole(RoleSlug::Buyer->value);
            $user->assignRole(RoleSlug::Seller->value);

            SellerProfile::firstOrCreate(
                ['user_id' => $user->getKey()],
                [
                    'display_name' => $user->fullName(),
                    'slug' => Str::slug($user->fullName()).'-'.Str::lower(Str::random(5)),
                ],
            );
        });
    }

    public function buyer(): static
    {
        return $this->afterCreating(function (User $user): void {
            $user->assignRole(RoleSlug::Buyer->value);
        });
    }

    public function moderator(): static
    {
        return $this->afterCreating(function (User $user): void {
            $user->assignRole(RoleSlug::Moderator->value);
        });
    }
}
