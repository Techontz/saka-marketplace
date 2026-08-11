<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Seller;

use App\Domain\Identity\Enums\RoleSlug;
use App\Domain\Trust\Enums\VerificationStatus;
use App\Domain\Trust\Enums\VerificationType;
use App\Domain\Trust\IdentityNumber;
use App\Models\Listing;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\Identity\IdentityVerificationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * NIDA identity verification.
 *
 * A NIDA number identifies a Tanzanian for life and is never reissued, so the
 * tests that matter here are about CONTAINMENT:
 *
 *   - it is encrypted at rest, so a leaked backup leaks nothing;
 *   - it never appears on any public surface;
 *   - even its owner sees only the last four digits;
 *   - only a reviewer with `verification.review` sees it in full.
 *
 * Plus the honesty requirement: SAKA performs no automated check, and both
 * surfaces must say so rather than implying a robot approved anyone.
 */
class NidaVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** A real 20-digit NIDA-shaped number. */
    private const NIDA = '19900101123450000123';

    private function vendor(): User
    {
        return User::factory()->seller()->create();
    }

    private function submit(User $vendor, ?string $number = self::NIDA): TestResponse
    {
        $payload = [
            'type' => VerificationType::NationalId->value,
            'document' => UploadedFile::fake()->image('nida.jpg', 1200, 800),
        ];

        if ($number !== null) {
            $payload['document_number'] = $number;
        }

        return $this->actingAs($vendor)->post('/api/v1/seller/verifications', $payload);
    }

    // ------------------------------------------------------------ containment

    #[Test]
    public function the_nida_number_is_encrypted_at_rest(): void
    {
        $vendor = $this->vendor();
        $this->submit($vendor)->assertCreated();

        // Read past Eloquent, as a backup or a replica would.
        $raw = DB::table('verification_requests')
            ->where('user_id', $vendor->getKey())
            ->value('document_number');

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString(self::NIDA, (string) $raw);

        // And it round-trips through the model, so encryption is not one-way
        // by accident.
        $this->assertSame(
            self::NIDA,
            VerificationRequest::query()->where('user_id', $vendor->getKey())->value('document_number'),
        );
    }

    #[Test]
    public function the_owner_sees_only_the_last_four_digits(): void
    {
        $vendor = $this->vendor();
        $this->submit($vendor)->assertCreated();

        $body = $this->actingAs($vendor)->getJson('/api/v1/seller/verifications')->assertOk();

        /*
         * The vendor typed this number themselves. Redisplaying all twenty
         * digits puts them in the page source, the browser cache and any
         * screenshot of the dashboard to tell them nothing they did not know.
         */
        $body->assertJsonPath('data.0.document_number_masked', '•••• •••• •••• 0123');
        $this->assertStringNotContainsString(self::NIDA, json_encode($body->json(), JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function a_nida_number_never_reaches_any_public_surface(): void
    {
        $vendor = $this->vendor();
        $this->submit($vendor)->assertCreated();

        $profile = SellerProfile::query()->where('user_id', $vendor->getKey())->firstOrFail();
        $profile->forceFill(['onboarding_completed_at' => now(), 'is_verified' => true])->save();

        $listing = Listing::factory()->published()->ownedBy($vendor)->create();

        // Every public surface that renders anything about this vendor.
        $surfaces = [
            '/api/v1/businesses',
            "/api/v1/businesses/{$profile->slug}",
            '/api/v1/listings',
            "/api/v1/listings/{$listing->slug}",
        ];

        foreach ($surfaces as $surface) {
            $payload = json_encode($this->getJson($surface)->assertOk()->json(), JSON_THROW_ON_ERROR);

            $this->assertStringNotContainsString(self::NIDA, $payload, "NIDA leaked via {$surface}");
            // The masked form must not leak either: the last four digits of a
            // national ID are still identifying data a buyer has no claim to.
            $this->assertStringNotContainsString('document_number', $payload, "Identity field present on {$surface}");
        }
    }

    #[Test]
    public function a_vendor_cannot_read_another_vendors_verification(): void
    {
        $mine = $this->vendor();
        $theirs = $this->vendor();

        $this->submit($theirs)->assertCreated();

        // Scoped at the query, so another vendor's row never enters the result.
        $this->actingAs($mine)
            ->getJson('/api/v1/seller/verifications')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_reviewer_sees_the_full_number_and_a_masked_one(): void
    {
        $vendor = $this->vendor();
        $this->submit($vendor)->assertCreated();

        $admin = User::factory()->create()->syncRoles([RoleSlug::Admin->value]);

        $body = $this->actingAs($admin)->getJson('/api/v1/admin/verifications')->assertOk();

        // A reviewer compares this against the photograph — masking it would
        // make the queue unusable. This resource is behind verification.review
        // and has no public counterpart.
        $body->assertJsonPath('data.0.document_number', self::NIDA);
        $body->assertJsonPath('data.0.document_number_masked', '•••• •••• •••• 0123');
    }

    #[Test]
    public function a_signed_in_customer_cannot_reach_the_review_queue(): void
    {
        $this->submit($this->vendor())->assertCreated();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/admin/verifications')
            ->assertForbidden();
    }

    // ------------------------------------------------------------- validation

    #[Test]
    public function a_national_id_submission_requires_a_number(): void
    {
        // Without it a reviewer has only a photograph and nothing to check it
        // against, which is the entire manual process.
        $this->submit($this->vendor(), null)->assertStatus(422);
    }

    #[Test]
    public function a_malformed_nida_number_is_refused(): void
    {
        $this->submit($this->vendor(), '12345')->assertStatus(422);
    }

    #[Test]
    public function a_formatted_nida_number_is_normalised_to_digits(): void
    {
        $vendor = $this->vendor();

        // The form people quote it in, off the card itself.
        $this->submit($vendor, '19900101-12345-00001-23')->assertCreated();

        $this->assertSame(
            self::NIDA,
            VerificationRequest::query()->where('user_id', $vendor->getKey())->value('document_number'),
        );
    }

    // -------------------------------------------------------------- honesty

    #[Test]
    public function no_automated_verification_is_claimed(): void
    {
        $vendor = $this->vendor();
        $this->submit($vendor)->assertCreated();

        $meta = $this->actingAs($vendor)
            ->getJson('/api/v1/seller/verifications')
            ->assertOk()
            ->json('meta.automated_verification');

        /*
         * NIDA publishes no integration a marketplace can call, so every check
         * is a person reading a document. The API says so explicitly — a
         * verified badge means somebody looked, and the platform must not imply
         * otherwise.
         */
        $this->assertFalse($meta['available']);
        $this->assertSame('manual_review', $meta['provider']);

        $result = app(IdentityVerificationProvider::class)
            ->check(VerificationType::NationalId, self::NIDA);

        $this->assertSame('unavailable', $result->outcome);
        // "Unavailable" is not "failed". A vendor whose document could not be
        // machine-checked has done nothing wrong.
        $this->assertFalse($result->isConclusive());
    }

    #[Test]
    public function submitting_never_verifies_anybody_on_its_own(): void
    {
        $vendor = $this->vendor();
        $this->submit($vendor)->assertCreated();

        $profile = SellerProfile::query()->where('user_id', $vendor->getKey())->firstOrFail();

        // The badge is earned by review, never by submission.
        $this->assertFalse((bool) $profile->is_verified);
        $this->assertSame(
            VerificationStatus::Pending,
            VerificationRequest::query()->where('user_id', $vendor->getKey())->value('status'),
        );
    }

    #[Test]
    public function a_request_the_reviewer_has_queried_reads_as_needing_correction(): void
    {
        $vendor = $this->vendor();
        $this->submit($vendor)->assertCreated();

        $admin = User::factory()->create()->syncRoles([RoleSlug::Admin->value]);
        $verification = VerificationRequest::query()->where('user_id', $vendor->getKey())->firstOrFail();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/verifications/{$verification->uuid}/request-info", [
                'message' => 'The photograph is cut off on the right-hand edge.',
            ])
            ->assertOk();

        $row = $this->actingAs($vendor)
            ->getJson('/api/v1/seller/verifications')
            ->assertOk()
            ->json('data.0');

        /*
         * Derived, not a new column. `requestInformation` deliberately keeps
         * the request pending and actionable; pending WITH a reviewer note is
         * what "needs correction" is, and the vendor sees exactly what to fix.
         */
        $this->assertSame(VerificationStatus::Pending->value, $row['status']);
        $this->assertTrue($row['needs_correction']);
        $this->assertSame('The photograph is cut off on the right-hand edge.', $row['reviewer_note']);
    }

    #[Test]
    public function approval_raises_the_verification_level(): void
    {
        $vendor = $this->vendor();
        $this->submit($vendor)->assertCreated();

        $admin = User::factory()->create()->syncRoles([RoleSlug::Admin->value]);
        $verification = VerificationRequest::query()->where('user_id', $vendor->getKey())->firstOrFail();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/verifications/{$verification->uuid}/approve")
            ->assertOk();

        $profile = SellerProfile::query()->where('user_id', $vendor->getKey())->firstOrFail();

        $this->assertSame('id', $profile->verification_level->value);
    }

    #[Test]
    public function masking_never_reveals_a_short_value(): void
    {
        // Showing the last four of a six-digit string reveals most of it.
        $this->assertSame('••••••', IdentityNumber::mask('123456'));
        $this->assertSame('•••• •••• •••• 0123', IdentityNumber::mask(self::NIDA));
        $this->assertNull(IdentityNumber::mask(null));
    }
}
