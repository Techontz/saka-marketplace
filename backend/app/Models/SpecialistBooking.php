<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Booking\Enums\BookingStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One appointment.
 *
 * `slot_key` is NOT in `$fillable` and never written by PHP — it is a STORED
 * generated column maintained by MySQL from `status`, `scheduled_date` and
 * `start_time`, and it is half of the unique index that makes double booking
 * impossible. Assigning it here would be rejected by the database.
 *
 * @property int $id
 * @property string $uuid
 * @property int $listing_id
 * @property int $specialist_service_id
 * @property int|null $user_id
 * @property Carbon $scheduled_date
 * @property string $start_time
 * @property string $end_time
 * @property string $timezone
 * @property Carbon $starts_at_utc
 * @property Carbon $ends_at_utc
 * @property BookingStatus $status
 */
class SpecialistBooking extends Model
{
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'listing_id', 'specialist_service_id', 'user_id',
        'customer_name', 'customer_email', 'customer_phone',
        'scheduled_date', 'start_time', 'end_time', 'timezone',
        'starts_at_utc', 'ends_at_utc', 'customer_note',
    ];

    /**
     * `status` is guarded: it moves only through BookingService, which checks
     * the transition table and the actor's right to make the move. A customer
     * who could mass-assign it could confirm their own appointment.
     */
    protected $guarded = [
        'id', 'uuid', 'status', 'slot_key',
        'responded_at', 'cancelled_at', 'cancelled_by', 'specialist_note',
    ];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'scheduled_date' => 'date',
            'starts_at_utc' => 'datetime',
            'ends_at_utc' => 'datetime',
            'responded_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Listing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /** @return BelongsTo<SpecialistService, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(SpecialistService::class, 'specialist_service_id');
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Bookings that hold their slot. Mirrors the generated column exactly. */
    public function scopeOccupying(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            fn (BookingStatus $status): string => $status->value,
            BookingStatus::occupying(),
        ));
    }

    /** @param  Builder<static>  $query */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('starts_at_utc', '>=', Carbon::now());
    }

    /**
     * The appointment as a wall-clock string in its own timezone.
     *
     * Built from the LOCAL columns, not from the UTC ones — those exist for
     * ordering. Rendering from UTC would move every historical appointment the
     * day a market changed its offset.
     */
    public function localLabel(): string
    {
        return $this->scheduled_date->format('D j M').' at '.substr($this->start_time, 0, 5);
    }
}
