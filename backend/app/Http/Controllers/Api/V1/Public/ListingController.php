<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Listing\IndexListingRequest;
use App\Http\Resources\V1\ListingResource;
use App\Models\Listing;
use App\Repositories\Contracts\ListingRepositoryInterface;
use App\Services\Engagement\FavoriteService;
use App\Services\Engagement\ListingViewService;
use App\Services\Search\SearchHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The public read surface. No authentication required — browsing is open to
 * guests (Milestone 4 decision 5); only publishing is gated.
 */
class ListingController extends Controller
{
    public function __construct(
        private readonly ListingRepositoryInterface $listings,
        private readonly ListingViewService $views,
        private readonly FavoriteService $favorites,
        private readonly SearchHistoryService $searchHistory,
    ) {}

    /**
     * Browse / search / filter.
     *
     * Offset pagination by default because the current frontend renders
     * numbered pages; `?cursor=` switches to cursor pagination, which is what
     * mobile and infinite scroll should use since it stays stable when new
     * listings are published mid-scroll.
     */
    public function index(IndexListingRequest $request): AnonymousResourceCollection
    {
        $filters = $request->toFilterData();
        $viewer = $request->user();

        $results = $request->wantsCursorPagination()
            ? $this->listings->cursorPaginate($filters, $viewer)
            : $this->listings->paginate($filters, $viewer);

        /*
         * Keyword searches are recorded here rather than in a dedicated
         * endpoint, so history and the popular list reflect what people
         * actually searched — including from a bookmarked URL or a shared link,
         * neither of which would go through a "record my search" call.
         *
         * Only the first page counts: paging through results is one search, not
         * five, and counting each page would let a single user dominate the
         * popular list by scrolling.
         */
        $keyword = $request->string('q')->trim()->value();

        if ($keyword !== '' && (int) $request->integer('page', 1) === 1) {
            $this->searchHistory->record(
                $viewer,
                $request->hasSession() ? $request->session()->getId() : null,
                $keyword,
                array_diff_key($request->validated(), array_flip(['q', 'page', 'per_page', 'cursor'])),
                $results->total(),
            );
        }

        return ListingResource::collection($results);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $listing = $this->listings->findBySlug($slug, $request->user());

        if ($listing === null) {
            // 404 rather than 403 for an unpublished listing — never disclose
            // that a resource exists.
            throw ApiException::notFound('Listing not found.');
        }

        // Off the request path; a slow analytics write must not slow the page.
        $this->views->record($listing, $request, $request->user());

        return response()->json([
            'data' => (new ListingResource($listing))->detailed(),
            'meta' => [
                'is_favorited' => $request->user() !== null
                    && $this->favorites->isFavorited($request->user(), $listing),
            ],
        ]);
    }

    public function similar(Request $request, string $slug): AnonymousResourceCollection
    {
        $listing = $this->listings->findBySlug($slug, $request->user());

        if ($listing === null) {
            throw ApiException::notFound('Listing not found.');
        }

        return ListingResource::collection($this->listings->similarTo($listing, 4));
    }

    public function trending(): AnonymousResourceCollection
    {
        return ListingResource::collection($this->listings->trending(8));
    }

    public function featured(): AnonymousResourceCollection
    {
        return ListingResource::collection($this->listings->featured(8));
    }

    public function recommended(Request $request): AnonymousResourceCollection
    {
        return ListingResource::collection(
            $this->listings->recommendedFor($request->user(), 4),
        );
    }
}
