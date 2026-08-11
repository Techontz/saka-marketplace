<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Listing;

class UpdateListingRequest extends StoreListingRequest
{
    public function authorize(): bool
    {
        $listing = $this->route('listing');

        return $listing !== null && ($this->user()?->can('update', $listing) ?? false);
    }

    /**
     * Same shape as create, but every field optional — PATCH semantics.
     * `category_id` stays required-if-present so the EAV rule always has a
     * category to validate against.
     *
     * Only the presence rules are relaxed: `required_with` pairings (latitude
     * and longitude) still hold, because half a coordinate is never valid.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        foreach ($rules as $field => $fieldRules) {
            $rules[$field] = array_values(array_map(
                static fn ($rule) => is_string($rule)
                    && ($rule === 'required' || str_starts_with($rule, 'required_without:'))
                        ? 'sometimes'
                        : $rule,
                (array) $fieldRules,
            ));
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        // The EAV rule needs a category: fall back to the listing's current one
        // when the client is not changing it.
        if (! $this->has('category_id')) {
            $listing = $this->route('listing');

            if ($listing !== null) {
                $this->merge(['category_id' => $listing->category_id]);
            }
        }
    }
}
