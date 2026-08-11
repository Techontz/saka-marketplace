<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Engagement;

use Illuminate\Foundation\Http\FormRequest;

class StoreInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // guests may inquire
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'listing_slug' => ['nullable', 'string', 'max:200'],
            // Required from a GUEST. A signed-in sender's identity is taken
            // from their account instead — see prepareForValidation.
            'first_name' => ['required', 'string', 'min:2', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:191'],
            'phone' => ['nullable', 'string', 'max:20'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],

            // Honeypot: a real browser leaves this empty because it is hidden.
            // Bots fill every field they find.
            'website' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'website.prohibited' => 'This submission was rejected.',
        ];
    }

    protected function prepareForValidation(): void
    {
        /*
         * An authenticated sender IS their account.
         *
         * The name and email are overwritten from the user record rather than
         * trusted from the payload: the inquiry is stamped with
         * `sender_user_id`, so a client that sent someone else's address would
         * show a seller a name and email that do not belong to the account the
         * message is attributed to. It also means a signed-in customer never
         * has to retype what SAKA already knows.
         */
        /*
         * `user('sanctum')`, not `user()`.
         *
         * This route is deliberately public, so it carries no auth middleware —
         * which means Laravel never resolves the sanctum guard and the DEFAULT
         * guard answers `user()` with null even when a valid bearer token was
         * sent. Every inquiry from a signed-in customer was therefore stored as
         * if a guest had sent it, and their inquiry history was always empty.
         * `actingAs()` hid it in tests by setting the default resolver too.
         */
        $user = $this->user('sanctum') ?? $this->user();

        if ($user !== null) {
            $this->merge([
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'phone' => $this->input('phone') ?: $user->phone,
            ]);
        }

        if (is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }
    }
}
