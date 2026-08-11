<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Exceptions\ApiException;
use App\Models\Listing;
use App\Models\User;

/**
 * Guard for endpoints that take a listing by SLUG from an authenticated user.
 *
 * Route-model binding resolves a slug to ANY listing regardless of status. That
 * let a signed-in user favourite or review a draft, rejected or archived
 * listing they could not even read — confirming its existence by slug and, for
 * reviews, polluting a seller's public rating with feedback on unpublished
 * content. Verified by live probe: GET returned 404 while POST returned 201.
 *
 * 404 rather than 403 on purpose: a 403 still confirms the slug exists.
 */
trait ResolvesVisibleListings
{
    protected function assertListingIsActionable(Listing $listing, ?User $viewer): void
    {
        if ($listing->isPublished() && $listing->published_at !== null) {
            return;
        }

        // The owner may act on their own listing in any status.
        if ($viewer !== null && $viewer->getKey() === $listing->user_id) {
            return;
        }

        throw ApiException::notFound('Listing not found.');
    }
}
