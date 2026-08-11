<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Listing;

use App\Domain\Listing\Enums\ListingCondition;
use App\Domain\Listing\Enums\ListingPurpose;
use App\Domain\Listing\Enums\PriceUnit;
use App\Http\Requests\Concerns\ResolvesSlugReferences;
use App\Models\Amenity;
use App\Models\Category;
use App\Models\District;
use App\Models\Facility;
use App\Models\Listing;
use App\Models\Region;
use App\Models\Ward;
use App\Rules\ValidCategoryAttributes;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreListingRequest extends FormRequest
{
    use ResolvesSlugReferences;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Listing::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:10', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            // Either form is accepted. `*_slug` is what the public read
            // endpoints publish; the numeric id is resolved from it before
            // validation runs (see prepareForValidation).
            'category_id' => ['required_without:category_slug', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'category_slug' => ['nullable', 'string', Rule::exists('categories', 'slug')->where('is_active', true)],

            'purpose' => ['nullable', Rule::in(ListingPurpose::values())],
            'price' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'currency' => ['nullable', 'string', 'size:3', Rule::in(array_keys((array) config('saka.currencies')))],
            'price_unit' => ['nullable', Rule::in(PriceUnit::values())],
            'is_negotiable' => ['nullable', 'boolean'],
            'condition' => ['nullable', Rule::in(ListingCondition::values())],

            'region_id' => ['required_without:region_slug', 'integer', 'exists:regions,id'],
            'region_slug' => ['nullable', 'string', 'exists:regions,slug'],
            'district_id' => ['required_without:district_slug', 'integer', 'exists:districts,id'],
            'district_slug' => ['nullable', 'string', 'exists:districts,slug'],
            'ward_id' => ['nullable', 'integer', 'exists:wards,id'],
            'ward_slug' => ['nullable', 'string', 'exists:wards,slug'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'available_from' => ['nullable', 'date', 'after_or_equal:today'],

            'attributes' => ['nullable', 'array', new ValidCategoryAttributes($this->integer('category_id') ?: null)],

            'amenities' => ['nullable', 'array', 'max:40'],
            'amenities.*' => ['integer', 'exists:amenities,id'],
            'facilities' => ['nullable', 'array', 'max:40'],
            'facilities.*' => ['integer', 'exists:facilities,id'],
        ];
    }

    /**
     * Accept slugs wherever an id is expected.
     *
     * Subclasses that override this must call back into it — the update
     * request does.
     */
    protected function prepareForValidation(): void
    {
        $this->resolveSlugReferences([
            'category_id' => Category::class,
            'region_id' => Region::class,
            'district_id' => District::class,
            'ward_id' => Ward::class,
        ]);

        $this->resolveSlugList('amenities', Amenity::class);
        $this->resolveSlugList('facilities', Facility::class);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator): void {
            // The location hierarchy must be internally consistent — a district
            // in Arusha under a region of Dar es Salaam is not a valid address.
            $regionId = $this->integer('region_id');
            $districtId = $this->integer('district_id');

            if ($regionId && $districtId) {
                $belongs = District::where('id', $districtId)
                    ->where('region_id', $regionId)->exists();

                if (! $belongs) {
                    $validator->errors()->add('district_id', 'That district does not belong to the selected region.');
                }
            }

            $wardId = $this->integer('ward_id');

            if ($districtId && $wardId) {
                $belongs = Ward::where('id', $wardId)
                    ->where('district_id', $districtId)->exists();

                if (! $belongs) {
                    $validator->errors()->add('ward_id', 'That ward does not belong to the selected district.');
                }
            }
        });
    }
}
