<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Inquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a seller they have a new inquiry. Queued — an inquiry must be recorded
 * whether or not the mail server is reachable.
 */
class NewInquiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Inquiry $inquiry) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->inquiry->listing?->title ?? 'your listing';

        return (new MailMessage)
            ->subject('New inquiry on '.$title)
            ->greeting('Hello '.$notifiable->first_name.',')
            ->line($this->inquiry->first_name.' has sent you an inquiry about '.$title.'.')
            // The message body is deliberately NOT interpolated into the email:
            // it is unvalidated user input from a public endpoint.
            ->line('Sign in to read it and reply.')
            ->action('View inquiry', rtrim((string) config('saka.frontend_url'), '/').'/dashboard/inquiries');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'inquiry.created',
            'inquiry_uuid' => $this->inquiry->uuid,
            'listing_uuid' => $this->inquiry->listing?->uuid,
            'listing_title' => $this->inquiry->listing?->title,
            'from' => $this->inquiry->first_name,
        ];
    }
}
