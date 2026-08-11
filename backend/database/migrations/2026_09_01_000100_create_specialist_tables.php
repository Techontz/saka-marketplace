<?php

declare(strict_types=1);

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\ServiceMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The specialist vertical: services, availability and bookings.
     *
     * WHY THERE IS NO `specialist_profiles` TABLE.
     *
     * A specialist IS A LISTING. The catalogue already models "a thing with a
     * category, category-specific attributes, a price, a location, photos,
     * reviews, inquiries and a moderation workflow", and a lawyer's profile is
     * exactly that shape — practice area and years of experience are EAV
     * attributes on the `specialists` vertical in the same way bedrooms are on
     * property. `ListingPurpose::Hire` already exists for precisely this.
     *
     * A parallel profile table would have meant a second search index, a second
     * filter builder, a second media pipeline, a second review target and a
     * second moderation queue — every one of which already works and is tested.
     * It would also have put "approve this specialist" outside the listing
     * queue an administrator already uses.
     *
     * So these three tables carry only what a listing genuinely cannot: what a
     * specialist SELLS, when they are FREE, and who has BOOKED them.
     */
    public function up(): void
    {
        Schema::create('specialist_services', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // The specialist's listing. Cascade: a deleted profile has no
            // services, and an orphan service is unbookable by construction.
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();

            $table->string('name', 150);
            $table->text('description')->nullable();

            /*
             * Duration drives the slot grid, so it is NOT optional.
             *
             * Without it there is nothing to compute an end time from, and
             * "book a lawyer" becomes an open-ended appointment nobody can
             * schedule around.
             */
            $table->unsignedSmallInteger('duration_minutes');

            /*
             * Buffer AFTER the appointment, in minutes.
             *
             * Real professionals need to write notes and take the next call.
             * Modelled here rather than baked into duration so the customer is
             * quoted the honest length of their own appointment — a 60-minute
             * consultation that silently blocks 75 minutes reads as a mistake
             * on the invoice.
             */
            $table->unsignedSmallInteger('buffer_minutes')->default(0);

            /*
             * Money in MINOR UNITS, matching `listings.price` and the rest of
             * the platform. Nullable because "price on enquiry" is a legitimate
             * and common way for professionals to sell.
             */
            $table->unsignedBigInteger('price_amount')->nullable();
            $table->char('currency', 3)->default('TZS');

            $table->enum('mode', ServiceMode::values())->default(ServiceMode::Both->value);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['listing_id', 'is_active', 'position'], 'specialist_services_menu_idx');
        });

        /*
         * Recurring working hours, one row per weekday interval.
         *
         * Rows rather than a JSON blob — unlike `seller_profiles.opening_hours`,
         * which is display-only. These are QUERIED: "is 14:30 on a Tuesday
         * inside any of this specialist's windows" runs on every slot request,
         * and a JSON column cannot be indexed for it.
         *
         * Several rows per weekday is the point: a lawyer who sits 09:00–13:00
         * and 14:00–17:00 has a lunch break, and one row per day could not say
         * so without inventing a "break" concept.
         */
        Schema::create('specialist_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();

            // 0 = Sunday .. 6 = Saturday, matching Carbon::dayOfWeek so no
            // translation is needed at the point of comparison.
            $table->unsignedTinyInteger('weekday');

            $table->time('start_time');
            $table->time('end_time');

            $table->timestamps();

            $table->index(['listing_id', 'weekday'], 'specialist_availability_day_idx');
        });

        /*
         * Blocked periods — holidays, court dates, anything that overrides the
         * recurring hours.
         *
         * Absolute timestamps rather than dates: a specialist blocking
         * "Thursday afternoon" is a real case, and a date-only block would take
         * out the whole day.
         */
        Schema::create('specialist_availability_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('reason', 191)->nullable();

            $table->timestamps();

            $table->index(['listing_id', 'starts_at', 'ends_at'], 'specialist_blocks_range_idx');
        });

        Schema::create('specialist_bookings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();

            /*
             * restrictOnDelete, NOT cascade.
             *
             * Deleting a service must not silently delete the bookings people
             * hold against it — somebody has an appointment on Thursday. The
             * service is deactivated instead; the API never hard-deletes one
             * that has bookings.
             */
            $table->foreignId('specialist_service_id')->constrained('specialist_services')->restrictOnDelete();

            /*
             * The customer. Nullable, because a booking is worth taking from
             * somebody who has not registered — requiring an account is how a
             * marketplace loses the enquiry. The contact fields below are what
             * makes an anonymous booking actionable, and they are captured for
             * signed-in customers too so a later account change cannot orphan
             * an appointment.
             */
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('customer_name', 120);
            $table->string('customer_email', 191)->nullable();
            $table->string('customer_phone', 30);

            /*
             * LOCAL date and time, plus the zone they are local TO.
             *
             * A booking is an agreement between two people about a wall clock:
             * "Tuesday at 14:00" is what both of them wrote down. Storing only
             * UTC and rendering back would be correct until Tanzania or any
             * future market changed its offset, at which point every historical
             * appointment would silently move.
             *
             * `starts_at_utc` is derived from these and exists for ORDERING and
             * RANGE queries, which cannot be done correctly on a local time
             * once more than one zone is in play. Local is the truth; UTC is
             * the index.
             */
            $table->date('scheduled_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('timezone', 64)->default('Africa/Dar_es_Salaam');

            $table->timestamp('starts_at_utc');
            $table->timestamp('ends_at_utc');

            $table->enum('status', BookingStatus::values())->default(BookingStatus::Pending->value);

            // What the customer said when booking, and what the specialist said
            // when responding. Separate so a decline reason is not overwritten
            // by the customer's original note.
            $table->text('customer_note')->nullable();
            $table->text('specialist_note')->nullable();

            $table->timestamp('responded_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by', 20)->nullable(); // customer | specialist | admin

            $table->timestamps();
            $table->softDeletes();

            $table->index(['listing_id', 'scheduled_date'], 'specialist_bookings_day_idx');
            $table->index(['listing_id', 'status', 'starts_at_utc'], 'specialist_bookings_queue_idx');
            $table->index(['user_id', 'starts_at_utc'], 'specialist_bookings_customer_idx');
            $table->index('starts_at_utc');
        });

        /*
         * ─────────────────────────────────────────────────────────────────────
         * DOUBLE-BOOKING PREVENTION, IN THE DATABASE
         * ─────────────────────────────────────────────────────────────────────
         *
         * Application-level checking cannot solve this. Two requests for the
         * same 14:00 slot both run "is it free?", both see yes, and both
         * insert — the window between the check and the write is small and
         * hit constantly, because the contended slot is exactly the one two
         * people want.
         *
         * `slot_key` is a STORED generated column holding the slot's identity
         * ONLY while the booking occupies time, and NULL once it does not:
         *
         *     pending / confirmed / completed / no_show  ->  "2026-09-04T14:00"
         *     declined / cancelled                       ->  NULL
         *
         * Paired with UNIQUE (listing_id, slot_key), MySQL then does the work:
         * a second insert for a live slot violates the constraint and loses the
         * race deterministically. And because MySQL permits UNLIMITED NULLs in a
         * unique index, a cancelled booking releases its slot automatically —
         * no cleanup job, no tombstones, no "why can nobody rebook this time".
         *
         * `no_show` deliberately keeps its slot: the appointment happened as far
         * as the diary is concerned, and the specialist's time was spent.
         *
         * This covers identical starts absolutely. OVERLAPPING starts — a
         * 30-minute service beginning inside a 60-minute one — are a different
         * shape and are handled by a locked overlap check in BookingService,
         * which this constraint backstops rather than replaces.
         */
        DB::statement(sprintf(
            "ALTER TABLE `specialist_bookings`
             ADD COLUMN `slot_key` VARCHAR(32)
             GENERATED ALWAYS AS (
                 CASE WHEN `status` IN (%s)
                      THEN CONCAT(`scheduled_date`, 'T', `start_time`)
                      ELSE NULL
                 END
             ) STORED",
            implode(',', array_map(
                fn (BookingStatus $status): string => "'".$status->value."'",
                BookingStatus::occupying(),
            )),
        ));

        DB::statement(
            'ALTER TABLE `specialist_bookings`
             ADD UNIQUE `specialist_bookings_slot_unique` (`listing_id`, `slot_key`)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('specialist_bookings');
        Schema::dropIfExists('specialist_availability_blocks');
        Schema::dropIfExists('specialist_availability');
        Schema::dropIfExists('specialist_services');
    }
};
