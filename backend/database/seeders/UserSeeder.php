<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Enums\RoleSlug;
use App\Domain\Identity\Enums\UserStatus;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Baseline accounts.
 *
 * Passwords come from env so no credential is hard-coded in the repository.
 * In a non-local environment the seeder REFUSES to run without them rather than
 * silently creating an account with a known password.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $isLocal = app()->environment('local', 'testing');

        $adminPassword = config('saka.seeding.admin_password') ?: ($isLocal ? 'password' : null);

        if ($adminPassword === null) {
            $this->command->warn('  SEED_ADMIN_PASSWORD not set outside local — skipping user seeding.');

            return;
        }

        $admin = User::updateOrCreate(
            ['email' => config('saka.seeding.admin_email')],
            [
                'first_name' => 'SAKA',
                'last_name' => 'Administrator',
                'password' => $adminPassword,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
                'phone' => '+255700000001',
                'phone_verified_at' => now(),
            ],
        );
        $this->assignRole($admin, RoleSlug::SuperAdmin);

        if (! $isLocal) {
            $this->command->info('  Seeded super admin only (non-local environment).');

            return;
        }

        // ---- local-only demo accounts ---------------------------------------

        $moderator = User::updateOrCreate(
            ['email' => 'moderator@saka.test'],
            [
                'first_name' => 'Amina',
                'last_name' => 'Moderator',
                'password' => 'password',
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
                'phone' => '+255700000002',
                'phone_verified_at' => now(),
            ],
        );
        $this->assignRole($moderator, RoleSlug::Moderator);

        $seller = User::updateOrCreate(
            ['email' => 'seller@saka.test'],
            [
                'first_name' => 'Juma',
                'last_name' => 'Mwenda',
                'password' => 'password',
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
                'phone' => '+255700000003',
                // Verified: this seller may publish (Milestone 4 decision 5).
                'phone_verified_at' => now(),
            ],
        );
        $this->assignRole($seller, RoleSlug::Buyer);
        $this->assignRole($seller, RoleSlug::Seller);

        SellerProfile::updateOrCreate(
            ['user_id' => $seller->id],
            [
                'display_name' => 'Juma Properties',
                'slug' => Str::slug('Juma Properties'),
                'bio' => 'Residential and commercial listings across Dar es Salaam.',
            ],
        );

        // Deliberately UNVERIFIED phone: exercises the publishing gate.
        $unverifiedSeller = User::updateOrCreate(
            ['email' => 'unverified@saka.test'],
            [
                'first_name' => 'Neema',
                'last_name' => 'Unverified',
                'password' => 'password',
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
                'phone' => '+255700000004',
                'phone_verified_at' => null,
            ],
        );
        $this->assignRole($unverifiedSeller, RoleSlug::Buyer);
        $this->assignRole($unverifiedSeller, RoleSlug::Seller);

        $buyer = User::updateOrCreate(
            ['email' => 'buyer@saka.test'],
            [
                'first_name' => 'Grace',
                'last_name' => 'Buyer',
                'password' => 'password',
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ],
        );
        $this->assignRole($buyer, RoleSlug::Buyer);

        $this->command->info('  Seeded 5 users (admin, moderator, seller, unverified seller, buyer).');
    }

    /** Delegates to spatie; assignRole is idempotent. */
    private function assignRole(User $user, RoleSlug $slug): void
    {
        $user->assignRole($slug->value);
    }
}
