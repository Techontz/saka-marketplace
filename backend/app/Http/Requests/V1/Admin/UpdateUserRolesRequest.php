<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Admin;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\RoleSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::UserAssignRole) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'roles' => ['required', 'array', 'max:6'],
            // super_admin is excluded at the validation layer AND re-checked
            // against roles.is_assignable in the controller.
            'roles.*' => ['string', Rule::in(array_values(array_diff(
                RoleSlug::values(),
                [RoleSlug::SuperAdmin->value],
            )))],
        ];
    }
}
