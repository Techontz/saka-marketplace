<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $userId = $this->user()->getKey();

        return [
            'first_name' => ['sometimes', 'string', 'min:2', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => ['sometimes', 'email:rfc', 'max:191', Rule::unique('users', 'email')->ignore($userId)],

            /*
             * Phone was MISSING from this list.
             *
             * `validated()` returns only the keys a rule matched, so a PATCH
             * carrying a phone number had it silently dropped: the response was
             * a 200 with the OLD phone, the client kept showing the typed value
             * from its own state, and the change vanished on the next reload.
             * Nothing anywhere reported an error.
             *
             * Unique because the phone is an identity — it is what an OTP is
             * sent to — and two accounts sharing one would make "verify your
             * phone to publish" ambiguous. The character class matches the OTP
             * request rule so a number accepted here cannot be rejected there.
             */
            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'min:9',
                'max:20',
                'regex:/^[+0-9 ()-]+$/',
                Rule::unique('users', 'phone')->ignore($userId),
            ],

            'locale' => ['sometimes', Rule::in(['en', 'sw'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.unique' => 'An account with this phone number already exists.',
            'phone.regex' => 'Enter a phone number using digits, spaces, and + ( ) - only.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }

        // A trailing space is the difference between "already taken" and
        // "saved", and it is not a difference the account holder can see.
        if (is_string($this->phone)) {
            $trimmed = trim($this->phone);
            $this->merge(['phone' => $trimmed === '' ? null : $trimmed]);
        }
    }
}
