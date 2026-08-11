<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Engagement\StoreInquiryRequest;
use App\Http\Resources\V1\InquiryResource;
use App\Models\Listing;
use App\Services\Engagement\InquiryService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public, unauthenticated write. Serves both "Contact Seller" and the /contact
 * form. Heavily rate limited and honeypot-guarded — this is the obvious spam
 * target on the whole API.
 */
class InquiryController extends Controller
{
    public function __construct(private readonly InquiryService $inquiries) {}

    public function store(StoreInquiryRequest $request): JsonResponse
    {
        $listing = null;

        if ($request->filled('listing_slug')) {
            $listing = Listing::query()
                ->publiclyVisible()
                ->where('slug', $request->string('listing_slug'))
                ->first();

            if ($listing === null) {
                throw ApiException::notFound('Listing not found.');
            }
        }

        $inquiry = $this->inquiries->create(
            $request->validated(),
            $listing,
            // See StoreInquiryRequest: this route has no auth middleware, so
            // the sanctum guard must be named explicitly or a signed-in sender
            // is recorded as a guest.
            $request->user('sanctum') ?? $request->user(),
            $request,
        );

        return response()->json([
            'data' => new InquiryResource($inquiry),
        ], Response::HTTP_CREATED);
    }
}
