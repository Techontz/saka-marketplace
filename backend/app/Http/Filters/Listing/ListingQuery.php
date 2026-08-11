<?php

declare(strict_types=1);

namespace App\Http\Filters\Listing;

use App\Domain\Listing\DataTransferObjects\ListingFilterData;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payload carried through the filter pipeline.
 *
 * Mutable by design — each stage narrows the same builder rather than cloning
 * it, which keeps the whole chain to one query.
 */
class ListingQuery
{
    public function __construct(
        public Builder $builder,
        public readonly ListingFilterData $filters,
    ) {}
}
