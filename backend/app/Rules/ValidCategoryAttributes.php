<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Catalog\Enums\AttributeInputType;
use App\Models\Attribute;
use App\Models\Category;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the EAV payload against the CATEGORY'S OWN attribute set.
 *
 * This is what lets a tenth vertical ship without touching validation code: the
 * rules are built at runtime from `category_attribute`, so a Vehicle is checked
 * for `mileage` and a Job for `employment_type` through the same class.
 */
class ValidCategoryAttributes implements ValidationRule
{
    public function __construct(private readonly ?int $categoryId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->categoryId === null) {
            return; // the category_id rule reports this
        }

        $category = Category::find($this->categoryId);

        if ($category === null) {
            return;
        }

        $values = is_array($value) ? $value : [];
        $definitions = $category->resolvedAttributes()->keyBy('code');

        // Unknown codes are rejected on WRITE (unlike on read, where a stale
        // bookmark should degrade rather than 500).
        foreach (array_keys($values) as $code) {
            if (! $definitions->has($code)) {
                $fail("The attribute [{$code}] does not apply to {$category->name}.");
            }
        }

        foreach ($definitions as $code => $definition) {
            $provided = $values[$code] ?? null;
            $isRequired = (bool) ($definition->getAttribute('is_required') ?? false);

            if ($provided === null || $provided === '' || $provided === []) {
                if ($isRequired) {
                    $fail("The attribute [{$code}] is required for {$category->name}.");
                }

                continue;
            }

            $this->validateValue($definition, $code, $provided, $fail);
        }
    }

    private function validateValue(Attribute $definition, string $code, mixed $provided, Closure $fail): void
    {
        if ($definition->input_type->expectsOptions()) {
            $incoming = is_array($provided) ? $provided : [$provided];

            if (! $definition->input_type->isMultiValued() && count($incoming) > 1) {
                $fail("The attribute [{$code}] accepts a single value.");

                return;
            }

            $valid = $definition->options()->pluck('value')->all();

            foreach ($incoming as $candidate) {
                if (! in_array((string) $candidate, $valid, true)) {
                    $fail("The value [{$candidate}] is not valid for [{$code}].");
                }
            }

            return;
        }

        if (is_array($provided)) {
            $fail("The attribute [{$code}] does not accept multiple values.");

            return;
        }

        if ($definition->input_type === AttributeInputType::Number) {
            if (! is_numeric($provided)) {
                $fail("The attribute [{$code}] must be a number.");

                return;
            }

            $number = (float) $provided;

            if ($definition->min_value !== null && $number < (float) $definition->min_value) {
                $fail("The attribute [{$code}] must be at least {$definition->min_value}.");
            }

            if ($definition->max_value !== null && $number > (float) $definition->max_value) {
                $fail("The attribute [{$code}] must not exceed {$definition->max_value}.");
            }

            return;
        }

        if ($definition->input_type === AttributeInputType::Boolean
            && ! is_bool(filter_var($provided, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE))) {
            $fail("The attribute [{$code}] must be true or false.");

            return;
        }

        if ($definition->input_type === AttributeInputType::Date
            && strtotime((string) $provided) === false) {
            $fail("The attribute [{$code}] must be a valid date.");
        }

        if ($definition->input_type === AttributeInputType::Text && mb_strlen((string) $provided) > 255) {
            $fail("The attribute [{$code}] must not exceed 255 characters.");
        }
    }
}
