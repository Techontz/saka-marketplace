<?php

declare(strict_types=1);

namespace App\Providers;

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
use App\Observers\CacheInvalidationObserver;
use App\Repositories\Contracts\ListingRepositoryInterface;
use App\Repositories\Eloquent\EloquentListingRepository;
use App\Services\Identity\IdentityVerificationProvider;
use App\Services\Identity\ManualReviewProvider;
use App\Services\Search\Contracts\SearchDriver;
use App\Services\Search\SearchService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Identity verification is MANUAL. See ManualReviewProvider — NIDA
         * publishes no integration a marketplace can call, so every check is a
         * person reading a document. This binding is the seam: an official
         * provider replaces the concrete class here and nothing else changes.
         */
        $this->app->singleton(IdentityVerificationProvider::class, ManualReviewProvider::class);

        // Resolve the search engine from config. Introducing Meilisearch means
        // adding a class and flipping SAKA_SEARCH_DRIVER — nothing else.
        $this->app->singleton(SearchDriver::class, function (): SearchDriver {
            $key = (string) config('saka.search.driver');
            $class = config("saka.search.drivers.{$key}");

            if (! is_string($class) || ! class_exists($class)) {
                throw new InvalidArgumentException("Unknown search driver [{$key}].");
            }

            return $this->app->make($class);
        });

        $this->app->singleton(SearchService::class);

        // Controllers depend on the CONTRACT; swapping the persistence layer
        // (or stubbing it in a test) never touches a controller.
        $this->app->bind(ListingRepositoryInterface::class, EloquentListingRepository::class);
    }

    public function boot(): void
    {
        // Fail loudly in development when a relation is accessed lazily or a
        // non-fillable attribute is assigned. Silent N+1s and silently-dropped
        // attributes are exactly the bugs that reach production otherwise.
        Model::shouldBeStrict($this->app->isLocal());

        // Also enforced under test: assigning a non-fillable attribute should
        // fail loudly, not be silently dropped. That exact silent drop hid a
        // bug in this milestone's OTP fixtures.
        Model::preventSilentlyDiscardingAttributes(
            $this->app->isLocal() || $this->app->runningUnitTests()
        );

        /*
         * Never let a stray query run against a replica-less production DB in
         * an unexpected shape.
         *
         * Enforced under test as well as locally. It was off in testing, and
         * that blind spot let a missing eager load on the seller listing
         * endpoints pass the whole suite and then fail on the first real
         * request: the resource renders an attribute's option label, and only
         * `attributeValues.attribute` was loaded.
         */
        Model::preventLazyLoading($this->app->isLocal() || $this->app->runningUnitTests());

        // Cached responses are invalidated on write rather than waiting for a
        // TTL, so an admin edit is visible immediately.
        foreach ([
            Category::class, Attribute::class, AttributeOption::class,
            Amenity::class, Facility::class,
            Faq::class, Page::class, Setting::class,
            PublicPlace::class, PublicPlaceCategory::class,
            Listing::class,
        ] as $model) {
            $model::observe(CacheInvalidationObserver::class);
        }
    }
}
