<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Models\PhoneVerificationCode;
use App\Models\User;
use App\Notifications\PhoneOtpNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Phone OTP — the gate on publishing (Milestone 4 decision 5).
 *
 * The plaintext code exists only in memory and in the outbound message; the
 * database stores a hash. Attempts are counted per code, and a successful
 * verification consumes the row rather than deleting it so replay is visible.
 */
class PhoneVerificationService
{
    public function request(User $user, string $phone): void
    {
        $phone = $this->normalise($phone);

        $this->assertPhoneAvailable($phone, $user);

        $code = $this->generateCode();

        DB::transaction(function () use ($user, $phone, $code): void {
            // Invalidate any outstanding codes for this phone: a user who asks
            // for a new code should not be able to use the old one.
            PhoneVerificationCode::where('phone', $phone)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            PhoneVerificationCode::create([
                'user_id' => $user->id,
                'phone' => $phone,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes((int) config('saka.otp.ttl_minutes')),
                'ip_address' => request()->ip(),
            ]);
        });

        $user->notify(new PhoneOtpNotification($code, $phone));
    }

    /**
     * Verifies a code and, on success, marks the phone verified on the user.
     */
    public function verify(User $user, string $phone, string $code): User
    {
        $phone = $this->normalise($phone);

        $record = PhoneVerificationCode::where('phone', $phone)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if ($record === null) {
            throw ApiException::make(ErrorCode::OtpInvalid);
        }

        if ($record->expires_at->isPast()) {
            throw ApiException::make(ErrorCode::OtpExpired);
        }

        if ($record->attempts >= (int) config('saka.otp.max_attempts')) {
            throw ApiException::make(ErrorCode::OtpMaxAttempts);
        }

        if (! Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');

            throw ApiException::make(ErrorCode::OtpInvalid);
        }

        return DB::transaction(function () use ($record, $user, $phone): User {
            $record->forceFill(['consumed_at' => now()])->save();

            $user->forceFill([
                'phone' => $phone,
                'phone_verified_at' => now(),
            ])->save();

            return $user->fresh();
        });
    }

    /**
     * E.164-ish normalisation for Tanzanian numbers.
     * `0712345678` and `255712345678` both become `+255712345678`, so the
     * uniqueness constraint on users.phone actually means something.
     */
    public function normalise(string $phone): string
    {
        $digits = preg_replace('/[^\d+]/', '', $phone) ?? '';

        if (str_starts_with($digits, '+')) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            return '+255'.substr($digits, 1);
        }

        if (str_starts_with($digits, '255')) {
            return '+'.$digits;
        }

        return '+'.$digits;
    }

    private function assertPhoneAvailable(string $phone, User $user): void
    {
        $taken = User::where('phone', $phone)
            ->whereKeyNot($user->getKey())
            ->whereNotNull('phone_verified_at')
            ->exists();

        if ($taken) {
            throw ApiException::make(ErrorCode::PhoneAlreadyRegistered);
        }
    }

    private function generateCode(): string
    {
        $length = (int) config('saka.otp.length');

        // random_int, not rand(): this is a security token.
        return str_pad(
            (string) random_int(0, (10 ** $length) - 1),
            $length,
            '0',
            STR_PAD_LEFT,
        );
    }
}
