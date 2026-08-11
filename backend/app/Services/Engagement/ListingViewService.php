<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Jobs\RecordListingView;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Http\Request;

class ListingViewService
{
    /**
     * A seller viewing their own listing is not a view — otherwise every
     * dashboard visit would inflate the number the seller is judging.
     */
    public function record(Listing $listing, Request $request, ?User $viewer): void
    {
        if ($viewer !== null && $viewer->getKey() === $listing->user_id) {
            return;
        }

        RecordListingView::dispatch(
            listingId: $listing->id,
            // Hashed with the app key: raw IPs are personal data and we only
            // ever need equality, never the address itself.
            ipHash: hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
            userId: $viewer?->getKey(),
            sessionId: substr((string) $request->header('X-Session-Id', ''), 0, 40) ?: null,
            referrer: substr((string) $request->header('referer', ''), 0, 255) ?: null,
        );
    }
}
