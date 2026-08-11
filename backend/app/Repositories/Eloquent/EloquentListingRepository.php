<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Domain\Listing\DataTransferObjects\ListingFilterData;
use App\Domain\Listing\Enums\ListingStatus;
use App\Http\Filters\Listing\AmenityFilter;
use App\Http\Filters\Listing\AttributeFilter;
use App\Http\Filters\Listing\CategoryFilter;
use App\Http\Filters\Listing\ConditionFilter;
use App\Http\Filters\Listing\GeoRadiusFilter;
use App\Http\Filters\Listing\KeywordFilter;
use App\Http\Filters\Listing\ListingQuery;
use App\Http\Filters\Listing\LocationFilter;
use App\Http\Filters\Listing\PriceFilter;
use App\Http\Filters\Listing\PurposeFilter;
use App\Http\Filters\Listing\SellerFilter;
use App\Http\Filters\Listing\SortStage;
use App\Http\Filters\Listing\VerifiedFilter;
use App\Http\Filters\Listing\VisibilityScope;
use App\Models\Listing;
use App\Models\User;
use App\Repositories\Contracts\ListingRepositoryInterface;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentListingRepository implements ListingRepositoryInterface
{
    /**
     * Relations every list response needs.
     *
     * Eager-loaded once here rather than per-Resource: without this, a 20-item
     * page issues 100+ queries. `primaryMedia` is a morphOne so it stays one
     * extra query regardless of page size.
     */
    private const LIST_RELATIONS = [
        'category:id,name,slug,icon,parent_id',
        // The vertical a listing belongs to ("Property"), as distinct from its
        // leaf category ("Apartments"). Clients group by it; deriving it from
        // the slug prefix instead would break the moment a category is renamed.
        'category.parent:id,name,slug,icon',
        'region:id,name,slug',
        'district:id,name,slug',
        'ward:id,name,slug',
        'primaryMedia',
    ];

    private const DETAIL_RELATIONS = [
        'category',
        // The vertical, same as on the list shape. Its absence here meant the
        // detail response always reported `category.parent: null`, so every
        // client that groups or themes by vertical silently fell back to a
        // default on the ONE page where it matters most.
        'category.parent:id,name,slug,icon',
        'region', 'district', 'ward',
        // Land parcel outline. Null for every non-land listing, and a single
        // keyed row when present, so this costs one extra query on a page that
        // already runs several.
        'boundary',
        'media',
        'amenities:id,name,slug,icon',
        'facilities:id,name,slug,icon',
        'attributeValues.attribute',
        'attributeValues.option',
        'user:id,uuid,first_name,last_name,phone,created_at',
        'user.sellerProfile',
    ];

    public function paginate(ListingFilterData $filters, ?User $viewer): LengthAwarePaginator
    {
        return $this->pipeline($filters, $viewer)
            ->with(self::LIST_RELATIONS)
            ->paginate($filters->perPage)
            ->withQueryString();
    }

    public function cursorPaginate(ListingFilterData $filters, ?User $viewer): CursorPaginator
    {
        return $this->pipeline($filters, $viewer)
            ->with(self::LIST_RELATIONS)
            ->cursorPaginate($filters->perPage)
            ->withQueryString();
    }

    public function findBySlug(string $slug, ?User $viewer): ?Listing
    {
        $query = Listing::query()->where('listings.slug', $slug);

        $result = app(Pipeline::class)
            ->send(new ListingQuery($query, new ListingFilterData))
            ->through([new VisibilityScope($viewer)])
            ->thenReturn();

        return $result->builder->with(self::DETAIL_RELATIONS)->first();
    }

    public function findByUuidForOwner(string $uuid, User $owner): ?Listing
    {
        return Listing::query()
            ->where('uuid', $uuid)
            ->where('user_id', $owner->getKey())
            ->with(self::DETAIL_RELATIONS)
            ->first();
    }

    public function similarTo(Listing $listing, int $limit): Collection
    {
        return Listing::query()
            ->publiclyVisible()
            ->where('category_id', $listing->category_id)
            ->whereKeyNot($listing->getKey())
            ->with(self::LIST_RELATIONS)
            ->orderByDesc('is_featured')
            ->orderByDesc('popularity_score')
            ->limit($limit)
            ->get();
    }

    public function trending(int $limit): Collection
    {
        return Listing::query()
            ->publiclyVisible()
            ->with(self::LIST_RELATIONS)
            ->orderByDesc('popularity_score')
            ->orderByDesc('view_count')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }

    public function featured(int $limit): Collection
    {
        return Listing::query()
            ->publiclyVisible()
            ->featured()
            ->with(self::LIST_RELATIONS)
            ->orderByDesc('boost_score')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Recommendations.
     *
     * Deliberately simple and honest: with no behavioural data yet, this biases
     * toward the categories a signed-in user has actually favourited and falls
     * back to popularity. A real recommender is v2.0 and needs the interaction
     * history that only MVP traffic can produce.
     */
    public function recommendedFor(?User $viewer, int $limit): Collection
    {
        $query = Listing::query()->publiclyVisible()->with(self::LIST_RELATIONS);

        if ($viewer !== null) {
            // Favourites are polymorphic, so the type constraint is what keeps
            // a saved BUSINESS out of a listing-category recommendation.
            $categoryIds = DB::table('favorites')
                ->join('listings', 'listings.id', '=', 'favorites.favoritable_id')
                ->where('favorites.favoritable_type', Listing::class)
                ->whereNull('favorites.removed_at')
                ->where('favorites.user_id', $viewer->getKey())
                ->distinct()
                ->pluck('listings.category_id')
                ->all();

            if ($categoryIds !== []) {
                $query->whereIn('category_id', $categoryIds)
                    // Already saved is already known: recommending it back is
                    // the most common way a recommender looks broken.
                    ->whereNotIn('listings.id', function ($q) use ($viewer): void {
                        $q->select('favoritable_id')->from('favorites')
                            ->where('favoritable_type', Listing::class)
                            ->whereNull('removed_at')
                            ->where('user_id', $viewer->getKey());
                    });
            }
        }

        return $query
            ->orderByDesc('is_featured')
            ->orderByDesc('popularity_score')
            ->limit($limit)
            ->get();
    }

    /** @return array<string, int> */
    public function statusCountsForSeller(User $seller): array
    {
        $counts = Listing::query()
            ->where('user_id', $seller->getKey())
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->all();

        // Always return every status so the dashboard has a stable shape.
        $result = [];
        foreach (ListingStatus::cases() as $status) {
            $result[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $result;
    }

    /** Builds and runs the full filter pipeline. */
    private function pipeline(ListingFilterData $filters, ?User $viewer): Builder
    {
        $payload = new ListingQuery(Listing::query(), $filters);

        return app(Pipeline::class)
            ->send($payload)
            ->through([
                new VisibilityScope($viewer),   // always first
                app(KeywordFilter::class),
                app(CategoryFilter::class),
                app(LocationFilter::class),
                app(PriceFilter::class),
                app(PurposeFilter::class),
                app(ConditionFilter::class),
                app(VerifiedFilter::class),
                app(AmenityFilter::class),
                app(AttributeFilter::class),
                app(GeoRadiusFilter::class),
                app(SellerFilter::class),
                app(SortStage::class),          // always last
            ])
            ->thenReturn()
            ->builder;
    }
}
