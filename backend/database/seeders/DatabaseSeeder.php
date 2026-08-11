<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Reference data first, then accounts.
 *
 * Every seeder is idempotent (updateOrCreate throughout), so `db:seed` can be
 * re-run after editing taxonomy or permissions without dropping the database.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            LocationSeeder::class,
            CatalogSeeder::class,
            // The Specialists vertical and its category-specific attributes.
            // After CatalogSeeder so the root ordering is stable.
            SpecialistCatalogSeeder::class,
            ContentSeeder::class,
            HomepageSeeder::class,
            UserSeeder::class,
        ]);
    }
}
