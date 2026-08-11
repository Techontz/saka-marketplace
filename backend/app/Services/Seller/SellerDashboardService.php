<?php

declare(strict_types=1);

namespace App\Services\Seller;

use App\Domain\Listing\Enums\ListingStatus;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\SellerProfile;
use App\Models\User;
use App\Repositories\Contracts\ListingRepositoryInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Everything the seller dashboard needs, in a bounded number of queries.
 *
 * Aggregates are computed with grouped SUM/COUNT rather than by loading the
 * seller's listings and counting in PHP — the difference matters as soon as a
 * seller has more than a page of inventory.
 */
class SellerDashboardService
{
    public function __construct(private readonly ListingRepositoryInterface $listings) {}

    /** @return array<string, mixed> */
    public function forSeller(User $seller): array
    {
        // Short TTL: the dashboard is read far more often than it changes, but
        // a seller must see their own new listing quickly.
        return Cache::remember(
            "seller:{$seller->getKey()}:dashboard",
            now()->addMinutes(5),
            fn (): array => $this->build($seller),
        );
    }

    public function forget(User $seller): void
    {
        Cache::forget("seller:{$seller->getKey()}:dashboard");
    }

    /** @return array<string, mixed> */
    private function build(User $seller): array
    {
        $statusCounts = $this->listings->statusCountsForSeller($seller);

        $totals = Listing::query()
            ->where('user_id', $seller->getKey())
            ->selectRaw('COALESCE(SUM(view_count), 0) as views')
            ->selectRaw('COALESCE(SUM(favorite_count), 0) as favorites')
            ->selectRaw('COALESCE(SUM(inquiry_count), 0) as inquiries')
            ->first();

        $inquiries = Inquiry::query()
            ->where('seller_id', $seller->getKey())
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as unread")
            ->first();

        $viewsLast30 = (int) DB::table('listing_views')
            ->join('listings', 'listings.id', '=', 'listing_views.listing_id')
            ->where('listings.user_id', $seller->getKey())
            ->where('listing_views.viewed_at', '>=', now()->subDays(30))
            ->count();

        $profile = $seller->sellerProfile;

        return [
            'listings' => [
                'total' => array_sum($statusCounts),
                'active' => $statusCounts[ListingStatus::Published->value],
                'draft' => $statusCounts[ListingStatus::Draft->value],
                'pending' => $statusCounts[ListingStatus::PendingReview->value],
                'rejected' => $statusCounts[ListingStatus::Rejected->value],
                'paused' => $statusCounts[ListingStatus::Paused->value],
                'sold' => $statusCounts[ListingStatus::Sold->value],
                'expired' => $statusCounts[ListingStatus::Expired->value],
                'archived' => $statusCounts[ListingStatus::Archived->value],
                'by_status' => $statusCounts,
            ],
            'engagement' => [
                'total_views' => (int) ($totals->views ?? 0),
                'views_last_30_days' => $viewsLast30,
                'total_favorites' => (int) ($totals->favorites ?? 0),
                'total_inquiries' => (int) ($totals->inquiries ?? 0),
                'unread_inquiries' => (int) ($inquiries->unread ?? 0),
            ],
            'verification' => [
                'phone_verified' => $seller->hasVerifiedPhone(),
                'email_verified' => $seller->email_verified_at !== null,
                'can_publish' => $seller->canPublishListings(),
                'seller_verified' => (bool) ($profile?->is_verified ?? false),
                'verification_level' => $profile?->verification_level?->value ?? 'none',
            ],
            'profile_completion' => $this->profileCompletion($seller, $profile),
        ];
    }

    /**
     * Profile completion as an explicit checklist, not just a percentage —
     * a bare "60%" tells a seller nothing about what to do next.
     *
     * @return array<string, mixed>
     */
    private function profileCompletion(User $seller, ?SellerProfile $profile): array
    {
        $checks = [
            'display_name' => $profile?->display_name !== null && $profile->display_name !== '',
            'bio' => $profile?->bio !== null && $profile->bio !== '',
            'logo' => $profile?->logo_media_id !== null,
            'phone_verified' => $seller->hasVerifiedPhone(),
            'email_verified' => $seller->email_verified_at !== null,
            'whatsapp' => $profile?->whatsapp !== null,
            'has_published_listing' => Listing::query()
                ->where('user_id', $seller->getKey())
                ->where('status', ListingStatus::Published)
                ->exists(),
        ];

        $completed = count(array_filter($checks));
        $total = count($checks);

        return [
            'percent' => (int) round(($completed / $total) * 100),
            'completed' => $completed,
            'total' => $total,
            'checklist' => $checks,
            'missing' => array_keys(array_filter($checks, static fn (bool $done) => ! $done)),
        ];
    }
}
