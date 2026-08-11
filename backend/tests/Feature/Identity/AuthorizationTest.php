<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\RoleSlug;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    #[Test]
    public function roles_grant_exactly_the_expected_permissions(): void
    {
        $seller = User::where('email', 'seller@saka.test')->firstOrFail();

        $this->assertTrue($seller->hasPermission(Permission::ListingCreate));
        $this->assertTrue($seller->hasPermission(Permission::ListingPublish));

        // A seller must never be able to moderate.
        $this->assertFalse($seller->hasPermission(Permission::ListingModerate));
        $this->assertFalse($seller->hasPermission(Permission::UserBan));
        $this->assertFalse($seller->hasPermission(Permission::SettingsManage));
    }

    #[Test]
    public function moderator_can_moderate_but_not_administer(): void
    {
        $moderator = User::where('email', 'moderator@saka.test')->firstOrFail();

        $this->assertTrue($moderator->hasPermission(Permission::ListingModerate));
        $this->assertTrue($moderator->hasPermission(Permission::VerificationReview));

        $this->assertFalse($moderator->hasPermission(Permission::SettingsManage));
        $this->assertFalse($moderator->hasPermission(Permission::UserBan));
        $this->assertFalse($moderator->hasPermission(Permission::CategoryManage));
    }

    #[Test]
    public function super_admin_holds_every_permission(): void
    {
        $admin = User::where('email', config('saka.seeding.admin_email'))->firstOrFail();

        $this->assertCount(count(Permission::cases()), $admin->permissionSlugs());

        foreach (Permission::cases() as $permission) {
            $this->assertTrue(
                $admin->hasPermission($permission),
                "Super admin is missing {$permission->value}",
            );
        }
    }

    #[Test]
    public function staff_detection_distinguishes_sellers_from_moderators(): void
    {
        $this->assertTrue(User::where('email', 'moderator@saka.test')->firstOrFail()->isStaff());
        $this->assertTrue(User::where('email', config('saka.seeding.admin_email'))->firstOrFail()->isStaff());
        $this->assertFalse(User::where('email', 'seller@saka.test')->firstOrFail()->isStaff());
        $this->assertFalse(User::where('email', 'buyer@saka.test')->firstOrFail()->isStaff());
    }

    #[Test]
    public function assigning_a_role_immediately_grants_its_permissions(): void
    {
        $buyer = User::where('email', 'buyer@saka.test')->firstOrFail();

        // Any registered user may CREATE a listing — that is how someone
        // becomes a seller at all. PUBLISHING is the seller-only capability.
        $this->assertTrue($buyer->hasPermission(Permission::ListingCreate));
        $this->assertFalse($buyer->hasPermission(Permission::ListingPublish));

        $buyer->assignRole(RoleSlug::Seller->value);

        $this->assertTrue($buyer->fresh()->hasPermission(Permission::ListingPublish));
    }
}
