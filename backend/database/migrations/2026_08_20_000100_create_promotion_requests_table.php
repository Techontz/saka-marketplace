<?php

declare(strict_types=1);

use App\Domain\Advertising\Enums\AdPlacement;
use App\Domain\Advertising\Enums\PromotionRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A vendor asking to promote something they own.
     *
     * WHY THIS IS NOT JUST A DRAFT CAMPAIGN.
     *
     * The obvious shortcut is to let vendors create `ad_campaigns` rows in
     * draft. It is wrong for three reasons that only show up later:
     *
     *   1. A campaign carries `priority` and `impression_cap` — the commercial
     *      terms. A vendor must never propose their own priority, or every
     *      request arrives at 65535.
     *   2. A campaign belongs to an `advertiser`, which is a BILLING record.
     *      Creating one per speculative request would fill the advertiser list
     *      with vendors who never got approved.
     *   3. A rejected request has to keep its reason and its artwork so the
     *      vendor can see what was wrong and resubmit. A deleted draft campaign
     *      has neither.
     *
     * So a request is a distinct thing with its own lifecycle, and approval
     * MINTS a campaign from it. `ad_campaign_id` is the seam between the two.
     *
     * ON PAYMENT — there are no payment columns here, deliberately.
     *
     * SAKA has no payment infrastructure, and nullable `paid_at` / `amount` /
     * `transaction_reference` columns that are always null are not
     * "payment-ready": they are three columns every query has to ignore and a
     * UI that has to decide what "not paid" means when nothing can ever pay.
     *
     * Payment attaches later as its own table — one row per settled request:
     *
     *   promotion_payments(
     *     promotion_request_id, amount_minor, currency, provider,
     *     provider_reference, status, paid_at, refunded_at
     *   )
     *
     * Nothing in this table changes when that lands. `PromotionRequestStatus`
     * stays a REVIEW state, payment becomes a separate axis, and an
     * approved-but-unpaid request is expressed by the absence of a payment row
     * rather than by a status value nobody can interpret.
     */
    public function up(): void
    {
        Schema::create('promotion_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Who asked. cascadeOnDelete: a deleted account's speculative
            // requests are not records anybody needs to keep — unlike the
            // campaigns and delivery that approval produces, which hang off
            // `advertisers` and survive independently.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            /*
             * What is being promoted.
             *
             * Polymorphic because the answer is already three different tables
             * — a listing, a business profile, and (from Phase 8) a specialist
             * profile — and will grow. The alternative, three nullable FK
             * columns with a CHECK that exactly one is set, means a migration
             * every time SAKA gains something worth promoting.
             *
             * No FK constraint is possible on a morph, so ownership and
             * existence are re-verified at approval rather than trusted from
             * submission time. See PromotionRequestService::assertPromotable().
             */
            $table->string('promotable_type', 120);
            $table->unsignedBigInteger('promotable_id');

            $table->enum('placement', AdPlacement::values());

            /*
             * DATES, not timestamps.
             *
             * A vendor asks for "the 3rd to the 10th", not for 14:32:07 on the
             * 3rd. Storing a time would invent precision nobody supplied and
             * make two requests for the same day look different. The campaign
             * minted at approval widens these to a full-day window.
             */
            $table->date('requested_start');
            $table->date('requested_end');

            /*
             * Defaults to DRAFT, not pending.
             *
             * A request is created before its artwork exists — media is
             * polymorphic and needs this row to attach to — so defaulting to
             * pending would drop every half-written wizard into the review
             * queue. `submit` is what moves it, and it checks the artwork.
             */
            $table->enum('status', PromotionRequestStatus::values())
                ->default(PromotionRequestStatus::Draft->value);

            // ---- the creative the vendor proposes -----------------------
            //
            // Mirrors `ad_creatives` so approval is a copy rather than a
            // translation. There is deliberately NO destination column: a
            // vendor promotion always points at the SAKA resource being
            // promoted, resolved server-side at approval. See the service.
            $table->string('headline', 120);
            $table->string('body', 240)->nullable();
            $table->string('cta_label', 40)->nullable();

            $table->foreignId('image_media_id')->nullable()
                ->constrained('media')->nullOnDelete();
            $table->foreignId('mobile_media_id')->nullable()
                ->constrained('media')->nullOnDelete();

            // ---- review -------------------------------------------------
            $table->foreignId('reviewed_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            // Required on rejection, enforced in the controller. A vendor told
            // only "Rejected" resubmits the same thing.
            $table->text('rejection_reason')->nullable();

            /*
             * The campaign minted on approval.
             *
             * nullOnDelete rather than cascade: an administrator deleting a
             * campaign created in error must not also delete the vendor's
             * record of having asked for it, or the vendor's Promotions screen
             * silently loses a row they are waiting on.
             */
            $table->foreignId('ad_campaign_id')->nullable()
                ->constrained('ad_campaigns')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // The vendor's own list, newest first.
            $table->index(['user_id', 'status', 'created_at'], 'promotion_requests_vendor_idx');
            // The administrator's review queue.
            $table->index(['status', 'created_at'], 'promotion_requests_queue_idx');
            // "Is this listing already being promoted?" — asked on every
            // vendor listing row that offers a Promote action.
            $table->index(['promotable_type', 'promotable_id', 'status'], 'promotion_requests_subject_idx');
            $table->index('ad_campaign_id');
            // `saka:promotions:expire` sweeps by window; without this it scans.
            $table->index(['status', 'requested_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_requests');
    }
};
