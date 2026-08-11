<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds SAKA-specific columns to spatie's `roles` table.
     *
     * `level` and `is_assignable` are presentation/guard-rail concerns for the
     * admin panel (ordering the role list; preventing a super-admin role from
     * being handed out through the UI). They are NEVER the basis of an
     * authorization decision — every check still resolves to a permission.
     */
    public function up(): void
    {
        Schema::table(config('permission.table_names.roles'), function (Blueprint $table) {
            $table->string('description', 255)->nullable()->after('guard_name');
            $table->unsignedTinyInteger('level')->default(0)->after('description');
            $table->boolean('is_assignable')->default(true)->after('level');

            $table->index('level');
        });
    }

    public function down(): void
    {
        Schema::table(config('permission.table_names.roles'), function (Blueprint $table) {
            $table->dropIndex(['level']);
            $table->dropColumn(['description', 'level', 'is_assignable']);
        });
    }
};
