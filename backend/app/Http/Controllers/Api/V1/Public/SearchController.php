<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Listing;
use App\Models\Region;
use App\Models\SellerProfile;
use App\Services\Search\SearchHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The search box itself: what to suggest as someone types, and what is popular.
 *
 * Suggestions are drawn from FOUR sources — listings, businesses, categories
 * and places — because a customer typing "masaki" may mean a neighbourhood, and
 * one typing "toyota" means stock. Returning only listing titles would make the
 * box feel broken for half the things people search for.
 */
class SearchController extends Controller
{
    public function __construct(private readonly SearchHistoryService $history) {}

    public function suggestions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $term = trim($validated['q']);
        $limit = (int) ($validated['limit'] ?? 5);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        // Short cache: suggestions are hit on every keystroke, and the same
        // prefixes recur constantly across users.
        $payload = Cache::remember(
            'search:suggest:'.md5(mb_strtolower($term)).":{$limit}",
            now()->addMinutes(10),
            fn (): array => [
                'listings' => Listing::query()
                    ->publiclyVisible()
                    ->where('title', 'like', $like)
                    ->orderByDesc('view_count')
                    ->limit($limit)
                    ->get(['slug', 'title'])
                    ->map(fn (Listing $listing): array => [
                        'type' => 'listing',
                        'label' => $listing->title,
                        'slug' => $listing->slug,
                    ])->all(),

                'businesses' => SellerProfile::query()
                    ->whereNotNull('onboarding_completed_at')
                    ->where('display_name', 'like', $like)
                    ->orderByDesc('active_listings')
                    ->limit($limit)
                    ->get(['slug', 'display_name'])
                    ->map(fn (SellerProfile $business): array => [
                        'type' => 'business',
                        'label' => $business->display_name,
                        'slug' => $business->slug,
                    ])->all(),

                'categories' => Category::query()
                    ->where('is_active', true)
                    ->where('name', 'like', $like)
                    ->orderByDesc('listing_count')
                    ->limit($limit)
                    ->get(['slug', 'name'])
                    ->map(fn (Category $category): array => [
                        'type' => 'category',
                        'label' => $category->name,
                        'slug' => $category->slug,
                    ])->all(),

                'places' => Region::query()
                    ->where('name', 'like', $like)
                    ->limit($limit)
                    ->get(['slug', 'name'])
                    ->map(fn ($region): array => [
                        'type' => 'place',
                        'label' => $region->name,
                        'slug' => $region->slug,
                    ])->all(),
            ],
        );

        return response()->json(['data' => $payload]);
    }

    /** What everyone is searching for. Drives the homepage chips. */
    public function popular(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        return response()->json([
            'data' => $this->history->popular((int) ($validated['limit'] ?? 8)),
        ]);
    }
}
