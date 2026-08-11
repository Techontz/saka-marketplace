<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Channels\LogChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Delivers the one-time phone code.
 *
 * MVP ships the `log` channel only — an SMS gateway (Africa's Talking / Twilio)
 * is a v1.1 integration with a real per-message cost. The channel list is the
 * only thing that changes when it lands.
 *
 * NOT queued: an OTP that arrives after the user has given up is worthless, and
 * queue latency is exactly the wrong thing to add here.
 */
class PhoneOtpNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $code,
        private readonly string $phone,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return [LogChannel::class];
    }

    /** @return array<string, mixed> */
    public function toLog(object $notifiable): array
    {
        return [
            'channel' => 'sms',
            'to' => $this->phone,
            'message' => "Your SAKA verification code is {$this->code}. It expires in "
                .config('saka.otp.ttl_minutes').' minutes.',
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        // The code is NEVER persisted to the notifications table.
        return ['phone' => $this->phone, 'type' => 'phone_otp'];
    }
}
