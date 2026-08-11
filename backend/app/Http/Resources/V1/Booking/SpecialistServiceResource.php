<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Booking;

use App\Models\SpecialistService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SpecialistService
 *
 * A service on a specialist's menu.
 *
 * `buffer_minutes` is deliberately ABSENT from the public shape. It is the
 * specialist's own scheduling padding — how long they need between
 * appointments — and quoting it to a customer alongside a 60-minute
 * consultation invites "why am I being charged for 75 minutes?". The vendor's
 * own view carries it; this one does not.
 */
class SpecialistServiceResource extends JsonResource
{
    /** Set on the vendor's own view, which sees its scheduling internals. */
    private bool $forOwner = false;

    public function forOwner(): self
    {
        $this->forOwner = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'duration_minutes' => $this->duration_minutes,
            'mode' => $this->mode->value,
            'mode_label' => $this->mode->label(),
            'is_active' => $this->is_active,

            /*
             * Null price is "on enquiry", not free.
             *
             * A common and legitimate way for professionals to sell, so the
             * shape distinguishes it — a client rendering `0` here would tell
             * customers a barrister works for nothing.
             */
            'price' => $this->price_amount === null ? null : [
                'amount' => (int) $this->price_amount,
                'currency' => $this->currency,
            ],
        ];

        if (! $this->forOwner) {
            return $data;
        }

        return array_merge($data, [
            'buffer_minutes' => $this->buffer_minutes,
            'position' => $this->position,
            'bookings_count' => $this->whenCounted('bookings'),
        ]);
    }
}
