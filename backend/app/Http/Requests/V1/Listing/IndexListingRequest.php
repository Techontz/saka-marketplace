<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Listing;

use App\Domain\Listing\DataTransferObjects\ListingFilterData;
use App\Domain\Listing\Enums\ListingCondition;
use App\Domain\Listing\Enums\ListingPurpose;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the entire public filter surface.
 *
 * Every filter is validated even though unknown values degrade gracefully —
 * validation is what stops a crafted `sort` or `radius` reaching the query
 * builder at all.
 */
class IndexListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:120'],
            'subcategory' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'ward' => ['nullable', 'string', 'max:120'],
            // Free-text place search, for a single "where?" input that does not
            // ask the user to know whether they typed a ward or a district.
            'place' => ['nullable', 'string', 'max:120'],

            'min_price' => ['nullable', 'integer', 'min:0'],
            // `gte:min_price` cannot be used unconditionally: with min_price absent
            // it compares against null and rejects every request. Checked in
            // withValidator() only when BOTH bounds are present.
            'max_price' => ['nullable', 'integer', 'min:0'],

            'purpose' => ['nullable', Rule::in(ListingPurpose::values())],
            'condition' => ['nullable', Rule::in(ListingCondition::values())],
            'verified' => ['nullable', 'boolean'],
            'featured' => ['nullable', 'boolean'],

            'amenities' => ['nullable', 'array', 'max:40'],
            'amenities.*' => ['string', 'max:120'],
            'facilities' => ['nullable', 'array', 'max:40'],
            'facilities.*' => ['string', 'max:120'],

            'attributes' => ['nullable', 'array', 'max:40'],

            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:radius'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:radius'],
            // Capped: an unbounded radius defeats the bounding-box prefilter and
            // turns every geo search into a full scan.
            'radius' => ['nullable', 'numeric', 'min:0.1', 'max:500', 'required_with:lat'],

            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'price_asc', 'price_desc', 'popularity', 'distance', 'relevance'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('saka.pagination.max_per_page')],
            'page' => ['nullable', 'integer', 'min:1'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->filled('min_price') && $this->filled('max_price')
                && $this->integer('max_price') < $this->integer('min_price')) {
                $validator->errors()->add('max_price', 'The maximum price must be at least the minimum price.');
            }
        });
    }

    public function toFilterData(): ListingFilterData
    {
        return ListingFilterData::fromArray($this->validated());
    }

    public function wantsCursorPagination(): bool
    {
        return $this->has('cursor') || $this->boolean('use_cursor');
    }
}
