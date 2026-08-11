<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Domain\Listing\DataTransferObjects\ListingFilterData;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ListingRepositoryInterface
{
    /** Numbered pages — the current frontend renders "1 2 … N". */
    public function paginate(ListingFilterData $filters, ?User $viewer): LengthAwarePaginator;

    /** Cursor pages — stable under insertion, for infinite feeds and mobile. */
    public function cursorPaginate(ListingFilterData $filters, ?User $viewer): CursorPaginator;

    /** Public detail lookup; respects visibility. */
    public function findBySlug(string $slug, ?User $viewer): ?Listing;

    public function findByUuidForOwner(string $uuid, User $owner): ?Listing;

    /** "More Listings" — same category, excluding the current listing. */
    public function similarTo(Listing $listing, int $limit): Collection;

    public function trending(int $limit): Collection;

    public function featured(int $limit): Collection;

    /** Personalised when a viewer is known; falls back to popularity. */
    public function recommendedFor(?User $viewer, int $limit): Collection;

    /** @return array<string, int> status => count */
    public function statusCountsForSeller(User $seller): array;
}
