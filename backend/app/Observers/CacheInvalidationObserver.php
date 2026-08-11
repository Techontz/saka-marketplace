<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Amenity;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\Facility;
use App\Models\Faq;
use App\Models\Listing;
use App\Models\Page;
use App\Models\PublicPlace;
use App\Models\PublicPlaceCategory;
use App\Models\Setting;
use App\Support\Cache\CacheKeys;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Invalidates caches on write.
 *
 * Previously these caches were TTL-only, so an admin editing a category waited
 * up to 24 hours to see it — the classic "did my change save?" support ticket.
 *
 * Registered for every model whose data feeds a cached response. `saved` and
 * `deleted` both fire so a soft delete invalidates too.
 */
class CacheInvalidationObserver
{
    /*
     * created/updated/deleted rather than a single saved() hook.
     *
     * saved() cannot tell an insert from an update, and `wasRecentlyCreated`
     * does not help: it stays true for the LIFETIME of the model instance, so
     * a create-then-update in one request looks like a create on both passes.
     * Splitting the hooks is what makes "only flush discovery when something
     * discovery-facing actually changed" expressible at all.
     */

    public function created(Model $model): void
    {
        $this->invalidate($model, alwaysFlushDiscovery: true);
    }

    public function updated(Model $model): void
    {
        $this->invalidate($model, alwaysFlushDiscovery: false);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model, alwaysFlushDiscovery: true);
    }

    public function restored(Model $model): void
    {
        $this->invalidate($model, alwaysFlushDiscovery: true);
    }

    private function invalidate(Model $model, bool $alwaysFlushDiscovery): void
    {
        match (true) {
            $model instanceof Category,
            $model instanceof Attribute,
            $model instanceof AttributeOption,
            $model instanceof Amenity,
            $model instanceof Facility => CacheKeys::flushTaxonomy(),

            $model instanceof Faq,
            $model instanceof Page,
            $model instanceof Setting,
            $model instanceof PublicPlace,
            $model instanceof PublicPlaceCategory => CacheKeys::flushContent(),

            $model instanceof Listing => $this->invalidateListing($model, $alwaysFlushDiscovery),

            default => null,
        };
    }

    /**
     * Columns that change what discovery surfaces show.
     *
     * `status`/`is_featured`/`featured_until`/`popularity_score` decide WHETHER
     * a listing appears; `category_id`/`price`/`title` decide whether a
     * recommendation still makes sense once it does.
     *
     * Deliberately absent: view_count, favorite_count, inquiry_count. Those are
     * written constantly on exactly the listings most likely to be in the
     * trending cache, so including them would leave discovery effectively
     * uncached under the load the cache exists to absorb.
     */
    private const DISCOVERY_COLUMNS = [
        'status',
        'is_featured',
        'featured_until',
        'popularity_score',
        'category_id',
        'price',
        'title',
    ];

    /** A listing write can change discovery surfaces and the owner's dashboard. */
    private function invalidateListing(Listing $listing, bool $alwaysFlushDiscovery): void
    {
        // An exact key, so forget it directly — routing it through the wildcard
        // helper both hand-built the key string (which drifts from
        // CacheKeys::sellerDashboard()) and silently no-ops on non-Redis stores.
        // The dashboard DOES show view counts, so it is flushed on every write.
        Cache::forget(CacheKeys::sellerDashboard((int) $listing->user_id));

        if ($alwaysFlushDiscovery || $listing->wasChanged(self::DISCOVERY_COLUMNS)) {
            CacheKeys::flushDiscovery();
        }
    }
}
