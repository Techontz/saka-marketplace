<?php

declare(strict_types=1);

use App\Domain\Trust\Enums\VerificationLevel;
use App\Domain\Trust\Enums\VerificationStatus;
use App\Domain\Trust\Enums\VerificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seller identity and the trust ladder.
     *
     * `seller_profiles` is 1:1 with users and is created lazily the first time
     * a user lists something. It is also the seed of the v1.1 "Storefront"
     * feature — `slug`, `bio` and `logo` are already here, so storefronts
     * become routes and a UI rather than a schema change.
     */
    public function up(): void
    {
        Schema::create('seller_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('display_name', 120);
            $table->string('slug', 140)->unique();
            $table->text('bio')->nullable();

            $table->string('business_name', 191)->nullable();
            $table->string('business_reg_no', 60)->nullable();
            $table->string('tin', 40)->nullable();

            $table->foreignId('logo_media_id')->nullable()
                ->constrained('media')->nullOnDelete();

            $table->string('whatsapp', 20)->nullable();
            $table->string('website', 191)->nullable();

            // Document-backed verification (v1.1). Phone verification lives on
            // users.phone_verified_at and is the MVP publishing gate.
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->enum('verification_level', VerificationLevel::values())
                ->default(VerificationLevel::None->value);

            // Denormalised counters, refreshed by scheduled rollups.
            $table->unsignedInteger('total_listings')->default(0);
            $table->unsignedInteger('active_listings')->default(0);
            $table->decimal('rating_avg', 3, 2)->nullable();
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedTinyInteger('response_rate_pct')->nullable();
            $table->unsignedInteger('response_time_minutes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('is_verified');
            $table->index('rating_avg');
        });

        Schema::create('verification_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->enum('type', VerificationType::values());
            $table->enum('status', VerificationStatus::values())
                ->default(VerificationStatus::Pending->value);

            $table->foreignId('document_media_id')->nullable()
                ->constrained('media')->nullOnDelete();
            $table->string('document_number', 60)->nullable();

            $table->foreignId('reviewed_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_requests');
        Schema::dropIfExists('seller_profiles');
    }
};
