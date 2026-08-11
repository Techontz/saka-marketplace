<?php

declare(strict_types=1);

namespace App\Http\Filters\Listing;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Listing\Enums\ListingStatus;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * ALWAYS the first stage.
 *
 * Row-level visibility must be part of the query, not a policy check after the
 * fact — a policy that runs once rows are already loaded is too late for a
 * collection response and leaks inventory through counts and pagination totals.
 */
class VisibilityScope
{
    public function __construct(private readonly ?User $viewer) {}

    public function __invoke(ListingQuery $query, Closure $next): ListingQuery
    {
        $viewer = $this->viewer;

        // Staff with moderation rights see everything.
        if ($viewer?->hasPermission(Permission::ListingModerate)) {
            return $next($query);
        }

        $query->builder->where(function (Builder $q) use ($viewer): void {
            $q->where(function (Builder $public): void {
                $public->where('status', ListingStatus::Published->value)
                    ->whereNotNull('published_at');
            });

            // A seller additionally sees their own listings in any status.
            if ($viewer !== null) {
                $q->orWhere('listings.user_id', $viewer->getKey());
            }
        });

        return $next($query);
    }
}
