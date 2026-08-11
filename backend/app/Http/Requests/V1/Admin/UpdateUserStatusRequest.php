<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Admin;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        // Banning is a heavier action than suspending, so it needs its own
        // permission rather than sharing one.
        return $this->input('status') === UserStatus::Banned->value
            ? $user->hasPermission(Permission::UserBan)
            : $user->hasPermission(Permission::UserSuspend);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(UserStatus::values())],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
