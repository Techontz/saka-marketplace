<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ListingResource;
use App\Services\Search\SearchHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What this customer has been doing: recent searches, recently viewed.
 *
 * Both are conveniences, and both are private by construction — scoped to the
 * caller with no parameter that could widen them.
 */
class ActivityController extends Controller
{
    public function __construct(private readonly SearchHistoryService $history) {}

    public function searchHistory(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->history->historyFor(
                $request->user(),
                min((int) $request->integer('limit', 20), 50),
            ),
        ]);
    }

    public function clearSearchHistory(Request $request): JsonResponse
    {
        $removed = $this->history->clearHistoryFor($request->user());

        return response()->json(['data' => ['cleared' => $removed]]);
    }

    public function recentlyViewed(Request $request): JsonResponse
    {
        $listings = $this->history->recentlyViewed(
            $request->user(),
            min((int) $request->integer('limit', 12), 40),
        );

        return response()->json(['data' => ListingResource::collection($listings)]);
    }
}
