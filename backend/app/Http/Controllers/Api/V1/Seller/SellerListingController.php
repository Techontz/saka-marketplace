<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Seller;

use App\Domain\Listing\Enums\ListingStatus;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Listing\IndexListingRequest;
use App\Http\Requests\V1\Listing\StoreListingRequest;
use App\Http\Requests\V1\Listing\UpdateListingRequest;
use App\Http\Resources\V1\ListingResource;
use App\Models\Listing;
use App\Repositories\Contracts\ListingRepositoryInterface;
use App\Services\Listing\ListingService;
use App\Services\Listing\ListingStatusService;
use App\Services\Seller\SellerDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seller-owned listing management.
 *
 * Anything the caller does not own returns 404, never 403. A 403 confirms the
 * uuid names a real listing, which is a (small) enumeration oracle and an
 * inconsistency with the public surface, where the same listing is already 404.
 * Moderators act through the admin surface, not this one.
 *
 * Route-model binding resolves {listing} by uuid; the policy then confirms
 * ownership. Controllers hold no business rules — every mutation goes through a
 * service, and every status change through ListingStatusService so the
 * transition table and history are never bypassed.
 */
class SellerListingController extends Controller
{
    public function __construct(
        private readonly ListingRepositoryInterface $listings,
        private readonly ListingService $service,
        private readonly ListingStatusService $status,
        private readonly SellerDashboardService $dashboard,
    ) {}

    public function index(IndexListingRequest $request): AnonymousResourceCollection
    {
        $filters = $request->toFilterData()->forSeller($request->user()->getKey());

        return ListingResource::collection(
            $this->listings->paginate($filters, $request->user()),
        );
    }

