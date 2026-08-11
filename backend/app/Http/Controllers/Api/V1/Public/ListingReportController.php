<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\ListingReport;
use App\Repositories\Contracts\ListingRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Report this listing".
 *
 * Open to guests. The people best placed to report a fraudulent listing are
 * the ones who just got burned by it, and requiring them to register first is
 * how a marketplace ends up not hearing about its worst listings. Rate limits
 * and a de-duplicating unique index carry the abuse load instead.
 */
class ListingReportController extends Controller
{
    public function __construct(private readonly ListingRepositoryInterface $listings) {}

    /** The reason vocabulary, so a client never hardcodes it. */
    public function reasons(): JsonResponse
    {
        return response()->json([
            'data' => collect(ListingReport::REASONS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
        ]);
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        $listing = $this->listings->findBySlug($slug, $request->user());

        if ($listing === null) {
            throw ApiException::notFound('Listing not found.');
        }

        $validated = $request->validate([
            'reason' => ['required', Rule::in(array_keys(ListingReport::REASONS))],
            'details' => ['nullable', 'string', 'max:1000'],
            // Only asked of guests, and only so a moderator can come back to
            // them. Never shown to the seller.
            'contact_email' => ['nullable', 'email', 'max:255'],
        ]);

        $user = $request->user();

        if ($user !== null && $user->getKey() === $listing->user_id) {
            throw ApiException::forbidden('This is your own listing.');
        }

        /*
         * The IP is hashed with the app key, never stored raw. It exists to
         * spot one source filing fifty reports; keeping the address itself
         * would make this table a log of who looked at which listing, which is
         * not what anyone consented to when they pressed Report.
         */
        $ipHash = hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));

        $report = ListingReport::firstOrCreate(
            [
                'listing_id' => $listing->getKey(),
                'reporter_id' => $user?->getKey(),
                'reporter_ip_hash' => $ipHash,
            ],
            [
                'reason' => $validated['reason'],
                'details' => $validated['details'] ?? null,
                'contact_email' => $validated['contact_email'] ?? $user?->email,
            ],
        );

        // Mirrored to the log so it shows up in the same alerting pipeline as
        // the review reports already do.
        Log::warning('listing.reported', [
            'listing_id' => $listing->getKey(),
            'listing_slug' => $listing->slug,
            'report_uuid' => $report->uuid,
            'reason' => $validated['reason'],
            'reported_by' => $user?->getKey(),
            'duplicate' => ! $report->wasRecentlyCreated,
        ]);

        return response()->json([
            'data' => [
                'uuid' => $report->uuid,
                // The same answer either way: telling someone their report was
                // a duplicate invites them to file it again from another
                // address, and it does not help them.
                'message' => 'Thank you. Our moderation team will review this listing.',
            ],
        ], Response::HTTP_ACCEPTED);
    }
}
