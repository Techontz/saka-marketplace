<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A fourth purpose: hire.
 *
 * `purpose` had rent / sale / lease, which covers property and goods and covers
 * nothing else. A job vacancy is not "for sale" and a plumber's call-out rate
 * is not "for rent", so every listing in the Jobs and Services verticals had to
 * be stored with a NULL purpose — which meant the purpose filter, the purpose
 * badge on the card and the "For sale / To let" line on the detail page all
 * silently disappeared on two entire verticals.
 *
 * Adding the case is the honest fix. Faking it by labelling a vacancy "for
 * rent" would have been visible to every customer who read one.
 *
 * MySQL only: enum modification is expressed as raw SQL because Doctrine DBAL
 * cannot alter an enum in place. SQLite stores enums as text with a CHECK
 * constraint Laravel does not emit, so there is nothing to change there — the
 * guard keeps the test suite portable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isMySql()) {
            return;
        }

        DB::statement("ALTER TABLE listings MODIFY purpose ENUM('rent','sale','lease','hire') NULL");
    }

    public function down(): void
    {
        if (! $this->isMySql()) {
            return;
        }

        // Anything already stored as `hire` would violate the narrowed column,
        // so it is moved to the nearest surviving value first rather than
        // failing the rollback.
        DB::table('listings')->where('purpose', 'hire')->update(['purpose' => null]);

        DB::statement("ALTER TABLE listings MODIFY purpose ENUM('rent','sale','lease') NULL");
    }

    private function isMySql(): bool
    {
        return Schema::getConnection()->getDriverName() === 'mysql';
    }
};
