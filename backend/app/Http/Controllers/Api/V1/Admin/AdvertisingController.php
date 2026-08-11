<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Advertising\Enums\AdCampaignStatus;
use App\Domain\Advertising\Enums\AdPlacement;
use App\Domain\Advertising\Enums\PromotionRequestStatus;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Media\Enums\MediaCollection;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Admin\AdminAdCampaignResource;
use App\Http\Resources\V1\Admin\AdminAdCreativeResource;
use App\Http\Resources\V1\Admin\AdminAdvertiserResource;
use App\Http\Resources\V1\Admin\AdminPromotionRequestResource;
use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\Advertiser;
use App\Models\Category;
use App\Models\Media;
use App\Models\PromotionRequest;
use App\Models\Region;
use App\Services\Advertising\PromotionRequestService;
use App\Services\Audit\AuditLogger;
use App\Services\Media\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Advertising administration.
 *
 * Until this existed a campaign could only be created through Tinker, which is
 * not a product — it meant nobody without shell access could sell inventory.
 *
 * TWO RULES SHAPE THE WHOLE CONTROLLER.
 *
 * 1. STATUS IS NEVER MASS-ASSIGNED. `AdCampaign::$guarded` blocks it, and the
 *    only way to move a campaign is `transition()`. Otherwise "fix a typo in
 *    the campaign name" could carry `status: active` in the body and put an
 *    unreviewed advert live — a PATCH that bills somebody.
 *
 * 2. TARGETING IS SET BY SLUG. The category and region pickers speak slugs
 *    everywhere else on this surface, and an auto-increment id in a request
 *    body is an invitation to enumerate the catalogue.
 */
