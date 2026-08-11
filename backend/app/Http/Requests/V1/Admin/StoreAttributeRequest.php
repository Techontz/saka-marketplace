<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Admin;

use App\Domain\Catalog\Enums\AttributeDataType;
use App\Domain\Catalog\Enums\AttributeInputType;
use App\Domain\Identity\Enums\Permission;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttributeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::AttributeManage) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $attribute = $this->route('attribute');
        $required = $attribute !== null ? 'sometimes' : 'required';

        return [
            // The code is a public filter key (?attributes[beds]=...), so it is
            // constrained to a safe identifier shape and never changed on update.
            'code' => [$attribute !== null ? 'prohibited' : 'required', 'string', 'max:60',
                'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('attributes', 'code')],
            'name' => [$required, 'string', 'min:2', 'max:120'],
            'input_type' => [$required, Rule::in(AttributeInputType::values())],
            'data_type' => [$required, Rule::in(AttributeDataType::values())],
            'unit' => ['nullable', 'string', 'max:20'],
            'is_filterable' => ['nullable', 'boolean'],
            'is_searchable' => ['nullable', 'boolean'],
            'is_required' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'min_value' => ['nullable', 'numeric'],
            'max_value' => ['nullable', 'numeric', 'gte:min_value'],
            'options' => ['nullable', 'array', 'max:200'],
            'options.*.value' => ['nullable', 'string', 'max:120'],
            'options.*.label' => ['required_with:options', 'string', 'max:120'],
            'options.*.position' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator): void {
            $input = $this->input('input_type');
            $options = $this->input('options', []);

            // A select with no options is unusable, and a number with options
            // is a modelling mistake — catch both here rather than in support.
            if (in_array($input, ['select', 'multiselect'], true) && $options === []) {
                $validator->errors()->add('options', 'A select attribute needs at least one option.');
            }

            if (in_array($input, ['number', 'boolean', 'date'], true) && $options !== []) {
                $validator->errors()->add('options', 'This input type does not take options.');
            }
        });
    }
}
