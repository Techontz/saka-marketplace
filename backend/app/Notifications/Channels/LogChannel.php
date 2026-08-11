<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Writes a notification to the log instead of sending it.
 *
 * Stands in for the SMS gateway until one is integrated (v1.1). Keeping it as a
 * real channel rather than a `Log::info()` sprinkled through the service means
 * swapping in Africa's Talking or Twilio is a one-line change to `via()`.
 */
class LogChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toLog')) {
            return;
        }

        $payload = $notification->toLog($notifiable);

        Log::channel(config('logging.default'))->info('notification.dispatched', [
            'notification' => $notification::class,
            'notifiable_id' => $notifiable->getKey(),
            'payload' => $payload,
        ]);
    }
}
