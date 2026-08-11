<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two DELIBERATELY SEPARATE audit systems:
     *
     *  - activity_log : general model auditing. Chatty, 12-month retention,
     *                   safe to prune.
     *  - audit_events : append-only security/finance trail. Hash-chained so
     *                   tampering is detectable, 7-year retention. The app DB
     *                   user should hold no UPDATE/DELETE grant on this table.
     *
     * Conflating the two is how audit trails quietly become unusable.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notif_unread_idx');
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name', 60)->nullable();
            $table->text('description')->nullable();

            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('causer_type', 120)->nullable();
            $table->unsignedBigInteger('causer_id')->nullable();

            $table->string('event', 60)->nullable();
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('request_id', 40)->nullable();

            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'activity_subject_idx');
            $table->index(['causer_type', 'causer_id'], 'activity_causer_idx');
            $table->index(['log_name', 'created_at'], 'activity_log_name_idx');
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('action', 100);

            $table->foreignId('actor_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('actor_label', 191)->nullable();

            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->json('before')->nullable();
            $table->json('after')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('request_id', 40)->nullable();

            // Chain each row to its predecessor so deletion or edit is visible.
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
            $table->index(['subject_type', 'subject_id'], 'audit_subject_idx');
            $table->index('actor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('notifications');
    }
};
