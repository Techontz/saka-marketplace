<?php

declare(strict_types=1);

use App\Domain\Advertising\Enums\AdCampaignStatus;
use App\Domain\Advertising\Enums\AdPlacement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SAKA's own advertising inventory.
     *
     * Distinct from Google AdSense, which is a script in a slot and owns none
     * of this. This is the inventory SAKA sells directly: a dealership paying
     * for a strip above the vehicle grid, an agency promoting a development.
     *
     * FOUR concepts, because collapsing any two of them breaks a real case:
     *
     *   advertiser  — who is billed. Outlives any one campaign, and may or may
     *                 not also be a vendor on the platform.
     *   campaign    — a booking of inventory: one placement, a date window, a
     *                 priority and its targeting. What gets scheduled and paid.
     *   creative    — the artwork. A campaign has several so it can be rotated
     *                 or A/B tested without re-booking, and so replacing a
     *                 banner mid-flight does not reset the campaign's numbers.
     *   events      — what happened.
     *
     * ON MEASUREMENT — this deliberately does NOT store one row per impression.
     *
     * A strip above the listing grid is rendered on essentially every search;
     * at any real traffic that is millions of rows a month describing nothing
     * anyone queries individually, and the table becomes the largest in the
     * database within a quarter. Impressions are therefore aggregated per
     * creative per day per placement, which is the granularity every report
     * actually asks for.
     *
     * Clicks ARE stored individually. They are three orders of magnitude rarer,
     * they are what an advertiser is billed for, and a billing dispute is
     * answered by pointing at rows — "when, from where, how many times" is not
     * a question a daily total can answer.
     */
    public function up(): void
    {
        Schema::create('advertisers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('name', 191);
            $table->string('slug', 200)->unique();

            /*
             * The vendor this advertiser IS, when they are one.
             *
             * Nullable because most advertisers will be — a bank, a telco, a
             * dealership that does not list on SAKA. When it is set, the admin
             * can jump between the campaign and the storefront, and the
             * advertiser inherits a verified badge it has already earned.
             *
             * nullOnDelete, not cascade: deleting a vendor account must not
             * delete the invoicing record of the campaigns they paid for.
             */
            $table->foreignId('seller_profile_id')->nullable()
                ->constrained('seller_profiles')->nullOnDelete();

            // Billing contact. Deliberately NOT a user account: the person who
            // signs off an insertion order is rarely the one with a login.
            $table->string('contact_name', 191)->nullable();
            $table->string('contact_email', 191)->nullable();
            $table->string('contact_phone', 30)->nullable();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'name']);
        });

        Schema::create('ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // cascadeOnDelete is safe here in a way it is not on `advertisers`:
            // an advertiser is only ever force-deleted deliberately, and a
            // campaign with no advertiser is unbillable and unservable.
            $table->foreignId('advertiser_id')->constrained('advertisers')->cascadeOnDelete();

            $table->string('name', 191);
            $table->enum('placement', AdPlacement::values());
            $table->enum('status', AdCampaignStatus::values())
                ->default(AdCampaignStatus::Draft->value);

            /*
             * The flight window. BOTH nullable:
             *   starts_at null — begins the moment it is activated;
             *   ends_at   null — runs until somebody stops it, which is what an
             *                    open-ended house ad or a retainer looks like.
             */
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            /*
             * Higher wins when more campaigns are eligible than the placement
             * can show. Ties break on `id`, so the ordering is total and stable
             * — without that, two campaigns at the same priority would swap
             * places between requests and neither advertiser would trust the
             * numbers.
             */
            $table->unsignedSmallInteger('priority')->default(0);

            /*
             * A hard stop on delivery, independent of the date window.
             *
             * This is how a fixed-price insertion order is honoured: "500,000
             * impressions or the end of March, whichever comes first". Null
             * means uncapped. Enforced at serve time against the denormalised
             * counter, so it survives a cron outage.
             */
            $table->unsignedBigInteger('impression_cap')->nullable();

            // Denormalised lifetime totals. The per-day rollup remains the
            // source of truth for reporting; these exist so the admin list and
            // the cap check are single-row reads.
            $table->unsignedBigInteger('impressions_count')->default(0);
            $table->unsignedBigInteger('clicks_count')->default(0);

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            /*
             * THE serving index.
             *
             * Every public page hits this query: "eligible campaigns for
             * placement X, right now, best first". Column order matters —
             * `placement` and `status` are equality predicates and must come
             * before the ranges, or MySQL cannot use the tail of the index.
             */
            $table->index(['placement', 'status', 'starts_at', 'ends_at'], 'ad_campaigns_serving_idx');
            $table->index(['advertiser_id', 'status']);
            // Used by `ads:refresh-statuses` to find the few rows whose window
            // has just opened or closed, rather than scanning every campaign.
            $table->index(['status', 'ends_at']);
        });

        /*
         * Targeting.
         *
         * Pivots rather than a nullable `target_category_id`, because real
         * bookings are plural: a property developer wants apartments AND plots,
         * a dealership wants cars AND motorcycles. Modelling that as one column
         * forces either a duplicate campaign per category — which splits the
         * reporting the advertiser is paying for — or a JSON blob nothing can
         * index or foreign-key.
         *
         * NO ROWS MEANS EVERYWHERE. An untargeted campaign is the common case
         * and must not require a row per category to express.
         */
        Schema::create('ad_campaign_category', function (Blueprint $table) {
            $table->foreignId('ad_campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();

            $table->primary(['ad_campaign_id', 'category_id']);
            // The serving query starts from the category, so it needs this
            // direction indexed too; the composite primary key only serves the
            // other one.
            $table->index('category_id');
        });

        Schema::create('ad_campaign_region', function (Blueprint $table) {
            $table->foreignId('ad_campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->foreignId('region_id')->constrained('regions')->cascadeOnDelete();

            $table->primary(['ad_campaign_id', 'region_id']);
            $table->index('region_id');
        });

        Schema::create('ad_creatives', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ad_campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();

            // The copy. Rendered as TEXT by the Banner component, never as
            // HTML — an advertiser-supplied string in `dangerouslySetInnerHTML`
            // is stored XSS with an invoice attached.
            $table->string('headline', 120);
            $table->string('body', 240)->nullable();
            $table->string('cta_label', 40)->nullable();

            /*
             * Where the click goes.
             *
             * Validated to http/https on write, for the same reason the vendor
             * website field is: `javascript:` in an href is stored XSS, and
             * this one is placed by someone outside the organisation.
             */
            $table->string('click_url', 2048);

            /*
             * Separate desktop and mobile artwork.
             *
             * Not an optimisation — a 1600×200 strip letterboxed into a 360px
             * column is an unreadable 45px sliver. Mobile is nullable: an
             * advertiser who supplies only one image gets it on both, scaled,
             * which is worse but is their choice to make.
             */
            $table->foreignId('image_media_id')->nullable()
                ->constrained('media')->nullOnDelete();
            $table->foreignId('mobile_media_id')->nullable()
                ->constrained('media')->nullOnDelete();

            // Describes the ADVERT, not the artwork — a screen-reader user
            // needs to know this is a promotion and what it offers.
            $table->string('alt_text', 255)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            $table->unsignedBigInteger('impressions_count')->default(0);
            $table->unsignedBigInteger('clicks_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['ad_campaign_id', 'is_active', 'position'], 'ad_creatives_rotation_idx');
        });

        /*
         * Impressions, aggregated.
         *
         * `placement` is denormalised onto the row rather than joined from the
         * campaign, because a campaign's placement can be edited and the
         * history must not retroactively change: an advertiser reading last
         * month's report should see where the ad ACTUALLY ran.
         */
        Schema::create('ad_impressions_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_creative_id')->constrained('ad_creatives')->cascadeOnDelete();
            $table->foreignId('ad_campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->enum('placement', AdPlacement::values());
            $table->date('date');

            $table->unsignedBigInteger('impressions')->default(0);

            // The upsert target for the buffered flush.
            $table->unique(['ad_creative_id', 'placement', 'date'], 'ad_impressions_daily_unique');
            $table->index(['ad_campaign_id', 'date']);
            $table->index('date');
        });

        Schema::create('ad_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_creative_id')->constrained('ad_creatives')->cascadeOnDelete();
            $table->foreignId('ad_campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->enum('placement', AdPlacement::values());

            // Nullable: most clicks are from guests, and requiring a user would
            // mean discarding the majority of the data.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * A HASH of the IP, never the IP.
             *
             * Enough to spot one machine clicking an ad four hundred times,
             * which is the fraud case this column exists for. Not enough to
             * identify a person, which is not something an ad click justifies
             * collecting. Matches how `listing_views` already treats it.
             */
            $table->char('ip_hash', 64)->nullable();
            $table->string('referrer', 255)->nullable();

            $table->timestamp('clicked_at')->useCurrent();

            $table->index(['ad_creative_id', 'clicked_at']);
            $table->index(['ad_campaign_id', 'clicked_at']);
            // Fraud review: "everything from this client in this window".
            $table->index(['ip_hash', 'clicked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_clicks');
        Schema::dropIfExists('ad_impressions_daily');
        Schema::dropIfExists('ad_creatives');
        Schema::dropIfExists('ad_campaign_region');
        Schema::dropIfExists('ad_campaign_category');
        Schema::dropIfExists('ad_campaigns');
        Schema::dropIfExists('advertisers');
    }
};
