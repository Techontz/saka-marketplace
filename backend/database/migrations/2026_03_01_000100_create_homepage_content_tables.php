<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable homepage content: banners and sections.
 *
 * Milestone 11 asks the admin portal to manage "homepage banners" and
 * "homepage sections". Neither existed as an entity — the homepage was a fixed
 * sequence of hardcoded components — so there was nothing for a CMS screen to
 * edit. These tables are that missing model.
 *
 * DELIBERATELY NOT a generic page-builder. A block system that can express any
 * layout is a large product in its own right and would let an administrator
 * break the homepage's design, which this project has spent four milestones
 * holding still. Instead:
 *
 *   - `homepage_banners` is a list of promotional slots with an image, copy and
 *     a link — content the marketing team owns;
 *   - `homepage_sections` is the ORDER and VISIBILITY of the sections that
 *     already exist, plus their headings. An administrator can retitle
 *     "Trending Listings", reorder it, or hide it. They cannot invent a new
 *     kind of section, because the component that renders it has to exist.
 *
 * That boundary is the point: everything editable here is data the design
 * already accounts for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homepage_banners', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->string('title', 191);
            $table->string('subtitle', 255)->nullable();

            // Free text rather than a route name: banners routinely point at
            // campaigns, filtered searches and external partner pages.
            $table->string('link_url', 500)->nullable();
            $table->string('link_label', 60)->nullable();

            $table->foreignId('image_media_id')->nullable()->constrained('media')->nullOnDelete();

            // Where on the page it renders. Constrained rather than free text so
            // a typo cannot make a banner silently invisible.
            $table->string('placement', 30)->default('hero');

            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            // A campaign banner that nobody remembers to take down is the
            // normal failure mode, so scheduling is built in from the start.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->timestamps();

            // The public read is always "active banners for this placement, in
            // order" — this index serves exactly that.
            $table->index(['placement', 'is_active', 'position'], 'banners_placement_active_idx');
        });

        Schema::create('homepage_sections', function (Blueprint $table): void {
            $table->id();

            /*
             * The component this row controls. Immutable after seeding: it is
             * the join to a React component, not a label. Renaming it would
             * orphan the section.
             */
            $table->string('key', 60)->unique();

            $table->string('title', 191);
            $table->string('subtitle', 500)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            // How many items the section shows, where that is meaningful.
            // Null means "the component's own default".
            $table->unsignedSmallInteger('item_limit')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'position'], 'sections_active_position_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homepage_sections');
        Schema::dropIfExists('homepage_banners');
    }
};
