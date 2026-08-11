<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Enums\Permission as PermissionEnum;
use App\Domain\Identity\Enums\RoleSlug;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent. Editing Permission::forRole() and re-running this seeder is the
 * supported way to adjust the baseline authorization matrix.
 *
 * spatie stores the identifier in `name`; the human label comes from the enum,
 * so there is exactly one canonical identifier and no name/slug drift.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // The permission cache is keyed by guard; stale entries here would make
        // freshly-seeded roles look empty until it expired.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::cases() as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission->value, 'guard_name' => 'web'],
                [],
            );
        }

        foreach (RoleSlug::cases() as $roleSlug) {
            /** @var Role $role */
            $role = Role::updateOrCreate(
                ['name' => $roleSlug->value, 'guard_name' => 'web'],
                [
                    'description' => $roleSlug->label(),
                    'level' => $roleSlug->level(),
                    'is_assignable' => $roleSlug !== RoleSlug::SuperAdmin,
                ],
            );

            $role->syncPermissions(array_map(
                fn (PermissionEnum $p) => $p->value,
                PermissionEnum::forRole($roleSlug),
            ));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command->info(sprintf(
            '  Seeded %d permissions across %d roles (spatie, guard: web).',
            count(PermissionEnum::cases()),
            count(RoleSlug::cases()),
        ));
    }
}
