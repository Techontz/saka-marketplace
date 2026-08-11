<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    #[Test]
    public function requesting_a_reset_sends_a_link(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'buyer@saka.test'])
            ->assertAccepted();

        Notification::assertSentTo(
            User::where('email', 'buyer@saka.test')->firstOrFail(),
            ResetPassword::class,
        );
    }

    #[Test]
    public function an_unknown_address_gets_the_identical_response(): void
    {
        Notification::fake();

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'buyer@saka.test']);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com']);

        // Account enumeration guard.
        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('data.message'), $unknown->json('data.message'));

        Notification::assertNothingSentTo(new User(['email' => 'nobody@example.com']));
    }

    #[Test]
    public function a_valid_token_resets_the_password_and_kills_every_session(): void
    {
        Notification::fake();
        $user = User::where('email', 'buyer@saka.test')->firstOrFail();

        // Establish an existing session first.
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'buyer@saka.test', 'password' => 'password',
        ])->json('data.token');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertAccepted();

        $resetToken = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$resetToken) {
            $resetToken = $notification->token;

            return true;
        });

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $resetToken,
            'email' => $user->email,
            'password' => 'BrandNewPass123',
            'password_confirmation' => 'BrandNewPass123',
        ])->assertOk();

        $this->assertTrue(Hash::check('BrandNewPass123', $user->fresh()->password));

        // A credential change must invalidate sessions an attacker may hold.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    #[Test]
    public function an_invalid_token_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'totally-made-up',
            'email' => 'buyer@saka.test',
            'password' => 'BrandNewPass123',
            'password_confirmation' => 'BrandNewPass123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PASSWORD_RESET_INVALID');
    }
}
