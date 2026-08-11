<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Seller;

use App\Domain\Identity\Enums\SocialNetwork;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Vendor social profiles.
 *
 * Stored in the EXISTING `seller_profiles.social_links` JSON column — no new
 * table. What is new is that the values are now normalised and host-checked
 * rather than accepted as any http(s) string.
 *
 * The property that matters most: an icon is a CLAIM about where a link goes,
 * so a link filed under "instagram" must actually be Instagram.
 */
class VendorSocialLinksTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function vendor(): User
    {
        return User::factory()->seller()->create();
    }

    private function saveLinks(User $vendor, array $links): TestResponse
    {
        return $this->actingAs($vendor)
            ->patchJson('/api/v1/seller/vendor-profile', ['social_links' => $links]);
    }

    private function stored(User $vendor): array
    {
        return (array) SellerProfile::query()
            ->where('user_id', $vendor->getKey())
            ->value('social_links');
    }

    #[Test]
    public function a_full_url_is_kept_and_canonicalised(): void
    {
        $vendor = $this->vendor();

        $this->saveLinks($vendor, [
            'instagram' => 'https://www.instagram.com/kilimani.properties/',
        ])->assertOk();

        // `www.` dropped, trailing slash removed — so the same profile entered
        // two different ways is stored once, one way.
        $this->assertSame(
            ['instagram' => 'https://instagram.com/kilimani.properties'],
            $this->stored($vendor),
        );
    }

    #[Test]
    public function a_bare_handle_is_expanded(): void
    {
        $vendor = $this->vendor();

        // What a vendor actually types when asked for "your Instagram".
        $this->saveLinks($vendor, ['instagram' => '@kilimani.properties'])->assertOk();

        $this->assertSame(
            ['instagram' => 'https://instagram.com/kilimani.properties'],
            $this->stored($vendor),
        );
    }

    #[Test]
    public function a_scheme_less_url_is_qualified(): void
    {
        $vendor = $this->vendor();

        /*
         * Copied out of a browser address bar. Stored as typed it would render
         * as a RELATIVE link and navigate to saka.africa/instagram.com/… —
         * a broken link that looks like a working one.
         */
        $this->saveLinks($vendor, ['instagram' => 'instagram.com/kilimani'])->assertOk();

        $this->assertSame(['instagram' => 'https://instagram.com/kilimani'], $this->stored($vendor));
    }

    #[Test]
    public function a_link_on_the_wrong_host_is_refused(): void
    {
        $vendor = $this->vendor();

        // A perfectly well-formed https URL that `url:http,https` would have
        // accepted — and which the profile would then render behind the
        // Instagram glyph, lending SAKA's credibility to it.
        $this->saveLinks($vendor, ['instagram' => 'https://evil.example/phishing'])->assertOk();

        $this->assertSame([], $this->stored($vendor));
    }

    #[Test]
    public function a_blank_value_is_removed_rather_than_stored_empty(): void
    {
        $vendor = $this->vendor();

        $this->saveLinks($vendor, ['instagram' => 'https://instagram.com/kilimani'])->assertOk();
        $this->saveLinks($vendor, ['instagram' => ''])->assertOk();

        // `{"instagram": ""}` would render an Instagram icon linking nowhere —
        // the empty-icon problem the public profile must never have.
        $this->assertSame([], $this->stored($vendor));
    }

    #[Test]
    public function credentials_and_fragments_are_stripped(): void
    {
        $vendor = $this->vendor();

        $this->saveLinks($vendor, [
            'facebook' => 'https://user:secret@facebook.com/kilimani#about',
        ])->assertOk();

        $stored = $this->stored($vendor);

        $this->assertSame('https://facebook.com/kilimani', $stored['facebook']);
        $this->assertStringNotContainsString('secret', $stored['facebook']);
    }

    #[Test]
    public function an_unknown_network_is_dropped(): void
    {
        $vendor = $this->vendor();

        // Every supported network is a rendered icon. One nobody drew would be
        // a blank square on the public profile.
        $this->saveLinks($vendor, [
            'myspace' => 'https://myspace.com/kilimani',
            'instagram' => 'https://instagram.com/kilimani',
        ])->assertOk();

        $this->assertSame(['instagram' => 'https://instagram.com/kilimani'], $this->stored($vendor));
    }

    #[Test]
    public function legacy_twitter_links_are_accepted_for_x(): void
    {
        $vendor = $this->vendor();

        // Refusing twitter.com would reject a link that genuinely works.
        $this->saveLinks($vendor, ['x' => 'https://twitter.com/kilimani'])->assertOk();

        $this->assertSame(['x' => 'https://twitter.com/kilimani'], $this->stored($vendor));
    }

    #[Test]
    public function sending_an_empty_map_clears_every_link(): void
    {
        $vendor = $this->vendor();

        $this->saveLinks($vendor, [
            'instagram' => 'https://instagram.com/kilimani',
            'facebook' => 'https://facebook.com/kilimani',
        ])->assertOk();

        // Removal has to be expressible. Treating `{}` as "no change" would
        // make it impossible to take a link down.
        $this->saveLinks($vendor, [])->assertOk();

        $this->assertSame([], $this->stored($vendor));
    }

    #[Test]
    public function the_public_business_page_shows_only_populated_links(): void
    {
        $vendor = $this->vendor();

        $profile = SellerProfile::query()->where('user_id', $vendor->getKey())->firstOrFail();
        $profile->forceFill([
            'onboarding_completed_at' => now(),
            // Written straight to the column, as a row predating validation
            // would be: a blank, a bare handle, and a wrong-host link.
            'social_links' => [
                'instagram' => 'https://instagram.com/kilimani',
                'facebook' => '',
                'tiktok' => '@kilimani',
                'linkedin' => 'https://evil.example/x',
            ],
        ])->save();

        $links = $this->getJson("/api/v1/businesses/{$profile->slug}")
            ->assertOk()
            ->json('data.social_links');

        // Cleaned on read, so no backfill is needed before the page is safe.
        $this->assertSame([
            'instagram' => 'https://instagram.com/kilimani',
            'tiktok' => 'https://tiktok.com/@kilimani',
        ], $links);
    }

    #[Test]
    public function the_supported_networks_are_published_for_the_portal(): void
    {
        $networks = $this->getJson('/api/v1/business-types')
            ->assertOk()
            ->json('meta.social_networks');

        // The portal renders exactly what the API will accept rather than
        // carrying its own copy that drifts when one is added.
        $this->assertCount(count(SocialNetwork::cases()), $networks);
        $this->assertContains('instagram', array_column($networks, 'value'));
    }
}
