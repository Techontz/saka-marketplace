<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // `current_password` is required even though the user is already
            // authenticated: it stops an attacker with a stolen token from
            // locking the real owner out by changing the password.
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password',
                Password::defaults()->min(8)->letters()->numbers()],
        ];
    }
}
