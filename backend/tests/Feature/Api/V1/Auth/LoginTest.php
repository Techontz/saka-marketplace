<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Auth;

use App\Domain\Identity\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    #[Test]
    public function a_seller_can_sign_in(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'seller@saka.test',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'seller@saka.test')
            ->assertJsonPath('data.user.phone_verified', true)
            ->assertJsonPath('data.user.can_publish_listings', true)
            ->assertJsonStructure(['data' => ['token', 'expires_at']]);
    }

    #[Test]
    public function signing_in_records_the_login_timestamp(): void
    {
        $this->assertNull(User::where('email', 'buyer@saka.test')->firstOrFail()->last_login_at);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'buyer@saka.test',
            'password' => 'password',
        ])->assertOk();

        $this->assertNotNull(User::where('email', 'buyer@saka.test')->firstOrFail()->last_login_at);
    }

    #[Test]
    public function a_wrong_password_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'seller@saka.test',
            'password' => 'wrong-password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    #[Test]
    public function an_unknown_account_returns_the_identical_error_to_a_wrong_password(): void
    {
        // Account enumeration guard: the two cases must be indistinguishable.
        $unknown = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever123',
        ]);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => 'seller@saka.test',
            'password' => 'definitely-wrong',
        ]);

        $this->assertSame($unknown->status(), $wrongPassword->status());
        $this->assertSame($unknown->json('error.code'), $wrongPassword->json('error.code'));
        $this->assertSame($unknown->json('error.message'), $wrongPassword->json('error.message'));
    }

    #[Test]
    public function a_suspended_account_cannot_sign_in(): void
    {
        User::where('email', 'buyer@saka.test')->firstOrFail()
            ->forceFill(['status' => UserStatus::Suspended])->save();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'buyer@saka.test',
            'password' => 'password',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');
    }

    #[Test]
    public function a_banned_account_cannot_sign_in(): void
    {
        User::where('email', 'buyer@saka.test')->firstOrFail()
            ->forceFill(['status' => UserStatus::Banned])->save();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'buyer@saka.test',
            'password' => 'password',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_BANNED');
    }

    #[Test]
    public function me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    #[Test]
    public function an_authenticated_user_can_read_their_own_profile(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'seller@saka.test',
            'password' => 'password',
        ])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'seller@saka.test')
            // permissions are exposed only to the owner of the profile
            ->assertJsonStructure(['data' => ['permissions', 'roles']]);
    }

    #[Test]
    public function logging_out_revokes_the_token(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'buyer@saka.test',
            'password' => 'password',
        ])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/auth/logout')->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    #[Test]
    public function a_token_issued_before_a_ban_stops_working_immediately(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'buyer@saka.test',
            'password' => 'password',
        ])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')->assertOk();

        User::where('email', 'buyer@saka.test')->firstOrFail()
            ->forceFill(['status' => UserStatus::Banned])->save();

        // Checking only at login would leave this token valid until expiry.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_BANNED');
    }
}
