<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class GoogleSignInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // The Google-issued ID token. Verified SERVER-SIDE against Google's
            // JWKS — a client-supplied profile is never trusted.
            'id_token' => ['required', 'string', 'min:20', 'max:4096'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
