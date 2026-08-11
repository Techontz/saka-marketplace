<?php

declare(strict_types=1);

use App\Domain\Engagement\Enums\InquirySource;
use App\Domain\Engagement\Enums\InquiryStatus;
use App\Domain\Engagement\Enums\ReviewStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The heart buttons already rendered on every card and in the header.
        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'listing_id']);
            $table->index(['listing_id']);
            $table->index(['user_id', 'created_at']);
        });

        /**
         * Serves BOTH frontend entry points: "Contact Seller" on a listing and
         * the standalone /contact form (listing_id NULL).
         *
         * Guests may submit, so sender_user_id is nullable and the contact
         * fields are captured inline. This is a public unauthenticated write
         * endpoint — hence the ip/user-agent capture for abuse handling.
         */
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('listing_id')->nullable()
                ->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->nullable()
                ->constrained('users')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('email', 191);
            $table->string('phone', 20)->nullable();
            $table->text('message');

            $table->enum('source', InquirySource::values())
                ->default(InquirySource::Listing->value);
            $table->enum('status', InquiryStatus::values())
                ->default(InquiryStatus::New->value);

            $table->text('reply_body')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamps();

            $table->index(['listing_id', 'created_at']);
            $table->index(['seller_id', 'status']);
            $table->index(['email']);
            $table->index(['status', 'created_at']);
        });

        /**
         * Reviews — promoted to MVP by Milestone 4 decision 2.
         *
         * `seller_id` is always populated (even for a listing review) so the
         * seller rating rollup is a single indexed aggregate rather than a join
         * through listings. Moderated: only Approved rows count toward ratings.
         */
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('listing_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedTinyInteger('rating');
            $table->string('title', 200)->nullable();
            $table->text('body')->nullable();

            $table->enum('status', ReviewStatus::values())
                ->default(ReviewStatus::Pending->value);
            $table->text('moderation_note')->nullable();
            $table->foreignId('moderated_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();

            // Seller's public response.
            $table->text('reply_body')->nullable();
            $table->timestamp('replied_at')->nullable();

            $table->unsignedInteger('helpful_count')->default(0);

            // Reserved for v2.0: set once an order backs the review.
            $table->boolean('is_verified_purchase')->default(false);

            $table->timestamps();
            $table->softDeletes();

            // One review per reviewer per listing.
            $table->unique(['reviewer_id', 'listing_id']);
            $table->index(['seller_id', 'status']);
            $table->index(['listing_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        DB::statement('ALTER TABLE `reviews` ADD CONSTRAINT `reviews_rating_range` CHECK (`rating` BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE `reviews` ADD CONSTRAINT `reviews_no_self_review` CHECK (`reviewer_id` <> `seller_id`)');

        /**
         * Raw view events. The fastest-growing table in any marketplace —
         * rolled up nightly into listing_view_daily and pruned at 90 days by a
         * scheduled command. Deliberately NOT partitioned in this migration:
         * MySQL requires the partition key to be part of every unique key, and
         * the daily-dedupe unique key is more valuable at this stage.
         */
        Schema::create('listing_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->char('session_id', 40)->nullable();
            $table->char('ip_hash', 64);
            $table->string('referrer', 255)->nullable();
            $table->timestamp('viewed_at')->useCurrent();

            $table->index(['listing_id', 'viewed_at']);
            $table->index(['user_id', 'viewed_at']);
        });

        // Generated column + unique key: one counted view per listing, per
        // client, per day. Cheaper and more reliable than app-side dedupe.
        DB::statement('ALTER TABLE `listing_views` ADD COLUMN `viewed_on` DATE GENERATED ALWAYS AS (DATE(`viewed_at`)) STORED');
        DB::statement('ALTER TABLE `listing_views` ADD UNIQUE `listing_views_daily_unique` (`listing_id`, `ip_hash`, `viewed_on`)');

        Schema::create('listing_view_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('unique_views')->default(0);

            $table->unique(['listing_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_view_daily');
        Schema::dropIfExists('listing_views');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('inquiries');
        Schema::dropIfExists('favorites');
    }
};
