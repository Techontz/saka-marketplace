<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Admin;

use App\Domain\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

/** Shared shape for amenities and facilities — the same fields either way. */
class StoreTaxonomyTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::AmenityManage) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isUpdate = $this->route('slug') !== null;

        return [
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'min:2', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120'],
            'icon' => ['nullable', 'string', 'max:60'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'position' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
