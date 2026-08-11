<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Booking;

use App\Models\SpecialistBooking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SpecialistBooking
 *
 * One appointment.
 *
 * THREE audiences read this — the customer, the specialist and an
 * administrator — and the customer's contact details are only in it for the
 * latter two. `forSpecialist()` is what opens that up; without it the payload
 * carries no phone number, so a customer fetching their own booking cannot
 * enumerate anybody else's contact details even if a scoping bug let them
 * reach the row.
 */
class SpecialistBookingResource extends JsonResource
{
    private bool $forSpecialist = false;

    public function forSpecialist(): self
    {
        $this->forSpecialist = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = [
            'uuid' => $this->uuid,

            /*
             * The LOCAL wall clock, plus its zone.
             *
             * What both parties agreed and wrote down. `starts_at_utc` is also
             * sent so a client can sort and compare correctly across zones, but
             * everything DISPLAYED is built from these.
             */
            'scheduled_date' => $this->scheduled_date->toDateString(),
            'start_time' => substr($this->start_time, 0, 5),
            'end_time' => substr($this->end_time, 0, 5),
            'timezone' => $this->timezone,
            'starts_at_utc' => $this->starts_at_utc->toIso8601String(),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_cancellable' => $this->status->isCancellable(),
            'awaits_specialist' => $this->status->awaitsSpecialist(),

            'service' => $this->whenLoaded('service', fn (): ?array => $this->service === null ? null : [
                'uuid' => $this->service->uuid,
                'name' => $this->service->name,
                'duration_minutes' => $this->service->duration_minutes,
                'mode' => $this->service->mode->value,
            ]),

            'specialist' => $this->whenLoaded('listing', fn (): ?array => $this->listing === null ? null : [
                'slug' => $this->listing->slug,
                'title' => $this->listing->title,
            ]),

            'customer_note' => $this->customer_note,
            'specialist_note' => $this->specialist_note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        if (! $this->forSpecialist) {
            return $data;
        }

        // Only the specialist and an administrator need to be able to ring the
        // customer. A booking is worthless to a specialist who cannot.
        return array_merge($data, [
            'customer' => [
                'name' => $this->customer_name,
                'email' => $this->customer_email,
                'phone' => $this->customer_phone,
                // Whether they have a SAKA account, which changes what the
                // specialist can expect them to see.
                'is_registered' => $this->user_id !== null,
            ],
            'cancelled_by' => $this->cancelled_by,
        ]);
    }
}
