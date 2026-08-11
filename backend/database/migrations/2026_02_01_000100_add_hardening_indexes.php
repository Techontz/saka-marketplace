<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes added after reviewing real query plans (Milestone 8).
     *
     * Both back list endpoints that filter on one column and ORDER BY another.
     * The existing composites cover the filter but not the sort, so MySQL was
     * filesorting the matched rows — invisible at seed volume, quadratic pain
     * for a seller with thousands of inquiries.
     */
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            // GET /seller/inquiries — where seller_id = ? order by created_at desc
            $table->index(['seller_id', 'created_at'], 'inquiries_seller_recent_idx');
        });

        Schema::table('reviews', function (Blueprint $table) {
            // GET /listings/{slug}/reviews — where listing_id = ? and status = ?
            // order by created_at desc
            $table->index(['listing_id', 'status', 'created_at'], 'reviews_listing_recent_idx');

            // GET /account/reviews — where reviewer_id = ? order by created_at desc
            $table->index(['reviewer_id', 'created_at'], 'reviews_author_recent_idx');
        });

        Schema::table('listings', function (Blueprint $table) {
            // The expiry sweeper: where status = 'published' and expires_at < now
            $table->index(['status', 'expires_at'], 'listings_expiry_sweep_idx');
        });
    }

    public function down(): void
    {
        Schema::table('listings', fn (Blueprint $t) => $t->dropIndex('listings_expiry_sweep_idx'));
        Schema::table('reviews', function (Blueprint $t) {
            $t->dropIndex('reviews_author_recent_idx');
            $t->dropIndex('reviews_listing_recent_idx');
        });
        Schema::table('inquiries', fn (Blueprint $t) => $t->dropIndex('inquiries_seller_recent_idx'));
    }
};
