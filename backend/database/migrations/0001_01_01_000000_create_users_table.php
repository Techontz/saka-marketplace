<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // Public identifier. `id` is never exposed by the API.
            $table->uuid('uuid')->unique();

            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('email', 191)->unique();
            $table->timestamp('email_verified_at')->nullable();

            // E.164. Nullable because Google sign-up yields no phone, but a
            // verified phone is a HARD GATE on publishing a listing.
            $table->string('phone', 20)->nullable()->unique();
            $table->timestamp('phone_verified_at')->nullable();

            // Nullable: OAuth-only accounts have no local password.
            $table->string('password')->nullable();

            // FK added in a later migration — `media` does not exist yet and
            // media.uploaded_by points back here (circular dependency).
            $table->unsignedBigInteger('avatar_media_id')->nullable();

            $table->char('locale', 5)->default('en');
            $table->enum('status', UserStatus::values())->default(UserStatus::Active->value);

            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('created_at');
            $table->index('phone_verified_at');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
