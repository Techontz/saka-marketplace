<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Domain\Identity\Enums\RoleSlug;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Listing\Enums\ListingStatus;
use App\Domain\Trust\Enums\VerificationStatus;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The numbers behind the admin dashboard and analytics screens.
 *
 * WHY THIS IS A SERVICE AND NOT A PILE OF CONTROLLER QUERIES
 *
 * The dashboard shows eleven counters. Written naively that is eleven round
 * trips before the page renders, and each one grows with the table. Two things
 * keep it cheap:
 *
 *   - counters that come from the same table are gathered in ONE query with
 *     conditional aggregates, so the listing tiles cost one scan, not five;
 *   - the whole payload is cached briefly by the controller. A dashboard is
 *     read constantly and refreshed by every open tab; a 60-second-old
 *     "pending listings" count is not a problem, and pretending otherwise
 *     makes the dashboard the heaviest page on the platform.
 *
 * TIME SERIES ARE GAP-FILLED. `GROUP BY date` returns no row for a day with no
 * activity, so a chart built straight from SQL silently draws a line from the
 * 3rd to the 7th as though the 4th–6th did not exist. Every series here is
 * padded to one point per day.
 */
class AdminStatsService
{
    /**
     * Headline counters for the dashboard tiles.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $listings = $this->listingCounters();
        $users = $this->userCounters();

        return [
            'users' => [
                // Cast at the boundary: MySQL returns COUNT/SUM as STRINGS, so
                // without this the dashboard gets {"active": "6"} and any
                // client-side arithmetic concatenates instead of adding.
                'total' => (int) $users->total,
                'active' => (int) $users->active,
                'suspended' => (int) $users->suspended,
                'pending' => (int) $users->pending,
                // "Vendors" in the UI; `seller` in the role matrix.
                'vendors' => $this->roleCount(RoleSlug::Seller),
                'admins' => $this->roleCount(RoleSlug::Admin) + $this->roleCount(RoleSlug::SuperAdmin),
                'moderators' => $this->roleCount(RoleSlug::Moderator),
                'new_this_week' => (int) DB::table('users')
                    ->whereNull('deleted_at')
                    ->where('created_at', '>=', now()->subWeek())
                    ->count(),
            ],

            'listings' => [
                'total' => (int) $listings->total,
                'published' => (int) $listings->published,
                'pending' => (int) $listings->pending,
                'rejected' => (int) $listings->rejected,
                'draft' => (int) $listings->draft,
                'archived' => (int) $listings->archived,
                'expired' => (int) $listings->expired,
                'sold' => (int) $listings->sold,
                'featured' => (int) $listings->featured,
                'verified' => (int) $listings->verified,
                'new_this_week' => (int) $listings->new_this_week,
            ],

            'engagement' => $this->engagementCounters(),

            'catalog' => [
                'categories' => (int) DB::table('categories')->count(),
                'active_categories' => (int) DB::table('categories')->where('is_active', true)->count(),
                'attributes' => (int) DB::table('attributes')->count(),
                'amenities' => (int) DB::table('amenities')->count(),
                'facilities' => (int) DB::table('facilities')->count(),
                'public_places' => (int) DB::table('public_places')->count(),
            ],

            'verifications' => [
                'pending' => (int) DB::table('verification_requests')
                    ->where('status', VerificationStatus::Pending->value)->count(),
                'approved' => (int) DB::table('verification_requests')
                    ->where('status', VerificationStatus::Approved->value)->count(),
                'rejected' => (int) DB::table('verification_requests')
                    ->where('status', VerificationStatus::Rejected->value)->count(),
            ],

            /*
             * Revenue is a PLACEHOLDER, and is reported as such rather than as
             * a zero. Payments are v2.0; there is no orders table, no ledger
             * and no currency handling. A tile reading "TZS 0" would look like
             * a platform that has taken no money, not one that does not yet
             * take money — and that is the kind of number people screenshot.
             */
            'revenue' => [
                'available' => false,
                'reason' => 'Payments land in v2.0. No orders, ledger or settlement data exists yet.',
            ],
        ];
    }

    /**
     * Daily time series for the dashboard charts.
     *
     * @return array<string, mixed>
     */
    public function growth(int $days = 30): array
    {
        $since = now()->subDays($days - 1)->startOfDay();

        return [
            'range' => [
                'from' => $since->toDateString(),
                'to' => now()->toDateString(),
                'days' => $days,
            ],
            'listings' => $this->dailySeries('listings', 'created_at', $since, $days),
            'published_listings' => $this->dailySeries('listings', 'published_at', $since, $days),
            'users' => $this->dailySeries('users', 'created_at', $since, $days),
            'vendors' => $this->vendorSeries($since, $days),
            'inquiries' => $this->dailySeries('inquiries', 'created_at', $since, $days),
            'reviews' => $this->dailySeries('reviews', 'created_at', $since, $days),
            'favorites' => $this->dailySeries('favorites', 'created_at', $since, $days),
            'views' => $this->viewSeries($since, $days),
        ];
    }

    /**
     * Listing counts per top-level category, for the popularity chart.
     *
     * @return array<int, array<string, mixed>>
     */
    public function categoryPopularity(): array
    {
        return DB::table('categories')
            ->whereNull('parent_id')
            ->orderByDesc('listing_count')
            ->get(['name', 'slug', 'icon', 'listing_count'])
            ->map(fn (object $row): array => [
                'name' => $row->name,
                'slug' => $row->slug,
                'icon' => $row->icon,
                // Maintained by saka:taxonomy:recount, so it is subtree-aware
                // and already excludes unpublished listings.
                'listings' => (int) $row->listing_count,
            ])
            ->all();
    }

    /**
     * Sellers ranked by reach, for the "most active vendors" table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function topVendors(int $limit = 10): array
    {
        $publiclyVisible = array_map(
            fn (ListingStatus $s): string => $s->value,
            ListingStatus::publiclyVisible(),
        );

        return DB::table('listings')
            ->join('users', 'users.id', '=', 'listings.user_id')
            ->leftJoin('seller_profiles', 'seller_profiles.user_id', '=', 'users.id')
            ->whereNull('listings.deleted_at')
            ->whereIn('listings.status', $publiclyVisible)
            ->groupBy('users.id', 'users.uuid', 'users.first_name', 'users.last_name', 'seller_profiles.display_name', 'seller_profiles.is_verified')
            ->orderByDesc(DB::raw('COUNT(listings.id)'))
            ->limit($limit)
            ->get([
                'users.uuid',
                'users.first_name',
                'users.last_name',
                'seller_profiles.display_name',
                'seller_profiles.is_verified',
                DB::raw('COUNT(listings.id) as listings_count'),
                DB::raw('SUM(listings.view_count) as views'),
                DB::raw('SUM(listings.inquiry_count) as inquiries'),
                DB::raw('SUM(listings.favorite_count) as favorites'),
            ])
            ->map(fn (object $row): array => [
                'uuid' => $row->uuid,
                'name' => $row->display_name ?: trim("{$row->first_name} {$row->last_name}"),
                'is_verified' => (bool) $row->is_verified,
                'listings' => (int) $row->listings_count,
                'views' => (int) $row->views,
                'inquiries' => (int) $row->inquiries,
                'favorites' => (int) $row->favorites,
            ])
            ->all();
    }

    // ------------------------------------------------------------- internals

    /**
     * Every listing tile in one pass.
     *
     * Eight `SELECT COUNT(*) WHERE status = ...` queries scan the same table
     * eight times; conditional aggregates scan it once.
     */
    private function listingCounters(): object
    {
        return DB::table('listings')
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(status = ?) as published', [ListingStatus::Published->value])
            ->selectRaw('SUM(status = ?) as pending', [ListingStatus::PendingReview->value])
            ->selectRaw('SUM(status = ?) as rejected', [ListingStatus::Rejected->value])
            ->selectRaw('SUM(status = ?) as draft', [ListingStatus::Draft->value])
            ->selectRaw('SUM(status = ?) as archived', [ListingStatus::Archived->value])
            ->selectRaw('SUM(status = ?) as expired', [ListingStatus::Expired->value])
            ->selectRaw('SUM(status = ?) as sold', [ListingStatus::Sold->value])
            ->selectRaw('SUM(is_featured = 1) as featured')
            ->selectRaw('SUM(is_verified = 1) as verified')
            ->selectRaw('SUM(created_at >= ?) as new_this_week', [now()->subWeek()])
            ->first() ?? (object) [];
    }

    private function userCounters(): object
    {
        return DB::table('users')
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(status = ?) as active', [UserStatus::Active->value])
            ->selectRaw('SUM(status = ?) as suspended', [UserStatus::Suspended->value])
            ->selectRaw('SUM(status = ?) as pending', [UserStatus::Pending->value])
            ->first() ?? (object) [];
    }

    /** @return array<string, mixed> */
    private function engagementCounters(): array
    {
        $inquiries = DB::table('inquiries')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(status = ?) as unread', ['new'])
            ->first();

        $reviews = DB::table('reviews')
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(status = ?) as pending', ['pending'])
            ->selectRaw('AVG(rating) as average')
            ->first();

        return [
            'inquiries' => (int) ($inquiries->total ?? 0),
            'unread_inquiries' => (int) ($inquiries->unread ?? 0),
            'reviews' => (int) ($reviews->total ?? 0),
            'pending_reviews' => (int) ($reviews->pending ?? 0),
            'average_rating' => $reviews?->average !== null ? round((float) $reviews->average, 2) : null,
            'favorites' => (int) DB::table('favorites')->count(),
            // Lifetime, from the denormalised counters on `listings`, which the
            // counter flush keeps current. Summing `listing_views` instead
            // would scan the largest table on the platform for one tile.
            'views' => (int) DB::table('listings')->whereNull('deleted_at')->sum('view_count'),
        ];
    }

    private function roleCount(RoleSlug $role): int
    {
        return (int) DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('users', function ($join): void {
                $join->on('users.id', '=', 'model_has_roles.model_id')
                    ->whereNull('users.deleted_at');
            })
            ->where('roles.name', $role->value)
            ->where('model_has_roles.model_type', User::class)
            ->count();
    }

    /**
     * @return array<int, array{date: string, value: int}>
     */
    private function dailySeries(string $table, string $column, Carbon $since, int $days): array
    {
        $query = DB::table($table)
            ->where($column, '>=', $since)
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->selectRaw("DATE({$column}) as bucket, COUNT(*) as value");

        // Soft-deleted rows are not activity.
        if (in_array($table, ['listings', 'users', 'reviews'], true)) {
            $query->whereNull('deleted_at');
        }

        return $this->fill($query->pluck('value', 'bucket'), $since, $days);
    }

    /**
     * New sellers per day, by when the role was granted.
     *
     * `model_has_roles` carries no timestamp, so this uses the seller PROFILE's
     * creation date — which is written at the same moment the role is, and is
     * the closest honest answer available.
     *
     * @return array<int, array{date: string, value: int}>
     */
    private function vendorSeries(Carbon $since, int $days): array
    {
        $rows = DB::table('seller_profiles')
            ->where('created_at', '>=', $since)
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->selectRaw('DATE(created_at) as bucket, COUNT(*) as value')
            ->pluck('value', 'bucket');

        return $this->fill($rows, $since, $days);
    }

    /**
     * @return array<int, array{date: string, value: int}>
     */
    private function viewSeries(Carbon $since, int $days): array
    {
        // The rollup table, not `listing_views`: it is pre-aggregated per day,
        // so this reads hundreds of rows instead of millions.
        $rows = DB::table('listing_view_daily')
            ->where('date', '>=', $since->toDateString())
            ->groupBy('date')
            ->orderBy('date')
            ->selectRaw('date, SUM(views) as value')
            ->pluck('value', 'date');

        return $this->fill($rows, $since, $days);
    }

    /**
     * Pads a sparse `date => count` map to one entry per day.
     *
     * Without this a chart draws a straight line across days that had no
     * activity, which reads as steady traffic rather than as none.
     *
     * @param  Collection<array-key, mixed>  $rows
     * @return array<int, array{date: string, value: int}>
     */
    private function fill(Collection $rows, Carbon $since, int $days): array
    {
        $series = [];
        $cursor = $since->copy();

        for ($i = 0; $i < $days; $i++) {
            $date = $cursor->toDateString();
            $series[] = ['date' => $date, 'value' => (int) ($rows[$date] ?? 0)];
            $cursor->addDay();
        }

        return $series;
    }
}
