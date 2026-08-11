<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\OAuthProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Social sign-in. The frontend login dialog offers Google today.
        Schema::create('oauth_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('provider', OAuthProvider::values());
            $table->string('provider_user_id', 191);
            $table->string('email', 191)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->index('user_id');
        });

        /**
         * Phone OTP codes.
         *
         * Codes are stored HASHED — a database leak must not hand out live
         * one-time codes. `attempts` caps brute force; the row is consumed on
         * success rather than deleted, so replay is detectable.
         */
        Schema::create('phone_verification_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('code_hash', 255);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['phone', 'expires_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_verification_codes');
        Schema::dropIfExists('oauth_identities');
    }
};
