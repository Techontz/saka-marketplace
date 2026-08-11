<?php

declare(strict_types=1);

namespace App\Services\Advertising;

use App\Domain\Advertising\Enums\AdPlacement;
use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Decides which advertisements a given page should show.
 *
 * The one place the rules live. Every public surface asks this service; none of
 * them carries its own idea of what "eligible" means, which is how an ad system
 * ends up serving expired campaigns on one page and not the other.
 *
 * The decision, in order:
 *
 *   1. the campaign is servable — active, inside its window, under its cap
 *      (see AdCampaign::scopeServable, which reads the DATES, not the cached
 *      status column);
 *   2. its targeting matches the page;
 *   3. highest priority wins, ties broken on id so the order is total;
 *   4. take at most what the placement can show;
 *   5. one creative per campaign, chosen to even out delivery.
 */
class AdServingService
{
    /**
     * The creatives to render in `$placement`.
     *
     * `$category` and `$region` describe the PAGE, not the campaign — the
     * category being browsed, the region being filtered on. Null means the page
     * has no such context (the homepage has no category), in which case only
     * untargeted campaigns are eligible: an ad bought specifically against
     * "vehicles" has not been bought against everything.
     *
     * @return EloquentCollection<int, AdCreative>
     */
    public function serve(
        AdPlacement $placement,
        ?Category $category = null,
        ?int $regionId = null,
    ): EloquentCollection {
        $campaigns = AdCampaign::query()
            ->servable()
            ->where('placement', $placement->value)
            ->tap(fn (Builder $query) => $this->applyCategoryTargeting($query, $category))
            ->tap(fn (Builder $query) => $this->applyRegionTargeting($query, $regionId))
            ->with(['advertiser'])
            /*
             * Priority DESC then id ASC.
             *
             * The id tiebreak is not cosmetic: without a total order, two
             * campaigns at the same priority swap places between requests
             * depending on how MySQL feels about the index that day. The
             * advertiser who paid for the top slot sees themselves in it
             * half the time and does not renew.
             */
            ->orderByDesc('priority')
            ->orderBy('id')
            ->limit($placement->maxConcurrent())
            ->get();

        /*
         * An ELOQUENT collection, built explicitly.
         *
         * `collect()` and `->map()` both hand back a base Collection here — map
         * degrades the class the moment the callback can return null, which it
         * can — and callers legitimately want `loadMissing()` on the result.
         * Constructing it makes the return type honest for every path,
         * including the empty one.
         *
         * A campaign whose creatives are all inactive or deleted is SKIPPED,
         * not rendered as an empty box. It is a real state — an administrator
         * deactivates a creative to swap the artwork — and the slot must
         * collapse rather than reserve space for nothing.
         */
        $chosen = [];

        foreach ($campaigns as $campaign) {
            $creative = $this->pickCreative($campaign);

            if ($creative !== null) {
                $chosen[] = $creative;
            }
        }

        return new EloquentCollection($chosen);
    }

    /**
     * Targeting: no rows means everywhere.
     *
     * The ancestor walk is the subtle part. A campaign bought against
     * "property" must appear on `property-apartments`, because that is what the
     * advertiser believes they bought — nobody purchasing the property vertical
     * expects to be absent from every subcategory in it. `Category::pathIds()`
     * returns the materialised ancestor chain INCLUDING self, so one
     * `whereIn` covers the whole lineage without a recursive query.
     *
     * @param  Builder<AdCampaign>  $query
     */
    private function applyCategoryTargeting(Builder $query, ?Category $category): void
    {
        $query->where(function (Builder $outer) use ($category): void {
            // Untargeted campaigns run everywhere.
            $outer->whereDoesntHave('categories');

            if ($category === null) {
                return;
            }

            $lineage = $category->pathIds() ?: [$category->getKey()];

            $outer->orWhereHas(
                'categories',
                fn (Builder $q) => $q->whereIn('categories.id', $lineage),
            );
        });
    }

    /**
     * Regions are flat, so this is a plain membership test.
     *
     * @param  Builder<AdCampaign>  $query
     */
    private function applyRegionTargeting(Builder $query, ?int $regionId): void
    {
        $query->where(function (Builder $outer) use ($regionId): void {
            $outer->whereDoesntHave('regions');

            if ($regionId === null) {
                return;
            }

            $outer->orWhereHas(
                'regions',
                fn (Builder $q) => $q->where('regions.id', $regionId),
            );
        });
    }

    /**
     * One creative from a campaign's rotation.
     *
     * Least-shown first, rather than random. Random rotation over a small
     * number of creatives is visibly lumpy at low volume — one creative can
     * take 70% of a day's impressions — and it makes A/B results useless and
     * tests non-deterministic. Ordering by the impression counter is
     * self-balancing: whichever creative is behind gets the next impression,
     * and the split converges on even without any scheduling.
     *
     * `position` then `id` break the tie at zero impressions, so a fresh
     * campaign starts on the creative the administrator put first.
     */
    private function pickCreative(AdCampaign $campaign): ?AdCreative
    {
        return $campaign->creatives()
            ->active()
            ->orderBy('impressions_count')
            ->orderBy('position')
            ->orderBy('id')
            ->with(['image', 'mobileImage'])
            ->first();
    }
}
