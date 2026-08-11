<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Advertising\Enums\AdPlacement;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AdCreativeResource;
use App\Models\AdCreative;
use App\Models\Category;
use App\Models\Region;
use App\Services\Advertising\AdMetricsService;
use App\Services\Advertising\AdServingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * SAKA's own advertisements, as the marketplace sees them.
 *
 * THREE endpoints, and the split between them is the whole design:
 *
 *   GET  /ads                      — what should this page show?
 *   POST /ads/{creative}/impression — it was actually SEEN.
 *   POST /ads/{creative}/click      — it was clicked.
 *
 * Serving does NOT count an impression. That separation is the point.
 *
 * A page renders its ad slots server-side, including the three below the fold
 * that most visitors never scroll to. Counting on serve would bill advertisers
 * for those, and the number would be indistinguishable from a real one — the
 * kind of statistic that looks fine on a dashboard and is quietly worthless.
 * The frontend fires the impression beacon from an IntersectionObserver, so
 * what is counted is a VIEWABLE impression: the unit was on screen.
 *
 * The cost is that impressions undercount when a beacon is blocked, which is
 * the right direction to be wrong in. Over-reporting delivery to someone paying
 * for it is not a rounding error, it is a refund.
 */
class AdController extends Controller
{
    public function __construct(
        private readonly AdServingService $serving,
        private readonly AdMetricsService $metrics,
    ) {}

    /**
     * The advertisements for one placement, in render order.
     *
     * Returns `data: []` rather than 404 when nothing is eligible — no
     * inventory sold against a slot is the NORMAL state, not an error, and a
     * 404 here would light up error tracking on a healthy site.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'placement' => ['required', Rule::in(AdPlacement::values())],
            // Slugs, not ids: the frontend has slugs in the URL and never sees
            // an id. `exists` is omitted deliberately — an unknown slug means
            // "no category context", not a validation error that would break
            // the page over an advert.
            'category' => ['sometimes', 'nullable', 'string', 'max:120'],
            'region' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $placement = AdPlacement::from($validated['placement']);

        $category = filled($validated['category'] ?? null)
            ? Category::query()->where('slug', $validated['category'])->first()
            : null;

        $regionId = filled($validated['region'] ?? null)
            ? Region::query()->where('slug', $validated['region'])->value('id')
            : null;

        $creatives = $this->serving->serve($placement, $category, $regionId);

        // Eager-loaded here rather than in the service: the service decides
        // WHICH creatives, the resource decides what a creative looks like, and
        // `campaign.advertiser` is only needed for the "Sponsored by" line.
        $creatives->loadMissing(['campaign.advertiser', 'image', 'mobileImage']);

        return response()->json([
            'data' => AdCreativeResource::collection($creatives),
            'meta' => ['placement' => $placement->toArray()],
        ]);
    }

    /**
     * The unit was on screen.
     *
     * Accepts any creative by uuid without re-checking eligibility. A campaign
     * can expire in the seconds between the page rendering and the visitor
     * scrolling to the slot, and refusing that impression would be wrong: it
     * WAS displayed, by us, and the advertiser is owed the count.
     */
    public function impression(Request $request, string $uuid): Response
    {
        $validated = $request->validate([
            'placement' => ['required', Rule::in(AdPlacement::values())],
        ]);

        $creative = AdCreative::query()->where('uuid', $uuid)->first();

        // 204 even when unknown. This is a fire-and-forget beacon from a page
        // that is already rendered; a 404 here would surface as a console error
        // on a visitor's screen for an advert that has since been deleted.
        if ($creative !== null) {
            $this->metrics->recordImpression($creative, AdPlacement::from($validated['placement']));
        }

        return response()->noContent();
    }

    /**
     * The unit was clicked.
     *
     * A beacon, not a redirect. The anchor in the page points at the
     * advertiser's real URL, so the destination is visible in the status bar
     * before the visitor commits and the link still works with JavaScript
     * disabled. Routing the navigation through the API would hide where the
     * click goes, which is the pattern people have learned to distrust.
     */
    public function click(Request $request, string $uuid): Response
    {
        $validated = $request->validate([
            'placement' => ['required', Rule::in(AdPlacement::values())],
        ]);

        $creative = AdCreative::query()->where('uuid', $uuid)->first();

        if ($creative !== null) {
            $this->metrics->recordClick(
                $creative,
                AdPlacement::from($validated['placement']),
                $request->user()?->getKey(),
                // Hashed with the app key, never stored raw — enough to spot
                // one machine clicking four hundred times, not enough to
                // identify a person. Same treatment as `listing_views`.
                hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
                $request->headers->get('referer'),
            );
        }

        return response()->noContent();
    }
}
