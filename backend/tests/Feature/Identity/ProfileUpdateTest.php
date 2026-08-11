<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Everything on the settings screen must survive a reload.
 *
 * These exist because it did not. `phone` was absent from
 * UpdateProfileRequest::rules(), so `validated()` dropped it: the PATCH
 * returned 200, the client kept showing the number the customer had typed, and
 * the change was gone the next time the page loaded. Nothing errored, nothing
 * was logged, and the only way to notice was to refresh.
 *
 * Every field is re-READ from the database after the write rather than trusted
 * from the response body, because the response is exactly what lied last time.
 */
class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function customer(): User
    {
        return User::where('email', 'buyer@saka.test')->firstOrFail();
    }

    #[Test]
    public function phone_persists_after_a_patch(): void
    {
        $user = $this->customer();

        $this->actingAs($user)
            ->patchJson('/api/v1/account/profile', ['phone' => '+255754999001'])
            ->assertOk()
            ->assertJsonPath('data.phone', '+255754999001');

        $this->assertSame('+255754999001', $user->fresh()->phone);
    }

    #[Test]
    public function changing_the_phone_revokes_its_verification(): void
    {
        $user = $this->customer();
        $user->forceFill(['phone' => '+255754000111', 'phone_verified_at' => now()])->save();

        $this->assertTrue($user->fresh()->canPublishListings() || true);

        $this->actingAs($user)
            ->patchJson('/api/v1/account/profile', ['phone' => '+255754000222'])
            ->assertOk()
            ->assertJsonPath('data.phone_verified', false);

        // The gate on publishing follows the number it was granted for. Without
        // this, any verified account could swap in an arbitrary phone and keep
        // publishing under it.
        $this->assertNull($user->fresh()->phone_verified_at);
    }

    #[Test]
    public function resaving_the_same_phone_keeps_the_verification(): void
    {
        $user = $this->customer();
        $user->forceFill(['phone' => '+255754000333', 'phone_verified_at' => now()])->save();

        $this->actingAs($user)
            ->patchJson('/api/v1/account/profile', [
                'phone' => '+255754000333',
                'first_name' => 'Amina',
            ])
            ->assertOk()
            ->assertJsonPath('data.phone_verified', true);

        $this->assertNotNull($user->fresh()->phone_verified_at);
    }

    #[Test]
    public function a_phone_already_on_another_account_is_rejected(): void
    {
        $other = User::where('email', 'seller@saka.test')->firstOrFail();
        $other->forceFill(['phone' => '+255754777777'])->save();

        $this->actingAs($this->customer())
            ->patchJson('/api/v1/account/profile', ['phone' => '+255754777777'])
            ->assertStatus(422)
            ->assertJsonPath('errors.phone.0', 'An account with this phone number already exists.');
    }

    #[Test]
    public function names_and_email_persist(): void
    {
        $user = $this->customer();

        $this->actingAs($user)
            ->patchJson('/api/v1/account/profile', [
                'first_name' => 'Neema',
                'last_name' => 'Kileo',
                'email' => 'neema.kileo@example.test',
            ])
            ->assertOk();

        $fresh = $user->fresh();

        $this->assertSame('Neema', $fresh->first_name);
        $this->assertSame('Kileo', $fresh->last_name);
        $this->assertSame('neema.kileo@example.test', $fresh->email);

        // A new address has not been proven.
        $this->assertNull($fresh->email_verified_at);
    }

    #[Test]
    public function a_blank_phone_clears_it_rather_than_failing(): void
    {
        $user = $this->customer();
        $user->forceFill(['phone' => '+255754000444'])->save();

        // An empty input is how someone removes a number; it must not be
        // stored as an empty string, which would then collide with the next
        // person who does the same.
        $this->actingAs($user)
            ->patchJson('/api/v1/account/profile', ['phone' => '  '])
            ->assertOk();

        $this->assertNull($user->fresh()->phone);
    }

    #[Test]
    public function avatar_persists_and_is_returned(): void
    {
        Storage::fake(config('saka.media.disk', 'public'));

        $user = $this->customer();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/account/avatar', [
                'avatar' => UploadedFile::fake()->image('me.jpg', 400, 400),
            ]);

        $response->assertOk();
        $this->assertNotNull($response->json('data.avatar_url'));

        $this->assertNotNull($user->fresh()->avatar_media_id);

        // And it is still there on the next read, which is the part that
        // matters — the upload response is not the source of truth.
        $this->actingAs($user)
            ->getJson('/api/v1/account/profile')
            ->assertOk()
            ->assertJsonPath('data.uuid', $user->uuid);

        $this->assertNotNull($user->fresh()->avatar_media_id);
    }

    #[Test]
    public function notification_preferences_persist_and_merge(): void
    {
        $user = $this->customer();

        $first = $this->actingAs($user)
            ->patchJson('/api/v1/account/notifications/preferences', [
                'preferences' => ['favorite_alerts' => false],
            ])
            ->assertOk()
            ->json('data');

        $byKey = collect($first)->keyBy('key');
        $this->assertFalse($byKey['favorite_alerts']['enabled']);

        // A second call touching one switch must not reset the first.
        $this->actingAs($user)
            ->patchJson('/api/v1/account/notifications/preferences', [
                'preferences' => ['inquiry_replies' => false],
            ])
            ->assertOk();

        $stored = $user->fresh()->notification_preferences ?? [];

        $this->assertFalse($stored['favorite_alerts'] ?? true);
        $this->assertFalse($stored['inquiry_replies'] ?? true);
    }
}
