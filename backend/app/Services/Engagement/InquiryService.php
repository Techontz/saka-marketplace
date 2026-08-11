<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Domain\Engagement\Enums\InquirySource;
use App\Domain\Engagement\Enums\InquiryStatus;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\User;
use App\Notifications\NewInquiryNotification;
use App\Services\Metrics\CounterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Serves BOTH frontend entry points: "Contact Seller" on a listing and the
 * standalone /contact form. Guests may submit either.
 */
class InquiryService
{
    public function __construct(private readonly CustomerNotifier $notifier) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, ?Listing $listing, ?User $sender, Request $request): Inquiry
    {
        if ($listing !== null && $sender !== null && $sender->getKey() === $listing->user_id) {
            throw ApiException::make(
                ErrorCode::Conflict,
                'You cannot send an inquiry about your own listing.',
            );
        }

        $inquiry = DB::transaction(function () use ($data, $listing, $sender, $request): Inquiry {
            $inquiry = Inquiry::create([
                'listing_id' => $listing?->getKey(),
                'seller_id' => $listing?->user_id,
                'sender_user_id' => $sender?->getKey(),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? null,
                'email' => strtolower(trim((string) $data['email'])),
                'phone' => $data['phone'] ?? null,
                'message' => $data['message'],
                'source' => $listing !== null ? InquirySource::Listing : InquirySource::ContactPage,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]);

            if ($listing !== null) {
                app(CounterService::class)->increment('inquiry_count', (int) $listing->getKey());
            }

            // `status` is guarded and comes from a DB default, so the
            // in-memory instance has no value for it until it is re-read.
            return $inquiry->fresh();
        });

        // Notify after commit so the seller is never told about a row that
        // rolled back.
        if ($listing !== null && $listing->user !== null) {
            $listing->user->notify(new NewInquiryNotification($inquiry));
        }

        return $inquiry;
    }

    public function markRead(Inquiry $inquiry): Inquiry
    {
        if ($inquiry->status === InquiryStatus::New) {
            $inquiry->forceFill([
                'status' => InquiryStatus::Read,
                'read_at' => now(),
            ])->save();
        }

        return $inquiry->fresh();
    }

    public function reply(Inquiry $inquiry, string $body): Inquiry
    {
        $inquiry->forceFill([
            'reply_body' => $body,
            'replied_at' => now(),
            'status' => InquiryStatus::Replied,
            'read_at' => $inquiry->read_at ?? now(),
        ])->save();

        $inquiry = $inquiry->fresh();

        // The customer has no other way of learning they were answered — the
        // reply is stored on the row, not emailed.
        $this->notifier->inquiryReplied($inquiry->loadMissing('listing:id,slug,title'));

        return $inquiry;
    }
}
