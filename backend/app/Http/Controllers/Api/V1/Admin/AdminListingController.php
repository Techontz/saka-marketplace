<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\RoleSlug;
use App\Domain\Listing\Enums\ListingStatus;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ListingResource;
use App\Models\Listing;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Listing\ListingIndexer;
use App\Services\Listing\ListingStatusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Listing administration across EVERY status.
 *
 * The public `/listings` endpoint is scoped to publicly-visible listings, and
 * `/admin/listings/pending` sees only the moderation queue. Neither can answer
 * "show me this seller's rejected listings" or "find the archived one with this
 * title", which is most of what moderation actually involves. This is the
 * unscoped view, and it is deliberately a separate controller so that scope
 * cannot leak into the public one by accident.
 *
 * DESTRUCTIVE ACTIONS ARE GRADED, and the grading is the design:
 *
 *   - archive   — a status transition. Reversible, keeps history, and is what
 *                 "remove this from the site" almost always means;
 *   - delete    — SOFT delete. The row stays, `deleted_at` is set, the listing
 *                 leaves every index. Restorable;
 *   - force     — the row is gone. Separate permission, separate endpoint,
 *                 never part of a bulk action.
 *
 * A single "Delete" button that destroys rows is how a moderator's misclick
 * becomes an unrecoverable incident.
 */
class AdminListingController extends Controller
{
    public function __construct(
        private readonly ListingStatusService $status,
        private readonly ListingIndexer $indexer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Search and filter every listing, whatever its status.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeModerate($request);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'string', Rule::in(ListingStatus::values())],
            'category' => ['nullable', 'string', 'max:120'],
            'seller' => ['nullable', 'string', 'max:64'],
            'featured' => ['nullable', 'boolean'],
            'verified' => ['nullable', 'boolean'],
            'trashed' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'updated', 'price_asc', 'price_desc', 'views'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $listings = Listing::query()
            ->with([
                'category:id,name,slug,icon,parent_id',
                'category.parent:id,name,slug,icon',
                'region:id,name,slug',
                'district:id,name,slug',
                'ward:id,name,slug',
                'primaryMedia',
                'user:id,uuid,first_name,last_name,email',
            ])
            /*
             * A plain LIKE, not the FULLTEXT index the public search uses.
             *
             * Moderators search for a fragment of a title they were sent, or a
             * slug from a URL — `%masaki%` has to match, and FULLTEXT will not
             * do infix matching. This is an admin surface with a small
             * concurrent audience, so the table scan is an acceptable trade for
             * a search that behaves the way its users expect.
             */
            ->when($validated['q'] ?? null, function (Builder $query, string $term): void {
                $escaped = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

                $query->where(function (Builder $inner) use ($escaped): void {
                    $inner->where('listings.title', 'like', $escaped)
                        ->orWhere('listings.slug', 'like', $escaped)
                        ->orWhere('listings.uuid', 'like', $escaped);
                });
            })
            ->when($validated['status'] ?? null, fn (Builder $q, string $s) => $q->where('listings.status', $s))
            ->when(
                $validated['category'] ?? null,
                fn (Builder $q, string $slug) => $q->whereHas('category', fn (Builder $c) => $c->where('slug', $slug)),
            )
            ->when(
                $validated['seller'] ?? null,
                fn (Builder $q, string $uuid) => $q->whereHas('user', fn (Builder $u) => $u->where('uuid', $uuid)),
            )
            ->when(isset($validated['featured']), fn (Builder $q) => $q->where('listings.is_featured', $request->boolean('featured')))
            ->when(isset($validated['verified']), fn (Builder $q) => $q->where('listings.is_verified', $request->boolean('verified')))
            // Soft-deleted listings are hidden unless explicitly asked for, so
            // the default view is "what exists" rather than "what ever existed".
            ->when($request->boolean('trashed'), fn (Builder $q) => $q->onlyTrashed())
            ->tap(fn (Builder $q) => $this->applySort($q, $validated['sort'] ?? 'updated'))
            ->paginate($validated['per_page'] ?? 25)
            ->withQueryString();

