<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\ResolvesVisibleListings;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BusinessResource;
use App\Http\Resources\V1\ListingResource;
use App\Models\Favorite;
use App\Models\Listing;
use App\Models\SellerProfile;
use App\Services\Engagement\FavoriteService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Saved listings and saved businesses.
 *
 * One controller for both, because to a customer they are one feature with a
 * tab. The target type is part of the route rather than a query parameter, so
 * a bad type is a 404 at the router instead of a validation error halfway
 * through a mutation.
 */
class FavoriteController extends Controller
{
    use ResolvesVisibleListings;

    public function __construct(private readonly FavoriteService $favorites) {}

    /** Saved listings. */
    public function listings(Request $request): AnonymousResourceCollection
    {
        $userId = $request->user()->getKey();

        $listings = Listing::query()
            ->publiclyVisible()
            ->join('favorites', fn (JoinClause $join) => $join
                ->on('favorites.favoritable_id', '=', 'listings.id')
                ->where('favorites.favoritable_type', '=', Listing::class)
                ->whereNull('favorites.removed_at')
                ->where('favorites.user_id', '=', $userId))
            ->select('listings.*')
            ->with(['category:id,name,slug,icon,parent_id', 'region:id,name,slug', 'district:id,name,slug', 'primaryMedia'])
            ->orderByDesc('favorites.created_at')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return ListingResource::collection($listings);
    }

    /** Saved businesses. */
    public function businesses(Request $request): AnonymousResourceCollection
    {
        $userId = $request->user()->getKey();

        $businesses = SellerProfile::query()
            ->whereNotNull('slug')
            ->join('favorites', fn (JoinClause $join) => $join
                ->on('favorites.favoritable_id', '=', 'seller_profiles.id')
                ->where('favorites.favoritable_type', '=', SellerProfile::class)
                ->whereNull('favorites.removed_at')
                ->where('favorites.user_id', '=', $userId))
            ->select('seller_profiles.*')
            ->with(['logo', 'region:id,name,slug', 'district:id,name,slug'])
            ->orderByDesc('favorites.created_at')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return BusinessResource::collection($businesses);
    }

    /**
     * Everything ever saved, including what was later removed.
     *
     * This is why un-saving stamps `removed_at` instead of deleting the row —
     * "I saved a flat last month and can't find it again" is a real and common
     * problem, and a delete makes it unanswerable.
     */
    public function history(Request $request): JsonResponse
    {
        $rows = Favorite::query()
            ->where('user_id', $request->user()->getKey())
            ->with('favoritable')
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->integer('per_page', 30), 100));

        return response()->json([
            'data' => collect($rows->items())
                // A target can vanish underneath a favourite — a deleted
                // listing, a closed business. The history row survives; the
                // entry is simply not linkable.
                ->map(fn (Favorite $favorite): array => [
                    'type' => $favorite->favoritable_type === Listing::class ? 'listing' : 'business',
                    'saved_at' => $favorite->created_at?->toAtomString(),
                    'removed_at' => $favorite->removed_at?->toAtomString(),
                    'still_saved' => $favorite->removed_at === null,
                    'target' => $this->describeTarget($favorite->favoritable),
                ])
                ->all(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'from' => $rows->firstItem(),
                'to' => $rows->lastItem(),
            ],
        ]);
    }

    public function storeListing(Request $request, Listing $listing): JsonResponse
    {
        $this->assertListingIsActionable($listing, $request->user());

        return response()->json([
            'data' => ['favorited' => true, 'created' => $this->favorites->add($request->user(), $listing)],
        ]);
    }

    public function destroyListing(Request $request, Listing $listing): JsonResponse
    {
        $this->favorites->remove($request->user(), $listing);

        return response()->json(['data' => ['favorited' => false]]);
    }

    public function storeBusiness(Request $request, string $slug): JsonResponse
    {
        $business = $this->businessOrFail($slug);

        return response()->json([
            'data' => ['favorited' => true, 'created' => $this->favorites->add($request->user(), $business)],
        ]);
    }

    public function destroyBusiness(Request $request, string $slug): JsonResponse
    {
        $this->favorites->remove($request->user(), $this->businessOrFail($slug));

        return response()->json(['data' => ['favorited' => false]]);
    }

    private function businessOrFail(string $slug): SellerProfile
    {
        // Saving is allowed for any business a customer can reach; whether it
        // is listed in the directory is BusinessController's question.
        $business = SellerProfile::query()->where('slug', $slug)->first();

        if ($business === null) {
            throw ApiException::notFound('Business not found.');
        }

        return $business;
    }

    /** @return array<string, mixed>|null */
    private function describeTarget(?Model $target): ?array
    {
        return match (true) {
            $target instanceof Listing => [
                'slug' => $target->slug,
                'title' => $target->title,
                'price' => $target->price,
                'currency' => $target->currency,
                'status' => $target->status->value,
                'image_url' => $target->primaryMedia?->url('card'),
            ],
            $target instanceof SellerProfile => [
                'slug' => $target->slug,
                'title' => $target->display_name,
                'business_type' => $target->business_type?->value,
                'image_url' => $target->logo?->url('card'),
            ],
            default => null,
        };
    }
}
