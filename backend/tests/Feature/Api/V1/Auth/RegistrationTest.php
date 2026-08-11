<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Auth;

use App\Domain\Identity\Enums\RoleSlug;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** @return array<string, string> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Asha',
            'last_name' => 'Mbwana',
            'email' => 'asha@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ], $overrides);
    }

    #[Test]
    public function a_visitor_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload());

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'user' => ['uuid', 'first_name', 'email', 'phone_verified', 'can_publish_listings', 'roles'],
                    'token', 'token_type', 'expires_at',
                ],
            ])
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'asha@example.com');

        $user = User::where('email', 'asha@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole(RoleSlug::Buyer->value));
    }

    #[Test]
    public function a_new_registrant_cannot_publish_until_the_phone_is_verified(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.user.phone_verified', false)
            ->assertJsonPath('data.user.can_publish_listings', false);
    }

    #[Test]
    public function the_response_never_includes_the_password_or_internal_id(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload());

        $user = $response->json('data.user');
        $this->assertArrayNotHasKey('password', $user);
        $this->assertArrayNotHasKey('id', $user);
        $this->assertArrayHasKey('uuid', $user);
    }

    #[Test]
    public function registration_rejects_a_duplicate_email(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors('email');
    }

    #[Test]
    public function registration_requires_a_confirmed_strong_password(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'password' => 'short',
            'password_confirmation' => 'nope',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');
    }

    #[Test]
    public function every_error_response_carries_the_standard_envelope(): void
    {
        $response = $this->postJson('/api/v1/auth/register', ['email' => 'not-an-email']);

        $response->assertStatus(422)
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id'], 'errors'])
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertNotEmpty($response->json('error.request_id'));
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }
}
