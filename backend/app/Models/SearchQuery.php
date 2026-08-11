<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded search.
 *
 * Backs three features off one table: a customer's own recent searches,
 * platform-wide popular searches, and the suggestion ranking. `query` is
 * normalised for grouping; `raw_query` is what was actually typed, because a
 * history that silently lower-cases what someone wrote reads as a bug.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $session_id
 * @property string $query
 * @property string $raw_query
 * @property array<string, mixed>|null $filters
 * @property int $results_count
 * @property Carbon $created_at
 * @property-read User|null $user
 *
 * @mixin \Eloquent
 */
class SearchQuery extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'session_id', 'query', 'raw_query', 'filters', 'results_count', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'results_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Trimmed, collapsed and lower-cased, so "  Masaki   Flat " groups with "masaki flat". */
    public static function normalise(string $query): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $query)));
    }
}
