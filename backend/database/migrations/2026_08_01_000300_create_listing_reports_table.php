<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abuse reports against a listing.
 *
 * A table rather than a log line. Review reports are written to the log and
 * that was a reasonable shortcut for a rare, low-stakes action; a listing
 * report is neither. It is the mechanism by which a scam, a stolen photo or a
 * property that does not exist gets taken down, and a moderator cannot work
 * from grep: they need to see what is outstanding, what has been actioned, and
 * whether the same listing has been reported eleven times.
 *
 * `reporter_id` is nullable because a guest can report. Requiring an account
 * first would suppress exactly the reports worth having — someone who has just
 * been defrauded is not going to register in order to say so. The IP hash and
 * the throttle are what keep that from being an abuse vector, and the unique
 * index stops one person filing the same report twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('reason', 40);
            $table->text('details')->nullable();

            // Contactable follow-up for a guest report, if they offer it.
            $table->string('contact_email', 255)->nullable();

            // Hashed, not stored raw: enough to spot one source filing fifty
            // reports, not enough to be a log of who looked at what.
            $table->char('reporter_ip_hash', 64)->nullable();

            $table->string('status', 20)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['listing_id', 'status']);

            // One report per listing per reporter. A signed-in duplicate is a
            // mis-click; an anonymous duplicate from the same address is noise.
            $table->unique(['listing_id', 'reporter_id', 'reporter_ip_hash'], 'listing_reports_unique_reporter');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_reports');
    }
};