    public function store(StoreListingRequest $request): JsonResponse
    {
        $listing = $this->service->create($request->user(), $request->validated());
        $this->dashboard->forget($request->user());

        return response()->json([
            'data' => (new ListingResource($listing->load([
                'category', 'region', 'district', 'ward', 'media',
                'attributeValues.attribute', 'attributeValues.option',
            ])))->detailed(),
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, Listing $listing): JsonResponse
    {
        $this->assertOwned($request, $listing);

        return response()->json([
            'data' => (new ListingResource($listing->load([
                'category', 'region', 'district', 'ward', 'media',
                'amenities', 'facilities', 'attributeValues.attribute', 'attributeValues.option',
            ])))->detailed(),
        ]);
    }

    public function update(UpdateListingRequest $request, Listing $listing): JsonResponse
    {
        $this->authorize('update', $listing);

        $updated = $this->service->update($listing, $request->validated());
        $this->dashboard->forget($request->user());

        return response()->json([
            'data' => (new ListingResource($updated->load([
                'category', 'region', 'district', 'ward', 'media',
                'attributeValues.attribute', 'attributeValues.option',
            ])))->detailed(),
        ]);
    }

    public function destroy(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('delete', $listing);

        $this->service->delete($listing);
        $this->dashboard->forget($request->user());

        return response()->json(['data' => ['message' => 'Listing deleted.']]);
    }

    /** Draft -> Pending review (or straight to Published when moderation is off). */
    /**
     * Copy a listing into a new draft.
     *
     * The single most-asked-for thing in any vendor tool: a landlord with
     * fourteen near-identical flats, a dealer with the same model in six
     * colours. Without it every one is retyped from scratch.
     *
     * WHAT IS AND IS NOT COPIED is the whole design:
     *
     *   - copied: the descriptive content — title, description, price,
     *     category, location, attributes, amenities, facilities;
     *   - NOT copied: identity (uuid, slug), lifecycle (status, published_at,
     *     expires_at), engagement (views, favourites, inquiries), moderation
     *     (is_verified, is_featured, rejection_reason) and media.
     *
     * A duplicate that inherited 4,000 views and a verified badge would be a
     * fabricated reputation. It starts as a draft with nothing earned.
     *
     * Media is excluded because the photos are of a DIFFERENT flat. Copying
     * them produces listings that all show the same room, which is the exact
     * complaint buyers have about duplicated inventory.
     */
    public function duplicate(Request $request, Listing $listing): JsonResponse
    {
        /*
         * Ownership is checked EXPLICITLY, not via the `view` policy.
         *
         * `view` returns true for any PUBLISHED listing — that is its job, it
         * gates public reads. Authorizing a duplicate against it would let any
         * seller clone a competitor's live listing, wording and all.
         *
         * `update` is not right either: it refuses on a sold or archived
         * listing, which is precisely when a vendor most wants to copy one, to
         * relist last season's stock. So: own it, and be allowed to create.
         *
         * 404 rather than 403 on someone else's listing, matching the rest of
         * the API — a 403 would confirm the listing exists.
         */
        if ($listing->user_id !== $request->user()?->getKey()) {
            throw ApiException::notFound();
        }

        $this->authorize('create', Listing::class);

        $copy = DB::transaction(function () use ($listing): Listing {
            $attributes = $listing->only([
                'category_id', 'description', 'purpose', 'price', 'currency',
                'price_unit', 'is_negotiable', 'condition', 'region_id',
                'district_id', 'ward_id', 'address_line', 'postal_code',
                'latitude', 'longitude', 'available_from',
            ]);

            $copy = new Listing;
            $copy->forceFill([
                ...$attributes,
                'uuid' => (string) Str::uuid7(),
                'user_id' => $listing->user_id,
                // Signposted in the title so a vendor can tell two drafts apart
                // in a list before they have edited either.
                'title' => Str::limit($listing->title.' (copy)', 191, ''),
                'slug' => $this->uniqueSlug($listing->title.' copy'),
                'status' => ListingStatus::Draft,
            ])->save();

            // EAV values are content, so they come across.
            foreach ($listing->attributeValues as $value) {
                $copy->attributeValues()->create($value->only([
                    'attribute_id', 'attribute_option_id', 'value_string',
                    'value_integer', 'value_decimal', 'value_boolean', 'value_date',
                ]));
            }

            $copy->amenities()->sync($listing->amenities->pluck('id'));
            $copy->facilities()->sync($listing->facilities->pluck('id'));

            return $copy;
        });

        return response()->json([
            'data' => (new ListingResource(
                $copy->fresh(['category', 'region', 'district', 'media', 'amenities', 'facilities', 'attributeValues.attribute', 'attributeValues.option']),
            ))->detailed(),
            'meta' => [
                'message' => 'Copied to a new draft. Photos were not copied — add the ones for this listing.',
            ],
        ], Response::HTTP_CREATED);
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'listing';
        $slug = $base.'-'.Str::lower(Str::random(6));

        while (Listing::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }

    public function submit(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('publish', $listing);

        $updated = $this->status->submitForReview($listing, $request->user());
        $this->dashboard->forget($request->user());

        return $this->statusResponse($updated);
    }

    public function pause(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('publish', $listing);

        $updated = $this->status->transition($listing, ListingStatus::Paused, $request->user());
        $this->dashboard->forget($request->user());

        return $this->statusResponse($updated);
    }

    /**
     * Un-pause a listing the seller paused.
     *
     * Restricted to a PAUSED listing on purpose. The status machine also allows
     * Pending review → Published, because that is how a moderator approves —
     * and this endpoint transitions to exactly that status, so without this
     * guard a seller could call `resume` on their own listing while it awaited
     * review and publish it themselves, skipping moderation entirely.
     */
    public function resume(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('publish', $listing);

        if ($listing->status !== ListingStatus::Paused) {
            throw ApiException::make(
                ErrorCode::InvalidStateTransition,
                'Only a paused listing can be resumed.',
                ['from' => $listing->status->value, 'to' => ListingStatus::Published->value],
            );
        }

        $updated = $this->status->transition($listing, ListingStatus::Published, $request->user());
        $this->dashboard->forget($request->user());

        return $this->statusResponse($updated);
    }

    public function markSold(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('publish', $listing);

        $updated = $this->status->transition($listing, ListingStatus::Sold, $request->user());
        $this->dashboard->forget($request->user());

        return $this->statusResponse($updated);
    }

    public function archive(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('publish', $listing);

        $updated = $this->status->transition($listing, ListingStatus::Archived, $request->user());
        $this->dashboard->forget($request->user());

        return $this->statusResponse($updated);
    }

    /**
     * Ownership check that fails as "not found" rather than "forbidden".
     */
    private function assertOwned(Request $request, Listing $listing): void
    {
        if ($request->user()?->getKey() !== $listing->user_id) {
            throw ApiException::notFound('Listing not found.');
        }
    }

    private function statusResponse(Listing $listing): JsonResponse
    {
        return response()->json([
            'data' => [
                'uuid' => $listing->uuid,
                'status' => $listing->status->value,
                'published_at' => $listing->published_at?->toAtomString(),
                'expires_at' => $listing->expires_at?->toAtomString(),
                'allowed_transitions' => array_map(
                    fn (ListingStatus $s) => $s->value,
                    $listing->status->allowedTransitions(),
                ),
            ],
        ]);
    }
}
