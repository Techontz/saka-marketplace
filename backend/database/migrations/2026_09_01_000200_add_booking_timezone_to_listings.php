<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The timezone a specialist's diary is kept in.
     *
     * On `listings` because a specialist IS a listing here, and because it is a
     * property of the PROFILE rather than of the account: a firm with a partner
     * in Dar es Salaam and another in Nairobi keeps two diaries, and hanging
     * this off the user would force them into one.
     *
     * Nullable, defaulting in code to Africa/Dar_es_Salaam. A NULL means "never
     * set" and reads as the default; a column default would make it impossible
     * to tell a deliberate choice of Dar es Salaam from silence — which matters
     * the day a second market is added and every existing row needs migrating.
     */
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->string('booking_timezone', 64)->nullable()->after('available_from');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->dropColumn('booking_timezone');
        });
    }
};
