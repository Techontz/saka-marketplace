<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Auth;

use App\Models\PhoneVerificationCode;
use App\Models\User;
use App\Notifications\PhoneOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The publishing gate (Milestone 4 decision 5) end to end over HTTP.
 */
class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function actingAsUnverifiedSeller(): User
    {
        $user = User::where('email', 'unverified@saka.test')->firstOrFail();
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    #[Test]
    public function requesting_a_code_stores_only_a_hash(): void
    {
        Notification::fake();
        $this->actingAsUnverifiedSeller();

        $this->postJson('/api/v1/auth/phone/request-otp', ['phone' => '0712345678'])
            ->assertAccepted()
            ->assertJsonPath('data.expires_in_minutes', (int) config('saka.otp.ttl_minutes'));

        $record = PhoneVerificationCode::where('phone', '+255712345678')->firstOrFail();

        // A database leak must not hand out live codes.
        $this->assertNotEmpty($record->code_hash);
        $this->assertTrue(strlen($record->code_hash) > 20);
        $this->assertNull($record->consumed_at);

        Notification::assertSentTo(
            User::where('email', 'unverified@saka.test')->firstOrFail(),
            PhoneOtpNotification::class,
        );
    }

    #[Test]
    public function local_and_international_phone_formats_normalise_to_one_value(): void
    {
        Notification::fake();
        $this->actingAsUnverifiedSeller();

        $this->postJson('/api/v1/auth/phone/request-otp', ['phone' => '0712 345 678'])->assertAccepted();
        $this->assertDatabaseHas('phone_verification_codes', ['phone' => '+255712345678']);
    }

    #[Test]
    public function verifying_a_correct_code_unlocks_publishing(): void
    {
        Notification::fake();
        $user = $this->actingAsUnverifiedSeller();

        $this->assertFalse($user->canPublishListings());

        // Plant a known code rather than scraping it from the notification.
        PhoneVerificationCode::create([
            'user_id' => $user->id,
            'phone' => '+255712345678',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/v1/auth/phone/verify-otp', [
            'phone' => '0712345678',
            'code' => '123456',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.phone_verified', true)
            ->assertJsonPath('data.user.can_publish_listings', true);

        $this->assertTrue($user->fresh()->canPublishListings());
        $this->assertNotNull(PhoneVerificationCode::where('phone', '+255712345678')->first()->consumed_at);
    }

    #[Test]
    public function an_incorrect_code_is_rejected_and_counted(): void
    {
        $user = $this->actingAsUnverifiedSeller();

        $record = PhoneVerificationCode::create([
            'user_id' => $user->id,
            'phone' => '+255712345678',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/v1/auth/phone/verify-otp', [
            'phone' => '+255712345678',
            'code' => '999999',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OTP_INVALID');

        $this->assertSame(1, $record->fresh()->attempts);
        $this->assertFalse($user->fresh()->hasVerifiedPhone());
    }

    #[Test]
    public function an_expired_code_is_rejected(): void
    {
        $user = $this->actingAsUnverifiedSeller();

        PhoneVerificationCode::create([
            'user_id' => $user->id,
            'phone' => '+255712345678',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/auth/phone/verify-otp', [
            'phone' => '+255712345678',
            'code' => '123456',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OTP_EXPIRED');
    }

    #[Test]
    public function brute_forcing_is_capped_by_the_attempt_limit(): void
    {
        $user = $this->actingAsUnverifiedSeller();

        // `attempts` is deliberately NOT fillable (it is a security counter),
        // so the fixture has to set it explicitly.
        PhoneVerificationCode::create([
            'user_id' => $user->id,
            'phone' => '+255712345678',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ])->forceFill(['attempts' => (int) config('saka.otp.max_attempts')])->save();

        $this->postJson('/api/v1/auth/phone/verify-otp', [
            'phone' => '+255712345678',
            'code' => '123456',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OTP_MAX_ATTEMPTS');
    }

    #[Test]
    public function requesting_a_new_code_invalidates_the_previous_one(): void
    {
        Notification::fake();
        $user = $this->actingAsUnverifiedSeller();

        $old = PhoneVerificationCode::create([
            'user_id' => $user->id,
            'phone' => '+255712345678',
            'code_hash' => Hash::make('111111'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/v1/auth/phone/request-otp', ['phone' => '+255712345678'])
            ->assertAccepted();

        $this->assertNotNull($old->fresh()->consumed_at);
    }

    #[Test]
    public function a_phone_already_verified_by_someone_else_is_refused(): void
    {
        $this->actingAsUnverifiedSeller();

        // seller@saka.test already owns +255700000003, verified.
        $this->postJson('/api/v1/auth/phone/request-otp', ['phone' => '+255700000003'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PHONE_ALREADY_REGISTERED');
    }

    #[Test]
    public function otp_endpoints_require_authentication(): void
    {
        $this->postJson('/api/v1/auth/phone/request-otp', ['phone' => '0712345678'])
            ->assertStatus(401);
    }
}