class AdvertisingController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MediaUploadService $media,
        private readonly PromotionRequestService $promotions,
    ) {}

    // ------------------------------------------------------------- reference

    /**
     * The vocabulary the admin UI needs to render its form.
     *
     * Placements, statuses and their labels come from the enums, so the portal
     * never carries a second copy of them. A placement added in PHP appears in
     * the dropdown without a frontend deploy — the same arrangement as
     * `/business-types`.
     */
    public function options(Request $request): JsonResponse
    {
        $this->authorizeAdvertising($request);

        return response()->json([
            'data' => [
                'placements' => array_map(
                    fn (AdPlacement $placement): array => $placement->toArray(),
                    AdPlacement::cases(),
                ),
                'statuses' => array_map(
                    fn (AdCampaignStatus $status): array => $status->toArray(),
                    AdCampaignStatus::cases(),
                ),
            ],
        ]);
    }

    // ----------------------------------------------------------- advertisers

    public function indexAdvertisers(Request $request): JsonResponse
    {
        $this->authorizeAdvertising($request);

        $advertisers = Advertiser::query()
            ->with('sellerProfile')
            ->withCount('campaigns')
            ->when($request->string('q')->toString() !== '', function ($query) use ($request): void {
                $term = $request->string('q')->toString();
                $query->where('name', 'like', "%{$term}%");
            })
            ->when($request->boolean('active_only'), fn ($query) => $query->active())
            ->orderBy('name')
            ->paginate(min((int) $request->integer('per_page', 50), 100));

        return AdminAdvertiserResource::collection($advertisers)->response();
    }

    public function storeAdvertiser(Request $request): JsonResponse
    {
        $this->authorizeAdvertising($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:191'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:191'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $advertiser = new Advertiser;
        $advertiser->fill($validated);
        $advertiser->slug = $this->uniqueAdvertiserSlug($validated['name']);
        $advertiser->save();

        $this->audit->record('advertiser.created', $request->user(), $advertiser, [], $advertiser->getChanges());

        return response()->json(
            ['data' => new AdminAdvertiserResource($advertiser->loadCount('campaigns'))],
            Response::HTTP_CREATED,
        );
    }

    public function updateAdvertiser(Request $request, string $uuid): JsonResponse
    {
        $this->authorizeAdvertising($request);

        $advertiser = Advertiser::query()->where('uuid', $uuid)->firstOr(fn () => throw ApiException::notFound());

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:191'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:191'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // The slug is NOT re-derived from a renamed advertiser. It is a stable
        // handle used in operator URLs; renaming "NMB" to "NMB Bank PLC" must
        // not break every link somebody has bookmarked.
        $advertiser->fill($validated)->save();

        $this->audit->recordChange('advertiser.updated', $request->user(), $advertiser);

        return response()->json(['data' => new AdminAdvertiserResource($advertiser->loadCount('campaigns'))]);
    }

    // ------------------------------------------------------------- campaigns

    public function indexCampaigns(Request $request): JsonResponse
    {
        $this->authorizeAdvertising($request);

        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(AdCampaignStatus::values())],
            'placement' => ['sometimes', 'nullable', Rule::in(AdPlacement::values())],
            'advertiser' => ['sometimes', 'nullable', 'string'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $campaigns = AdCampaign::query()
            ->with(['advertiser', 'categories', 'regions'])
            ->withCount('creatives')
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['placement'] ?? null, fn ($query, $placement) => $query->where('placement', $placement))
            ->when(
                $validated['advertiser'] ?? null,
                fn ($query, $uuid) => $query->whereHas('advertiser', fn ($q) => $q->where('uuid', $uuid)),
            )
            ->when(
                $validated['q'] ?? null,
                fn ($query, $term) => $query->where('name', 'like', "%{$term}%"),
            )
            /*
             * Newest first, not by priority.
             *
             * Priority orders SERVING; an operator's list is a work queue and
             * the thing they just created has to be at the top of it.
             */
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 25);

        return AdminAdCampaignResource::collection($campaigns)->response();
    }

    public function showCampaign(Request $request, string $uuid): JsonResponse
    {
        $this->authorizeAdvertising($request);

        $campaign = $this->campaign($uuid)->load([
            'advertiser', 'categories', 'regions',
            'creatives' => fn ($query) => $query->orderBy('position')->orderBy('id'),
            'creatives.image', 'creatives.mobileImage',
        ]);

        return response()->json(['data' => new AdminAdCampaignResource($campaign)]);
    }

    public function storeCampaign(Request $request): JsonResponse
    {
        $this->authorizeAdvertising($request);

        $validated = $this->validateCampaign($request, creating: true);

        $advertiser = Advertiser::query()
            ->where('uuid', $validated['advertiser_uuid'])
            ->firstOr(fn () => throw ApiException::make(ErrorCode::ValidationFailed, 'That advertiser does not exist.'));

        $campaign = DB::transaction(function () use ($validated, $advertiser, $request): AdCampaign {
            $campaign = new AdCampaign;
            $campaign->fill([
                'advertiser_id' => $advertiser->getKey(),
                'name' => $validated['name'],
                'placement' => $validated['placement'],
                'starts_at' => $validated['starts_at'] ?? null,
                'ends_at' => $validated['ends_at'] ?? null,
                'priority' => $validated['priority'] ?? 0,
                'impression_cap' => $validated['impression_cap'] ?? null,
            ]);

            /*
             * Always created as a DRAFT.
             *
             * A campaign with no creative cannot render, so anything else would
             * mean a slot that is "live" and blank. Going live is a separate,
             * deliberate call to `transition`.
             */
            $campaign->forceFill([
                'status' => AdCampaignStatus::Draft->value,
                'created_by' => $request->user()?->getKey(),
            ])->save();

            $this->syncTargeting($campaign, $validated);

            return $campaign;
        });

        $this->audit->record('ad_campaign.created', $request->user(), $campaign, [], $campaign->getChanges());

        return response()->json(
            ['data' => new AdminAdCampaignResource($campaign->load(['advertiser', 'categories', 'regions']))],
            Response::HTTP_CREATED,
        );
    }

    public function updateCampaign(Request $request, string $uuid): JsonResponse
    {
        $this->authorizeAdvertising($request);

        $campaign = $this->campaign($uuid);
        $validated = $this->validateCampaign($request, creating: false);

        DB::transaction(function () use ($campaign, $validated): void {
            // `status` is guarded, so even if a client sends it, it cannot
            // arrive here. Editing never changes lifecycle state.
            $campaign->fill(array_intersect_key($validated, array_flip([
                'name', 'placement', 'starts_at', 'ends_at', 'priority', 'impression_cap',
            ])))->save();

            $this->syncTargeting($campaign, $validated);
        });

        $this->audit->recordChange('ad_campaign.updated', $request->user(), $campaign);

        return response()->json([
            'data' => new AdminAdCampaignResource($campaign->fresh(['advertiser', 'categories', 'regions'])),
        ]);
    }

    /**
     * Move a campaign through its lifecycle.
     *
     * A dedicated endpoint rather than a field on the PATCH, so going live is
     * always an explicit act with its own audit entry — and so the guard below
     * cannot be bypassed by a field update.
     */
    public function transitionCampaign(Request $request, string $uuid): JsonResponse
    {
        $this->authorizeAdvertising($request);

        $campaign = $this->campaign($uuid);

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                AdCampaignStatus::Draft->value,
                AdCampaignStatus::Scheduled->value,
                AdCampaignStatus::Active->value,
                AdCampaignStatus::Paused->value,
                AdCampaignStatus::Archived->value,
            ])],
        ]);

        $target = AdCampaignStatus::from($validated['status']);
        $previous = $campaign->status;

        /*
         * A campaign with no ACTIVE creative cannot go live.
         *
         * Without this the slot renders nothing while the admin list insists
         * the campaign is active — the operator sees "Active, 0 impressions"
         * and has no way to tell whether that is a delivery problem or an empty
         * campaign. Caught here, they get told which.
         */
        if ($target === AdCampaignStatus::Active || $target === AdCampaignStatus::Scheduled) {
            $hasCreative = $campaign->creatives()->active()->exists();

            if (! $hasCreative) {
                throw ApiException::make(
                    ErrorCode::ValidationFailed,
                    'Add an active creative before putting this campaign live.',
                );
            }
        }

        // Expired is deliberately not settable by hand: it is what the SCHEDULE
        // means, and an operator who wants a campaign stopped now wants Paused
        // — which survives its window and can be resumed without re-dating.
        $campaign->forceFill(['status' => $target->value])->save();

        $this->audit->record(
            'ad_campaign.transitioned',
            $request->user(),
            $campaign,
            ['status' => $previous->value],
            ['status' => $target->value],
        );

        return response()->json([
            'data' => new AdminAdCampaignResource($campaign->fresh(['advertiser', 'categories', 'regions'])),
        ]);
    }

    /**
     * Soft-delete a campaign.
     *
     * Soft, because `ad_impressions_daily` and `ad_clicks` reference it and a
     * hard delete would cascade away the delivery history an advertiser was
     * invoiced against. Archiving is the normal retirement path; this is for
     * campaigns created in error.
     */
    public function destroyCampaign(Request $request, string $uuid): JsonResponse
    {
        $this->authorizeAdvertising($request);

        $campaign = $this->campaign($uuid);
        $campaign->delete();

        $this->audit->record('ad_campaign.deleted', $request->user(), $campaign);

        return response()->json(['data' => ['deleted' => true]]);
    }

    // ------------------------------------------------------------- creatives

    public function storeCreative(Request $request, string $uuid): JsonResponse
    {
        $this->authorizeAdvertising($request);

        $campaign = $this->campaign($uuid);
        $validated = $this->validateCreative($request, creating: true);

        $creative = new AdCreative;
        $creative->fill($validated);
        $creative->ad_campaign_id = $campaign->getKey();
        $creative->save();

        $this->audit->record('ad_creative.created', $request->user(), $creative, [], $creative->getChanges());

        return response()->json(
            ['data' => new AdminAdCreativeResource($creative->load(['image', 'mobileImage']))],
            Response::HTTP_CREATED,
        );
    }

    public function updateCreative(Request $request, string $uuid): JsonResponse
    {
        $this->authorizeAdvertising($request);

        $creative = $this->creative($uuid);
        $validated = $this->validateCreative($request, creating: false);

        $creative->fill($validated)->save();

        $this->audit->recordChange('ad_creative.updated', $request->user(), $creative);

        return response()->json(['data' => new AdminAdCreativeResource($creative->fresh(['image', 'mobileImage']))]);
    }

    public function destroyCreative(Request $request, string $uuid): JsonResponse
    {
        $this->authorizeAdvertising($request);

        $creative = $this->creative($uuid);
        $creative->delete();

        $this->audit->record('ad_creative.deleted', $request->user(), $creative);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * Upload the desktop or mobile artwork for a creative.
     *
     * Separate from the JSON write because it is multipart and goes through the
     * media pipeline — MIME sniffing from magic bytes, EXIF stripping, WebP
     * variant generation — none of which belongs in a field update. Same
     * arrangement as vendor branding.
     */
    public function uploadCreativeImage(Request $request, string $uuid, string $kind): JsonResponse
    {
        $this->authorizeAdvertising($request);

        if (! in_array($kind, ['desktop', 'mobile'], true)) {
            throw ApiException::notFound();
        }

        $request->validate([
            'file' => ['required', 'file', 'max:'.((int) config('saka.media.max_image_mb', 5) * 1024)],
        ]);

        $creative = $this->creative($uuid);

        $media = $this->media->upload(
            $request->file('file'),
            $creative,
            $request->user(),
            MediaCollection::AdCreative,
        );

        $column = $kind === 'desktop' ? 'image_media_id' : 'mobile_media_id';
        $previousId = $creative->{$column};

        $creative->forceFill([$column => $media->getKey()])->save();

        // Replacing artwork leaves the old file orphaned; delete it so an
        // operator iterating on a banner does not accumulate dead rows.
        if ($previousId !== null && $previousId !== $media->getKey()) {
            $previous = Media::find($previousId);

            if ($previous !== null) {
                $this->media->delete($previous);
            }
        }

        return response()->json(['data' => new AdminAdCreativeResource($creative->fresh(['image', 'mobileImage']))]);
    }

    // ---------------------------------------------------- promotion requests

    /**
     * The review queue.
     *
     * Pending first by default, because that is the only state anyone can act
     * on and an operator opening this screen is here to clear it. Drafts are
     * NEVER listed: a draft is a vendor mid-wizard, and showing half-written
     * requests would be showing work in progress belonging to someone else.
     */
    public function indexPromotions(Request $request): JsonResponse
    {
        $this->authorizeAdvertising($request);

        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(PromotionRequestStatus::values())],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $requested = $validated['status'] ?? null;

        $promotions = PromotionRequest::query()
            ->with(['vendor', 'promotable', 'image', 'mobileImage', 'campaign', 'reviewer'])
            ->when(
                $requested !== null,
                fn ($query) => $query->where('status', $requested),
                // Default view excludes drafts rather than showing everything:
                // an unfiltered queue that is 80% other people's unfinished
                // drafts is a queue nobody uses.
                fn ($query) => $query->where('status', '!=', PromotionRequestStatus::Draft->value),
            )
            ->orderByRaw('FIELD(status, ?) DESC', [PromotionRequestStatus::Pending->value])
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 25);

        return AdminPromotionRequestResource::collection($promotions)->response();
    }

    public function approvePromotion(Request $request, string $uuid): JsonResponse
    {
        $this->authorizeAdvertising($request);

        $promotion = $this->promotion($uuid);

        // Every guard lives in the service, which re-verifies ownership,
        // existence, eligibility, artwork and the date window — all of which
        // can have changed since the vendor submitted.
        $campaign = $this->promotions->approve($promotion, $request->user());

        $this->audit->record(
            'promotion.approved',
            $request->user(),
            $promotion,
            [],
            ['ad_campaign_id' => $campaign->getKey(), 'campaign_uuid' => $campaign->uuid],
        );

        return response()->json([
            'data' => new AdminPromotionRequestResource(
                $promotion->fresh(['vendor', 'promotable', 'image', 'mobileImage', 'campaign', 'reviewer']),
            ),
            'meta' => [
                /*
                 * The campaign is a DRAFT. Said explicitly in the payload so the
                 * portal can tell the operator the next step rather than
                 * implying the promotion is now live — approving a request and
                 * putting it on the site are two decisions, and Phase 11A's
                 * lifecycle keeps them separate.
                 */
                'campaign_uuid' => $campaign->uuid,
                'campaign_status' => $campaign->status->value,
                'requires_activation' => true,
            ],
        ]);
    }

    public function rejectPromotion(Request $request, string $uuid): JsonResponse
    {
        $this->authorizeAdvertising($request);

        $validated = $request->validate([
            // Required, with a floor on length. "no" is not a reason, and a
            // vendor who is told only "Rejected" resubmits the same thing.
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $promotion = $this->promotion($uuid);

        $this->promotions->reject($promotion, $request->user(), $validated['reason']);

        $this->audit->record(
            'promotion.rejected',
            $request->user(),
            $promotion,
            [],
            ['reason' => $validated['reason']],
        );

        return response()->json([
            'data' => new AdminPromotionRequestResource(
                $promotion->fresh(['vendor', 'promotable', 'image', 'mobileImage', 'campaign', 'reviewer']),
            ),
        ]);
    }

    // ----------------------------------------------------------- performance

    /**
     * Delivery over a date range, from the rollup table.
     *
     * Read from `ad_impressions_daily` and `ad_clicks` — the same rows the
     * public beacons write — so nothing here is derived, estimated or
     * synthesised. When there is no data the totals are zero and `has_data` is
     * false, which is what lets the UI say "no performance data yet" instead of
     * drawing an authoritative-looking chart of nothing.
     */
    public function performance(Request $request): JsonResponse
    {
        $this->authorizeAdvertising($request);

        $validated = $request->validate([
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
        ]);

        // Thirty days: long enough to show a trend, short enough that the
        // grouped scan stays on the `date` index.
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : Carbon::now()->endOfDay();
        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : (clone $to)->subDays(29)->startOfDay();

        $impressionsByDay = DB::table('ad_impressions_daily')
            ->selectRaw('`date`, SUM(impressions) AS impressions')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $clicksByDay = DB::table('ad_clicks')
            ->selectRaw('DATE(clicked_at) AS `date`, COUNT(*) AS clicks')
            ->whereBetween('clicked_at', [$from, $to])
            ->groupBy(DB::raw('DATE(clicked_at)'))
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $series = $impressionsByDay->map(fn (object $row): array => [
            'date' => (string) $row->date,
            'impressions' => (int) $row->impressions,
            'clicks' => (int) ($clicksByDay[(string) $row->date]->clicks ?? 0),
        ])->values();

        $totalImpressions = (int) $series->sum('impressions');
        $totalClicks = (int) $series->sum('clicks');

        $byPlacement = DB::table('ad_impressions_daily')
            ->selectRaw('placement, SUM(impressions) AS impressions')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('placement')
            ->orderByDesc('impressions')
            ->get()
            ->map(function (object $row) use ($from, $to): array {
                $clicks = (int) DB::table('ad_clicks')
                    ->where('placement', $row->placement)
                    ->whereBetween('clicked_at', [$from, $to])
                    ->count();

                $impressions = (int) $row->impressions;

                return [
                    'placement' => (string) $row->placement,
                    'placement_label' => AdPlacement::from((string) $row->placement)->label(),
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'ctr' => $impressions === 0 ? null : round(($clicks / $impressions) * 100, 2),
                ];
            });

        $topCampaigns = AdCampaign::query()
            ->with('advertiser')
            ->where('impressions_count', '>', 0)
            ->orderByDesc('impressions_count')
            ->limit(10)
            ->get()
            ->map(fn (AdCampaign $campaign): array => [
                'uuid' => $campaign->uuid,
                'name' => $campaign->name,
                'advertiser' => $campaign->advertiser?->name,
                'placement_label' => $campaign->placement->label(),
                'impressions' => $campaign->impressions_count,
                'clicks' => $campaign->clicks_count,
                'ctr' => $campaign->clickThroughRate(),
            ]);

        return response()->json([
            'data' => [
                /*
                 * The flag the UI branches on.
                 *
                 * Zero impressions over a range is a legitimate answer — a
                 * marketplace that has sold no advertising yet — and it must
                 * read as "nothing to show" rather than as a chart asserting
                 * that delivery was flat at zero.
                 */
                'has_data' => $totalImpressions > 0 || $totalClicks > 0,
                'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'totals' => [
                    'impressions' => $totalImpressions,
                    'clicks' => $totalClicks,
                    'ctr' => $totalImpressions === 0
                        ? null
                        : round(($totalClicks / $totalImpressions) * 100, 2),
                ],
                'series' => $series,
                'by_placement' => $byPlacement,
                'top_campaigns' => $topCampaigns,
            ],
        ]);
    }

    // ------------------------------------------------------------- internals

    private function authorizeAdvertising(Request $request): void
    {
        if (! $request->user()?->hasPermission(Permission::AdvertisingManage)) {
            throw ApiException::forbidden();
        }
    }

    private function promotion(string $uuid): PromotionRequest
    {
        return PromotionRequest::query()
            ->where('uuid', $uuid)
            ->firstOr(fn () => throw ApiException::notFound());
    }

    private function campaign(string $uuid): AdCampaign
    {
        return AdCampaign::query()->where('uuid', $uuid)->firstOr(fn () => throw ApiException::notFound());
    }

    private function creative(string $uuid): AdCreative
    {
        return AdCreative::query()->where('uuid', $uuid)->firstOr(fn () => throw ApiException::notFound());
    }

    /** @return array<string, mixed> */
    private function validateCampaign(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        $validated = $request->validate([
            'advertiser_uuid' => [$creating ? 'required' : 'prohibited', 'string'],
            'name' => [$required, 'string', 'min:2', 'max:191'],
            'placement' => [$required, Rule::in(AdPlacement::values())],

            'starts_at' => ['sometimes', 'nullable', 'date'],
            // No `after:now` — an operator legitimately back-dates a campaign
            // that was agreed last week, and refusing that would send them to
            // Tinker, which is what this controller exists to stop.
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],

            'priority' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'impression_cap' => ['sometimes', 'nullable', 'integer', 'min:1'],

            'category_slugs' => ['sometimes', 'nullable', 'array'],
            'category_slugs.*' => ['string', 'exists:categories,slug'],
            'region_slugs' => ['sometimes', 'nullable', 'array'],
            'region_slugs.*' => ['string', 'exists:regions,slug'],
        ], [
            'advertiser_uuid.prohibited' => 'A campaign cannot be moved to a different advertiser; create a new one.',
        ]);

        /*
         * `after:starts_at` only fires when BOTH are in the payload.
         *
         * A PATCH sending only `ends_at` would slip past it and could set an
         * end date before the campaign's stored start — a window that can never
         * open, and a campaign that silently never serves.
         */
        if (! $creating && array_key_exists('ends_at', $validated) && ! array_key_exists('starts_at', $validated)) {
            $existing = $this->campaign((string) $request->route('uuid'));

            if (
                $validated['ends_at'] !== null
                && $existing->starts_at !== null
                && Carbon::parse($validated['ends_at'])->lte($existing->starts_at)
            ) {
                throw ApiException::make(
                    ErrorCode::ValidationFailed,
                    'The end date must be after the campaign start date.',
                    ['ends_at' => ['The end date must be after the campaign start date.']],
                );
            }
        }

        return $validated;
    }

    /** @return array<string, mixed> */
    private function validateCreative(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'headline' => [$required, 'string', 'min:2', 'max:120'],
            'body' => ['sometimes', 'nullable', 'string', 'max:240'],
            'cta_label' => ['sometimes', 'nullable', 'string', 'max:40'],

            /*
             * http/https ONLY, and validated on the way in.
             *
             * This string becomes an `href` on the marketplace, supplied by
             * somebody outside the organisation. `javascript:` in an href is
             * stored XSS with an invoice attached. Same rule as the vendor
             * website field.
             */
            'click_url' => [$required, 'url:http,https', 'max:2048'],

            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);
    }

    /**
     * Replace a campaign's targeting.
     *
     * `sync` rather than `attach`: this is the whole set as the operator sees
     * it, and an omitted key means "leave as-is" while an empty array means
     * "target everywhere". Those are different, so the key's PRESENCE is what
     * is tested, not its truthiness.
     *
     * @param  array<string, mixed>  $validated
     */
    private function syncTargeting(AdCampaign $campaign, array $validated): void
    {
        if (array_key_exists('category_slugs', $validated)) {
            $ids = Category::query()
                ->whereIn('slug', $validated['category_slugs'] ?? [])
                ->pluck('id')
                ->all();

            $campaign->categories()->sync($ids);
        }

        if (array_key_exists('region_slugs', $validated)) {
            $ids = Region::query()
                ->whereIn('slug', $validated['region_slugs'] ?? [])
                ->pluck('id')
                ->all();

            $campaign->regions()->sync($ids);
        }
    }

    private function uniqueAdvertiserSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'advertiser';
        $slug = $base;
        $suffix = 2;

        while (Advertiser::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
