<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recurring working window: "Tuesdays, 09:00 to 13:00".
 *
 * Several rows per weekday is the point — a lunch break is two windows, not a
 * window with a hole in it.
 *
 * @property int $listing_id
 * @property int $weekday 0 = Sunday .. 6 = Saturday, matching Carbon::dayOfWeek
 * @property string $start_time
 * @property string $end_time
 */
class SpecialistAvailability extends Model
{
    use HasFactory;

    protected $table = 'specialist_availability';

    protected $fillable = ['listing_id', 'weekday', 'start_time', 'end_time'];

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    /** @return BelongsTo<Listing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