        return ListingResource::collection($listings);
    }

    /**
     * One listing, in full, whatever its status.
     *
     * Route-model binding is not used here: it applies the default global scope
     * and would 404 on a soft-deleted listing, which is precisely the one a
     * moderator is trying to look at.
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $this->authorizeModerate($request);

        $listing = Listing::withTrashed()
            ->with([
                'category', 'region', 'district', 'ward', 'media',
                'amenities:id,name,slug,icon', 'facilities:id,name,slug,icon',
                'attributeValues.attribute', 'attributeValues.option',
                'user:id,uuid,first_name,last_name,email,phone,created_at',
                'user.sellerProfile',
                'statusHistories' => fn ($q) => $q->latest()->limit(20),
            ])
            ->where('uuid', $uuid)
            ->first();

        if ($listing === null) {
            throw ApiException::notFound();
        }

        return response()->json([
            'data' => array_merge(
                (new ListingResource($listing))->detailed()->resolve($request),
                [
                    'deleted_at' => $listing->deleted_at?->toAtomString(),
                    // The moderation paper trail: who changed what, and why.
                    'status_history' => $listing->statusHistories
                        ->map(fn ($history): array => [
                            'from' => $history->from_status,
                            'to' => $history->to_status,
                            'reason' => $history->reason,
                            'changed_by' => $history->changed_by,
                            'at' => $history->created_at?->toAtomString(),
                        ])->values()->all(),
                ],
            ),
        ]);
    }

    /**
     * Move a listing to any status its current state allows.
     *
     * Goes through ListingStatusService rather than writing the column, so the
     * transition is validated, recorded in `listing_status_histories` and the
     * search index is updated. Setting `status` directly would skip all three.
     */
    public function transition(Request $request, string $uuid): JsonResponse
    {
        $this->authorizeModerate($request);

        $validated = $request->validate([
            'status' => ['required', Rule::in(ListingStatus::values())],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $listing = $this->findOrFail($uuid);
        $target = ListingStatus::from($validated['status']);

        if (! $listing->status->canTransitionTo($target)) {
            throw ApiException::make(
                ErrorCode::InvalidStateTransition,
                "A {$listing->status->value} listing cannot become {$target->value}.",
                [
                    'from' => $listing->status->value,
                    'to' => $target->value,
                    'allowed' => array_map(
                        fn (ListingStatus $s): string => $s->value,
                        $listing->status->allowedTransitions(),
                    ),
                ],
            );
        }

        $before = $listing->status->value;
        $updated = $this->status->transition($listing, $target, $request->user(), $validated['reason'] ?? null);

        $this->audit->record(
            'listing.status_changed',
            $request->user(),
            $updated,
            ['status' => $before],
            ['status' => $updated->status->value, 'reason' => $validated['reason'] ?? null],
        );

        return response()->json([
            'data' => ['uuid' => $updated->uuid, 'status' => $updated->status->value],
        ]);
    }

    /** Soft delete. The row survives; `restore` brings it back. */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->authorizePermission($request, Permission::ListingDeleteAny);

        $listing = $this->findOrFail($uuid);

        $listing->delete();
        // Leaves search and every discovery surface immediately, rather than
        // when the cache happens to expire.
        $this->indexer->remove($listing);

        $this->audit->record('listing.deleted', $request->user(), $listing, [
            'title' => $listing->title,
            'status' => $listing->status->value,
        ]);

        return response()->json(['data' => ['uuid' => $listing->uuid, 'deleted' => true]]);
    }

    public function restore(Request $request, string $uuid): JsonResponse
    {
        $this->authorizePermission($request, Permission::ListingDeleteAny);

        $listing = Listing::withTrashed()->where('uuid', $uuid)->first();

        if ($listing === null) {
            throw ApiException::notFound();
        }

        if (! $listing->trashed()) {
            throw ApiException::make(ErrorCode::Conflict, 'That listing is not deleted.');
        }

        $listing->restore();
        $this->indexer->index($listing);

        $this->audit->record('listing.restored', $request->user(), $listing);

        return response()->json([
            'data' => ['uuid' => $listing->uuid, 'status' => $listing->status->value],
        ]);
    }

    /**
     * Permanent deletion. Separate permission, and never reachable from bulk.
     */
    public function forceDestroy(Request $request, string $uuid): JsonResponse
    {
        $this->authorizePermission($request, Permission::ListingDeleteAny);

        // Super-admin only, on top of the permission. Everything else on this
        // controller is reversible; this is the one action that is not, so it
        // is gated on the role that cannot be granted through the API.
        if (! $request->user()?->hasRole(RoleSlug::SuperAdmin->value)) {
            throw ApiException::make(
                ErrorCode::Forbidden,
                'Permanent deletion is restricted to super administrators. Use delete instead — it is reversible.',
            );
        }

        $listing = Listing::withTrashed()->where('uuid', $uuid)->first();

        if ($listing === null) {
            throw ApiException::notFound();
        }

        // Recorded BEFORE the row disappears — afterwards there is nothing left
        // to describe what was destroyed.
        $this->audit->record('listing.force_deleted', $request->user(), $listing, [
            'uuid' => $listing->uuid,
            'title' => $listing->title,
            'slug' => $listing->slug,
            'user_id' => $listing->user_id,
            'status' => $listing->status->value,
        ]);

        $this->indexer->remove($listing);
        $listing->forceDelete();

        return response()->json(['data' => ['uuid' => $uuid, 'purged' => true]]);
    }

    /**
     * Apply one action to many listings.
     *
     * Capped at 100 per call, and each listing is processed independently: one
     * that cannot make the transition is reported in `failed` rather than
     * aborting the batch. A moderator clearing a queue of 50 wants the 49 that
     * worked, plus a list of what did not — not a rollback.
     *
     * `force_delete` is deliberately absent from the allowed actions.
     */
    public function bulk(Request $request): JsonResponse
    {
        $this->authorizeModerate($request);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject', 'archive', 'feature', 'unfeature', 'verify', 'delete'])],
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validated['action'] === 'delete') {
            $this->authorizePermission($request, Permission::ListingDeleteAny);
        }

        if (in_array($validated['action'], ['feature', 'unfeature'], true)) {
            $this->authorizePermission($request, Permission::ListingFeature);
        }

        $succeeded = [];
        $failed = [];

        foreach (array_unique($validated['uuids']) as $uuid) {
            try {
                $this->applyBulkAction(
                    $validated['action'],
                    (string) $uuid,
                    $request,
                    $validated['reason'] ?? null,
                );
                $succeeded[] = $uuid;
            } catch (ApiException $e) {
                $failed[] = ['uuid' => $uuid, 'reason' => $e->getMessage()];
            }
        }

        $this->audit->record(
            'listing.bulk_'.$validated['action'],
            $request->user(),
            null,
            [],
            ['succeeded' => count($succeeded), 'failed' => count($failed), 'uuids' => $succeeded],
        );

        return response()->json([
            'data' => [
                'action' => $validated['action'],
                'succeeded' => $succeeded,
                'failed' => $failed,
                'summary' => [
                    'requested' => count($validated['uuids']),
                    'succeeded' => count($succeeded),
                    'failed' => count($failed),
                ],
            ],
        ]);
    }

    // ------------------------------------------------------------- internals

    private function applyBulkAction(string $action, string $uuid, Request $request, ?string $reason): void
    {
        $listing = $this->findOrFail($uuid);
        $actor = $request->user();

        match ($action) {
            'approve' => $this->transitionOrFail($listing, ListingStatus::Published, $actor, $reason),
            'reject' => $this->transitionOrFail($listing, ListingStatus::Rejected, $actor, $reason),
            'archive' => $this->transitionOrFail($listing, ListingStatus::Archived, $actor, $reason),
            'feature' => $this->setFeatured($listing, true),
            'unfeature' => $this->setFeatured($listing, false),
            'verify' => $listing->forceFill(['is_verified' => true])->save(),
            'delete' => DB::transaction(function () use ($listing): void {
                $listing->delete();
                $this->indexer->remove($listing);
            }),
            // Unreachable: `action` is validated against this exact set. Kept
            // so that adding an action to the Rule::in without adding it here
            // fails loudly instead of silently doing nothing.
            default => throw ApiException::make(
                ErrorCode::ValidationFailed,
                "Unsupported bulk action [{$action}].",
            ),
        };
    }

    private function transitionOrFail(Listing $listing, ListingStatus $target, ?User $actor, ?string $reason): void
    {
        if (! $listing->status->canTransitionTo($target)) {
            throw ApiException::make(
                ErrorCode::InvalidStateTransition,
                "Cannot move from {$listing->status->value} to {$target->value}.",
            );
        }

        $this->status->transition($listing, $target, $actor, $reason);
    }

    private function setFeatured(Listing $listing, bool $featured): void
    {
        $listing->forceFill([
            'is_featured' => $featured,
            'featured_until' => $featured ? $listing->featured_until : null,
        ])->save();
    }

    private function findOrFail(string $uuid): Listing
    {
        $listing = Listing::query()->where('uuid', $uuid)->first();

        if ($listing === null) {
            throw ApiException::notFound();
        }

        return $listing;
    }

    private function authorizeModerate(Request $request): void
    {
        $this->authorizePermission($request, Permission::ListingModerate);
    }

    private function authorizePermission(Request $request, Permission $permission): void
    {
        if (! $request->user()?->hasPermission($permission)) {
            throw ApiException::forbidden();
        }
    }

    /**
     * @param  Builder<Listing>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'newest' => $query->orderByDesc('listings.created_at'),
            'oldest' => $query->orderBy('listings.created_at'),
            'price_asc' => $query->orderBy('listings.price'),
            'price_desc' => $query->orderByDesc('listings.price'),
            'views' => $query->orderByDesc('listings.view_count'),
            default => $query->orderByDesc('listings.updated_at'),
        };
    }
}
