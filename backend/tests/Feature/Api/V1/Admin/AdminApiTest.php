<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Trust\Enums\VerificationLevel;
use App\Domain\Trust\Enums\VerificationType;
use App\Models\Category;
use App\Models\Listing;
use App\Models\Page;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    // ---------------------------------------------------------------- users

    #[Test]
    public function an_admin_can_list_and_filter_users(): void
    {
        User::factory()->count(3)->seller()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/users?role=seller')
            ->assertOk()
            ->assertJsonStructure(['data' => [['uuid', 'email', 'status', 'roles', 'listings_count']]]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/users?verified_phone=0')
            ->assertOk();
    }

    #[Test]
    public function suspending_a_user_revokes_their_sessions_immediately(): void
    {
        $victim = User::factory()->buyer()->create();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $victim->email, 'password' => 'password',
        ])->json('data.token');

        $this->app['auth']->forgetGuards();

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/v1/admin/users/{$victim->uuid}/status", ['status' => 'suspended'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');

        // Waiting for the token to expire would leave a suspended user active.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    #[Test]
    public function an_admin_cannot_change_their_own_status_or_roles(): void
    {
        $admin = $this->admin();

        // Otherwise an organisation can lock itself out of its own platform.
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$admin->uuid}/status", ['status' => 'banned'])
            ->assertStatus(403);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$admin->uuid}/roles", ['roles' => ['buyer']])
            ->assertStatus(403);
    }

    #[Test]
    public function a_super_admin_cannot_be_modified_through_the_api(): void
    {
        $superAdmin = User::where('email', config('saka.seeding.admin_email'))->firstOrFail();

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/v1/admin/users/{$superAdmin->uuid}/status", ['status' => 'banned'])
            ->assertStatus(403);
    }

    #[Test]
    public function the_super_admin_role_cannot_be_granted(): void
    {
        $victim = User::factory()->buyer()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/v1/admin/users/{$victim->uuid}/roles", ['roles' => ['super_admin']])
            ->assertStatus(422);
    }

    #[Test]
    public function roles_can_be_assigned_and_replace_the_previous_set(): void
    {
        $user = User::factory()->buyer()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/v1/admin/users/{$user->uuid}/roles", ['roles' => ['buyer', 'moderator']])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasRole('moderator'));
        $this->assertTrue($fresh->hasRole('buyer'));
    }

    #[Test]
    public function a_moderator_cannot_manage_users(): void
    {
        $victim = User::factory()->buyer()->create();

        $this->actingAs(User::factory()->moderator()->create(), 'sanctum')
            ->patchJson("/api/v1/admin/users/{$victim->uuid}/status", ['status' => 'suspended'])
            ->assertStatus(403);
    }

    // -------------------------------------------------------- verification

    #[Test]
    public function approving_a_verification_raises_the_seller_level(): void
    {
        $seller = User::factory()->seller()->create();

        $request = VerificationRequest::create([
            'user_id' => $seller->id,
            'type' => VerificationType::NationalId,
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/verifications/{$request->uuid}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $profile = $seller->fresh()->sellerProfile;
        $this->assertTrue($profile->is_verified);
        $this->assertSame('id', $profile->verification_level->value);
    }

    #[Test]
    public function a_verification_cannot_be_reviewed_twice(): void
    {
        $seller = User::factory()->seller()->create();
        $request = VerificationRequest::create(['user_id' => $seller->id, 'type' => VerificationType::NationalId]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/verifications/{$request->uuid}/approve")->assertOk();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/verifications/{$request->uuid}/reject", ['reason' => 'Changed my mind here.'])
            ->assertStatus(409);
    }

    #[Test]
    public function approval_never_downgrades_an_existing_level(): void
    {
        $seller = User::factory()->seller()->create();
        $seller->sellerProfile->forceFill([
            'verification_level' => VerificationLevel::Business,
            'is_verified' => true,
        ])->save();

        $request = VerificationRequest::create(['user_id' => $seller->id, 'type' => VerificationType::Address]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/verifications/{$request->uuid}/approve")->assertOk();

        $this->assertSame('business', $seller->fresh()->sellerProfile->verification_level->value);
    }

    // ------------------------------------------------------------ taxonomy

    #[Test]
    public function an_admin_can_create_a_category_and_the_parent_stops_being_a_leaf(): void
    {
        $parentId = Category::where('slug', 'property')->value('id');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/categories', [
                'name' => 'Penthouses', 'parent_id' => $parentId, 'icon' => '🏙️',
            ])
            ->assertCreated()->assertJsonPath('data.name', 'Penthouses');

        $created = Category::where('slug', 'penthouses')->firstOrFail();
        $this->assertSame(1, $created->depth);
        $this->assertStringContainsString((string) $parentId, $created->path);
        $this->assertFalse(Category::find($parentId)->is_leaf);
    }

    #[Test]
    public function a_category_with_listings_cannot_be_deleted(): void
    {
        $category = Category::where('slug', 'property-apartments')->firstOrFail();
        Listing::factory()->inCategory('property-apartments')->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/admin/categories/{$category->slug}")
            ->assertStatus(409);
    }

    #[Test]
    public function taxonomy_writes_invalidate_the_public_cache(): void
    {
        // Warm the cache.
        $before = $this->getJson('/api/v1/categories')->assertOk()->json('data');
        // Counted, not hardcoded: this test is about INVALIDATION, and pinning
        // the catalogue size made it fail every time a vertical was seeded.
        $countBefore = count($before);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/categories', ['name' => 'Marine', 'icon' => '⛵'])
            ->assertCreated();

        $this->app['auth']->forgetGuards();

        // Previously TTL-only: an admin waited up to 24h to see their change.
        $after = $this->getJson('/api/v1/categories')->assertOk()->json('data');
        $this->assertCount($countBefore + 1, $after);
    }

    #[Test]
    public function an_attribute_can_be_created_with_options_and_bound_to_a_category(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/attributes', [
                'code' => 'roof_type', 'name' => 'Roof type',
                'input_type' => 'select', 'data_type' => 'string',
                'options' => [['label' => 'Tile'], ['label' => 'Iron sheet']],
            ])
            ->assertCreated()->assertJsonPath('data.code', 'roof_type');

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/categories/property-apartments/attributes', [
                'attributes' => [['code' => 'roof_type', 'is_required' => false]],
            ])->assertOk();

        $this->app['auth']->forgetGuards();

        $codes = array_column(
            $this->getJson('/api/v1/categories/property-apartments/attributes')->json('data'),
            'code',
        );
        $this->assertContains('roof_type', $codes);
    }

    #[Test]
    public function a_select_attribute_without_options_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/attributes', [
                'code' => 'broken', 'name' => 'Broken',
                'input_type' => 'select', 'data_type' => 'string',
            ])
            ->assertStatus(422)->assertJsonValidationErrors('options');
    }

    #[Test]
    public function an_attribute_code_cannot_be_changed_after_creation(): void
    {
        // The code is a public filter key; renaming it breaks saved searches.
        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/v1/admin/attributes/beds', ['code' => 'bedrooms'])
            ->assertStatus(422);
    }

    #[Test]
    public function amenities_can_be_created_and_appear_publicly(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/taxonomy/amenities', ['name' => 'Solar Power'])
            ->assertCreated()->assertJsonPath('data.slug', 'solar-power');

        $this->app['auth']->forgetGuards();

        $slugs = array_column($this->getJson('/api/v1/amenities')->json('data'), 'slug');
        $this->assertContains('solar-power', $slugs);
    }

    // ----------------------------------------------------------------- CMS

    #[Test]
    public function a_page_cannot_be_published_without_a_body(): void
    {
        $page = Page::where('slug', 'terms-and-conditions')->firstOrFail();
        $page->forceFill(['body' => null])->save();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->slug}/publish", ['published' => true])
            ->assertStatus(409);
    }

    #[Test]
    public function publishing_a_page_makes_it_publicly_readable(): void
    {
        $this->getJson('/api/v1/pages/terms-and-conditions')->assertStatus(404);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/pages/terms-and-conditions/publish', ['published' => true])
            ->assertOk()->assertJsonPath('data.is_published', true);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/pages/terms-and-conditions')->assertOk();
    }

    #[Test]
    public function faqs_can_be_managed_and_invalidate_the_public_cache(): void
    {
        $this->assertCount(5, $this->getJson('/api/v1/faqs')->json('data'));

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/faqs', [
                'question' => 'How do I verify my phone number?',
                'answer' => 'Open your account settings and request a code.',
            ])->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->assertCount(6, $this->getJson('/api/v1/faqs')->json('data'));
    }

    #[Test]
    public function platform_settings_are_super_admin_only(): void
    {
        // Feature flags and moderation toggles change how the whole platform
        // behaves, so they sit above the ordinary admin role.
        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/v1/admin/settings', [
                'settings' => [['key' => 'site.name', 'value' => 'Hijacked']],
            ])->assertStatus(403);
    }

    #[Test]
    public function settings_can_be_updated_but_visibility_cannot(): void
    {
        $superAdmin = User::where('email', config('saka.seeding.admin_email'))->firstOrFail();

        $this->actingAs($superAdmin, 'sanctum')
            ->patchJson('/api/v1/admin/settings', [
                'settings' => [
                    ['key' => 'site.name', 'value' => 'SAKA Marketplace'],
                    // is_public is deliberately not writable here.
                    ['key' => 'listings.require_moderation', 'value' => false],
                ],
            ])->assertOk();

        $this->app['auth']->forgetGuards();

        $public = $this->getJson('/api/v1/settings/public')->json('data');
        $this->assertSame('SAKA Marketplace', $public['site.name']);
        $this->assertArrayNotHasKey('listings.require_moderation', $public);
    }

    #[Test]
    public function the_whole_admin_surface_rejects_unauthenticated_callers(): void
    {
        $this->getJson('/api/v1/admin/users')->assertStatus(401);
        $this->getJson('/api/v1/admin/roles')->assertStatus(401);
        $this->getJson('/api/v1/admin/verifications')->assertStatus(401);
        $this->getJson('/api/v1/admin/settings')->assertStatus(401);
    }
}
