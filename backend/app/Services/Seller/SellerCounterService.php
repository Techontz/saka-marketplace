<?php

declare(strict_types=1);

namespace App\Services\Seller;

use App\Domain\Listing\Enums\ListingStatus;
use Illuminate\Support\Facades\DB;

/**
 * Keeps `seller_profiles.active_listings` and `total_listings` true.
 *
 * These columns were read in four places — the featured-business filter, the
 * "N listings" on every business card, the directory's sort-by-listings, and
 * the stats on a business page — and written in none. They sat at zero
 * forever, so featured businesses was permanently empty and every business
 * advertised that it had nothing for sale.
 *
 * Recomputed from source rather than incremented: an increment cannot be
 * corrected once it drifts, and a listing can change status through moderation,
 * expiry, a seller action or an admin action. Counting is cheap — it is one
 * indexed aggregate over `listings_seller_idx (user_id, status)`.
 */
class SellerCounterService
{
    /** Statuses that count as live stock a customer could actually reach. */
    private const ACTIVE = [ListingStatus::Published];

    /**
     * Recompute one seller's counters.
     *
     * Takes the OWNING USER's id, not the profile id: listings belong to a
     * user, and a seller who has not opened the vendor portal has no profile
     * row to update yet.
     */
    public function recount(int $userId): void
    {
        $counts = DB::table('listings')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as active',
                [ListingStatus::Published->value],
            )
            ->first();

        DB::table('seller_profiles')
            ->where('user_id', $userId)
            ->update([
                'total_listings' => (int) ($counts->total ?? 0),
                'active_listings' => (int) ($counts->active ?? 0),
                'updated_at' => now(),
            ]);
    }

    /**
     * Recompute every seller in one pass.
     *
     * Used by the scheduled reconciliation. A single grouped aggregate joined
     * back to the profiles, rather than one query per seller — which at a few
     * thousand vendors would be a few thousand round trips.
     */
    public function recountAll(): int
    {
        $active = ListingStatus::Published->value;

        return DB::update(<<<'SQL'
            UPDATE seller_profiles p
            LEFT JOIN (
                SELECT user_id,
                       COUNT(*) AS total,
                       SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS active
                FROM listings
                WHERE deleted_at IS NULL
                GROUP BY user_id
            ) c ON c.user_id = p.user_id
            SET p.total_listings = COALESCE(c.total, 0),
                p.active_listings = COALESCE(c.active, 0)
        SQL, [$active]);
    }

    /** @return array<int, ListingStatus> */
    public static function activeStatuses(): array
    {
        return self::ACTIVE;
    }
}
