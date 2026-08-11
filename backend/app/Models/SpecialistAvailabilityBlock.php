<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A period the specialist is unavailable, overriding their working hours.
 *
 * Timestamps rather than dates: "Thursday afternoon" is a real block, and a
 * date-only model would take out the whole day.
 *
 * @property int $listing_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string|null $reason
 */
class SpecialistAvailabilityBlock extends Model
{
    use HasFactory;

    protected $table = 'specialist_availability_blocks';

    protected $fillable = ['listing_id', 'starts_at', 'ends_at', 'reason'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    /** @return BelongsTo<Listing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
