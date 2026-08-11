<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 13 — what the customer side needs and did not have.
 *
 * Three changes:
 *
 *  1. FAVORITES BECOME POLYMORPHIC. A customer saves businesses as well as
 *     listings, and the table could only hold a listing_id. Existing rows are
 *     migrated to the polymorphic columns before the old one is dropped.
 *
 *  2. FAVORITES GAIN A HISTORY. Un-favouriting now stamps `removed_at` rather
 *     than deleting the row, which is the only honest way to answer "what did I
 *     save and then change my mind about?". The unique key is on the pair, so
 *     re-saving restores the original row instead of accumulating duplicates.
 *
 *  3. SEARCHES ARE RECORDED. One table serves both "your recent searches" and
 *     "what everyone is searching for", because they are the same rows read two
 *     ways. Guests are recorded by session so popular searches are not skewed
 *     to signed-in users only.
 *
 * Every step is guarded: MySQL does not roll back DDL, so a migration that
 * fails half way must be safe to run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('favorites', 'favoritable_type')) {
            Schema::table('favorites', function (Blueprint $table): void {
                $table->string('favoritable_type', 191)->after('user_id');
                $table->unsignedBigInteger('favoritable_id')->after('favoritable_type');

                // Null means "still saved". Set on removal so history survives.
                $table->timestamp('removed_at')->nullable()->after('created_at');
            });
        }

        if (Schema::hasColumn('favorites', 'listing_id')) {
            // Existing rows are all listings by definition — that was the only
            // thing the table could hold.
            DB::table('favorites')->update([
                'favoritable_type' => 'App\Models\Listing',
                'favoritable_id' => DB::raw('listing_id'),
            ]);

            // The foreign key goes FIRST and alone: MySQL refuses to drop an
            // index that is still backing one, and both sit on listing_id.
            Schema::table('favorites', function (Blueprint $table): void {
                $table->dropForeign(['listing_id']);
            });

            Schema::table('favorites', function (Blueprint $table): void {
                foreach (['favorites_user_id_listing_id_unique', 'favorites_listing_id_index'] as $index) {
                    if (self::hasIndex('favorites', $index)) {
                        $table->dropIndex($index);
                    }
                }

                $table->dropColumn('listing_id');
            });
        }

        if (! self::hasIndex('favorites', 'favorites_user_target_unique')) {
            Schema::table('favorites', function (Blueprint $table): void {
                $table->unique(['user_id', 'favoritable_type', 'favoritable_id'], 'favorites_user_target_unique');
                $table->index(['favoritable_type', 'favoritable_id'], 'favorites_target_index');
                // Partial indexes do not exist in MySQL, so "still saved"
                // filters ride on this composite instead.
                $table->index(['user_id', 'removed_at', 'created_at'], 'favorites_user_active_index');
            });
        }

        if (! Schema::hasColumn('users', 'notification_preferences')) {
            Schema::table('users', function (Blueprint $table): void {
                /*
                 * Null means "never chosen", which is NOT the same as
                 * "everything off" — an unset preference falls back to the
                 * platform default, while a user who has deliberately turned
                 * everything off keeps it that way. Writing the defaults at
                 * registration would erase that distinction.
                 */
                $table->json('notification_preferences')->nullable()->after('locale');
            });
        }

        if (! Schema::hasTable('search_queries')) {
            Schema::create('search_queries', function (Blueprint $table): void {
                $table->id();

                // Both nullable: a guest search still counts toward what is
                // popular, and a signed-in search still belongs to a session.
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('session_id', 100)->nullable();

                // Normalised (trimmed, lower-cased) for grouping; `raw_query`
                // keeps what the customer actually typed, for their history.
                $table->string('query', 200);
                $table->string('raw_query', 200);

                $table->json('filters')->nullable();
                $table->unsignedInteger('results_count')->default(0);
                $table->timestamp('created_at')->useCurrent();

                $table->index(['user_id', 'created_at']);
                $table->index(['session_id', 'created_at']);
                // Popular searches: group by query over a recent window.
                $table->index(['query', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('search_queries');

        if (Schema::hasColumn('users', 'notification_preferences')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('notification_preferences');
            });
        }

        if (Schema::hasColumn('favorites', 'listing_id')) {
            return;
        }

        Schema::table('favorites', function (Blueprint $table): void {
            $table->unsignedBigInteger('listing_id')->nullable()->after('user_id');
        });

        DB::table('favorites')
            ->where('favoritable_type', 'App\Models\Listing')
            ->update(['listing_id' => DB::raw('favoritable_id')]);

        // Rows for anything that is not a listing cannot survive the old shape.
        DB::table('favorites')->whereNull('listing_id')->delete();

        Schema::table('favorites', function (Blueprint $table): void {
            $table->dropUnique('favorites_user_target_unique');
            $table->dropIndex('favorites_target_index');
            $table->dropIndex('favorites_user_active_index');
            $table->dropColumn(['favoritable_type', 'favoritable_id', 'removed_at']);
        });

        Schema::table('favorites', function (Blueprint $table): void {
            $table->unsignedBigInteger('listing_id')->nullable(false)->change();
            $table->foreign('listing_id')->references('id')->on('listings')->cascadeOnDelete();
            $table->unique(['user_id', 'listing_id']);
            $table->index(['listing_id']);
        });
    }

    private static function hasIndex(string $table, string $index): bool
    {
        return DB::select(
            'select 1 from information_schema.statistics where table_schema = database() and table_name = ? and index_name = ? limit 1',
            [$table, $index],
        ) !== [];
    }
};
