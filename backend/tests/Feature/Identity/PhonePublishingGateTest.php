<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Milestone 4 decision 5: browsing stays open to guests, but PUBLISHING a
 * listing requires a verified phone number.
 */
class PhonePublishingGateTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    #[Test]
    public function seller_with_verified_phone_may_publish(): void
    {
        $seller = User::where('email', 'seller@saka.test')->firstOrFail();

        $this->assertTrue($seller->hasVerifiedPhone());
        $this->assertTrue($seller->canPublishListings());
    }

    #[Test]
    public function seller_without_verified_phone_may_not_publish(): void
    {
        $seller = User::where('email', 'unverified@saka.test')->firstOrFail();

        $this->assertFalse($seller->hasVerifiedPhone());
        $this->assertFalse($seller->canPublishListings());
    }

    #[Test]
    public function verifying_the_phone_opens_the_gate(): void
    {
        $seller = User::where('email', 'unverified@saka.test')->firstOrFail();
        $this->assertFalse($seller->canPublishListings());

        $seller->forceFill(['phone_verified_at' => now()])->save();

        $this->assertTrue($seller->fresh()->canPublishListings());
    }

    #[Test]
    public function the_gate_can_be_disabled_by_configuration(): void
    {
        config()->set('saka.listings.require_phone_verification_to_publish', false);

        $seller = User::where('email', 'unverified@saka.test')->firstOrFail();

        $this->assertFalse($seller->hasVerifiedPhone());
        $this->assertTrue($seller->canPublishListings());
    }
}
