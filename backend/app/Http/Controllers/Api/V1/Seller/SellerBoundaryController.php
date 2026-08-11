<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Seller;

use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Listing\StoreListingBoundaryRequest;
use App\Http\Resources\V1\ListingBoundaryResource;
use App\Models\Listing;
use App\Services\Listing\LandBoundaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD for a land parcel outline.
 *
 * Authorised through the existing `manageMedia` ability rather than a new one:
 * a boundary is listing content the owner may change under exactly the same
 * conditions as its photos — including the rule that a sold or archived listing
 * is a historical record and can no longer be edited.
 */
class SellerBoundaryController extends Controller
{
    public function __construct(private readonly LandBoundaryService $boundaries) {}

    public function show(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('view', $listing);

        $boundary = $listing->boundary;

        return response()->json([
            'data' => $boundary !== null ? new ListingBoundaryResource($boundary) : null,
            'meta' => ['supported' => $listing->supportsBoundary()],
        ]);
    }

    public function update(StoreListingBoundaryRequest $request, Listing $listing): JsonResponse
    {
        $this->authorize('manageMedia', $listing);
        $this->assertSupported($listing);

        $boundary = $this->boundaries->save(
            $listing,
            $request->array('rings'),
            $request->input('survey_reference'),
            $request->input('notes'),
        );

        return response()->json(['data' => new ListingBoundaryResource($boundary)]);
    }

    public function destroy(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('manageMedia', $listing);

        $this->boundaries->delete($listing);

        return response()->json(['data' => ['message' => 'Boundary removed.']]);
    }

    /**
     * A boundary on a phone listing is not a validation error, it is a category
     * error — so it is a 422 with an explanation rather than a silent no-op.
     */
    private function assertSupported(Listing $listing): void
    {
        if ($listing->supportsBoundary()) {
            return;
        }

        throw ApiException::make(
            ErrorCode::ValidationFailed,
            'This category does not support a land boundary.',
            ['category' => ['Land boundaries apply to plots and agricultural land.']],
        );
    }
}
