<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Admin;

use App\Domain\Identity\Enums\Permission;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::CategoryManage) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $category = $this->route('category');
        $category = $category instanceof Category ? $category : null;
        $required = $category !== null ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'min:2', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120', Rule::unique('categories', 'slug')
                ->ignore($category?->id)],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'icon' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:2000'],
            'position' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
