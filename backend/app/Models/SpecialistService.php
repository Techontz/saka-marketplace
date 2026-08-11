<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Booking\Enums\ServiceMode;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something a specialist sells: a consultation, a lesson, a survey.
 *
 * Hangs off the specialist's LISTING — see the migration for why a specialist
 * is a listing rather than a parallel profile table.
 *
 * @property int $id
 * @property string $uuid
 * @property int $listing_id
 * @property string $name
 * @property int $duration_minutes
 * @property int $buffer_minutes
 * @property int|null $price_amount
 * @property string $currency
 * @property ServiceMode $mode
 * @property bool $is_active
 */
class SpecialistService extends Model
{
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'listing_id', 'name', 'description', 'duration_minutes', 'buffer_minutes',
        'price_amount', 'currency', 'mode', 'is_active', 'position',
    ];

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'mode' => ServiceMode::class,
            'duration_minutes' => 'integer',
            'buffer_minutes' => 'integer',
            'price_amount' => 'integer',
            'is_active' => 'boolean',
            'position' => 'integer',
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

    /** @return HasMany<SpecialistBooking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(SpecialistBooking::class);
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * How much of the specialist's diary one appointment consumes.
     *
     * Duration plus buffer. The customer is quoted `duration_minutes` — the
     * length of their own appointment — while the calendar reserves this, which
     * is why the two are separate columns.
     */
    public function blockedMinutes(): int
    {
        return $this->duration_minutes + $this->buffer_minutes;
    }
}
