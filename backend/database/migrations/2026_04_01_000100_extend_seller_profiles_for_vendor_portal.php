<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything a vendor profile needs that it did not have.
 *
 * `seller_profiles` was built for a marketplace seller: a display name, a bio
 * and a verification level. Milestone 12 needs a BUSINESS profile — one that
 * can describe a pharmacy's opening hours, a hotel's street address and a car
 * dealer's WhatsApp number, all in the same table.
 *
 * Everything here is nullable. A vendor exists the moment they publish their
 * first listing (the seller role is granted automatically), long before they
 * have filled any of this in — so a NOT NULL column would either block that or
 * force a fake default. Onboarding progress is measured by
 * `onboarding_completed_at` and a computed completion percentage instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table): void {
            /*
             * The multi-vertical discriminator. See BusinessType — it decides
             * which categories are offered, whether opening hours apply, and
             * what the portal calls a listing.
             *
             * A string rather than a foreign key: these values drive code paths
             * and UI copy, so a row an administrator could add would be a
             * vertical with no behaviour behind it.
             */
            $table->string('business_type', 30)->nullable()->after('business_name');

            // ---- where the business is ---------------------------------
            $table->char('country_code', 2)->default('TZ')->after('business_type');
            $table->foreignId('region_id')->nullable()->after('country_code')->constrained('regions')->nullOnDelete();
            $table->foreignId('district_id')->nullable()->after('region_id')->constrained('districts')->nullOnDelete();
            $table->foreignId('ward_id')->nullable()->after('district_id')->constrained('wards')->nullOnDelete();
            $table->string('street', 255)->nullable()->after('ward_id');

            // Same precision as `listings`, so a vendor pin and a listing pin
            // are directly comparable.
            $table->decimal('latitude', 10, 7)->nullable()->after('street');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');

            // ---- branding ----------------------------------------------
            $table->foreignId('cover_media_id')->nullable()->after('logo_media_id')
                ->constrained('media')->nullOnDelete();

            // ---- contact -----------------------------------------------
            // Distinct from users.email: the address customers should write to
            // is routinely not the one the owner signs in with.
            $table->string('public_email', 191)->nullable()->after('whatsapp');
            $table->string('public_phone', 20)->nullable()->after('public_email');

            /*
             * Opening hours as JSON, not seven pairs of columns.
             *
             * Real schedules have split shifts (09:00–13:00, 14:00–18:00) and
             * per-day closures, which a fixed column pair cannot express
             * without a second table nobody would query independently. Shape:
             *
             *   {"mon": [{"open": "09:00", "close": "17:00"}], "sun": []}
             *
             * An empty array means closed that day; a missing key means not
             * configured — different things, and the UI shows them differently.
             */
            $table->json('opening_hours')->nullable()->after('public_phone');

            /*
             * Social links as JSON for the same reason: the set changes with
             * fashion, and adding a column per network means a migration every
             * time a new one matters.
             */
            $table->json('social_links')->nullable()->after('opening_hours');

            // Null until the vendor finishes onboarding. Used to decide whether
            // to route them into the wizard rather than the dashboard.
            $table->timestamp('onboarding_completed_at')->nullable()->after('verified_at');

            // The public vendor directory filters by type and region.
            $table->index(['business_type', 'region_id'], 'seller_type_region_idx');
        });
    }

    public function down(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table): void {
            $table->dropIndex('seller_type_region_idx');

            $table->dropConstrainedForeignId('region_id');
            $table->dropConstrainedForeignId('district_id');
            $table->dropConstrainedForeignId('ward_id');
            $table->dropConstrainedForeignId('cover_media_id');

            $table->dropColumn([
                'business_type', 'country_code', 'street', 'latitude', 'longitude',
                'public_email', 'public_phone', 'opening_hours', 'social_links',
                'onboarding_completed_at',
            ]);
        });
    }
};
