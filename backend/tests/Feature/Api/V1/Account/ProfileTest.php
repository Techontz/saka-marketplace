<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function tokenFor(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'password',
        ])->json('data.token');
    }

    #[Test]
    public function the_account_surface_requires_authentication(): void
    {
        $this->getJson('/api/v1/account/profile')->assertStatus(401);
        $this->patchJson('/api/v1/account/profile', [])->assertStatus(401);
    }

    #[Test]
    public function a_user_can_read_and_update_their_profile(): void
    {
        $token = $this->tokenFor('buyer@saka.test');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/account/profile', [
                'first_name' => 'Gracey',
                'locale' => 'sw',
            ])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Gracey')
            ->assertJsonPath('data.locale', 'sw');
    }

    #[Test]
    public function changing_the_email_clears_its_verified_state(): void
    {
        $token = $this->tokenFor('buyer@saka.test');
        $this->assertNotNull(User::where('email', 'buyer@saka.test')->firstOrFail()->email_verified_at);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/account/profile', ['email' => 'grace.new@example.com'])
            ->assertOk()
            ->assertJsonPath('data.email', 'grace.new@example.com')
            ->assertJsonPath('data.email_verified', false);

        // Otherwise a user would inherit verification for an address they have
        // never proven they control.
        $this->assertNull(User::where('email', 'grace.new@example.com')->firstOrFail()->email_verified_at);
    }

    #[Test]
    public function an_email_already_taken_is_rejected(): void
    {
        $token = $this->tokenFor('buyer@saka.test');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/account/profile', ['email' => 'seller@saka.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    #[Test]
    public function changing_the_password_requires_the_current_one(): void
    {
        $token = $this->tokenFor('buyer@saka.test');

        // Guards against an attacker holding a stolen token locking the owner out.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/account/password', [
                'current_password' => 'not-the-password',
                'password' => 'NewPassword123',
                'password_confirmation' => 'NewPassword123',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');
    }

    #[Test]
    public function a_password_change_succeeds_and_keeps_the_current_session(): void
    {
        $token = $this->tokenFor('buyer@saka.test');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/account/password', [
                'current_password' => 'password',
                'password' => 'NewPassword123',
                'password_confirmation' => 'NewPassword123',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check(
            'NewPassword123',
            User::where('email', 'buyer@saka.test')->firstOrFail()->password,
        ));

        // The caller stays signed in; other devices do not.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')->assertOk();
    }

    #[Test]
    public function a_password_change_revokes_other_sessions(): void
    {
        $deviceA = $this->tokenFor('buyer@saka.test');
        $deviceB = $this->tokenFor('buyer@saka.test');

        $this->withHeader('Authorization', "Bearer {$deviceB}")
            ->patchJson('/api/v1/account/password', [
                'current_password' => 'password',
                'password' => 'NewPassword123',
                'password_confirmation' => 'NewPassword123',
            ])->assertOk();

        $this->withHeader('Authorization', "Bearer {$deviceA}")
            ->getJson('/api/v1/auth/me')->assertStatus(401);
    }
}
