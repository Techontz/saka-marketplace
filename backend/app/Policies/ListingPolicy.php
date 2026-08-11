<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Listing\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;

/**
 * Ownership OR an explicit override permission — never a role name.
 *
 * These run in addition to the VisibilityScope applied to every query; the
 * scope stops rows being read in bulk, the policy stops a single record being
 * mutated.
 */
class ListingPolicy
{
    public function view(?User $user, Listing $listing): bool
    {
        if ($listing->status === ListingStatus::Published && $listing->published_at !== null) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $this->owns($user, $listing) || $user->hasPermission(Permission::ListingModerate);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ListingCreate);
    }

    public function update(User $user, Listing $listing): bool
    {
        if ($user->hasPermission(Permission::ListingUpdateAny)) {
            return true;
        }

        // A sold or archived listing is a historical record, not a draft.
        if (in_array($listing->status, [ListingStatus::Sold, ListingStatus::Archived], true)) {
            return false;
        }

        return $this->owns($user, $listing) && $user->hasPermission(Permission::ListingUpdate);
    }

    public function delete(User $user, Listing $listing): bool
    {
        if ($user->hasPermission(Permission::ListingDeleteAny)) {
            return true;
        }

        return $this->owns($user, $listing) && $user->hasPermission(Permission::ListingDelete);
    }

    /** Submitting for review / publishing / pausing — owner-only. */
    public function publish(User $user, Listing $listing): bool
    {
        return $this->owns($user, $listing) && $user->hasPermission(Permission::ListingPublish);
    }

    public function moderate(User $user): bool
    {
        return $user->hasPermission(Permission::ListingModerate);
    }

    public function feature(User $user): bool
    {
        return $user->hasPermission(Permission::ListingFeature);
    }

    /** Managing the listing's images follows the listing itself. */
    public function manageMedia(User $user, Listing $listing): bool
    {
        return $this->update($user, $listing);
    }

    private function owns(User $user, Listing $listing): bool
    {
        return $user->getKey() === $listing->user_id;
    }
}
