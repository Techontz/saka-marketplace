<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A saved listing or a saved business.
 *
 * Polymorphic because a customer saves both, and un-saving STAMPS rather than
 * deletes: `removed_at` is what makes "favourite history" answerable. The row
 * is therefore permanent per (user, target) pair — re-saving clears the stamp
 * on the original row instead of writing a second one.
 *
 * @property int $id
 * @property int $user_id
 * @property string $favoritable_type
 * @property int $favoritable_id
 * @property Carbon $created_at
 * @property Carbon|null $removed_at
 * @property-read Model|null $favoritable
 * @property-read User|null $user
 *
 * @method static Builder<static>|Favorite active()
 * @method static Builder<static>|Favorite newModelQuery()
 * @method static Builder<static>|Favorite newQuery()
 * @method static Builder<static>|Favorite query()
 *
 * @mixin \Eloquent
 */
class Favorite extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'favoritable_type', 'favoritable_id', 'created_at', 'removed_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function favoritable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Still saved, as opposed to saved-and-then-removed. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    /** @param  Builder<static>  $query */
    public function scopeOfType(Builder $query, string $class): Builder
    {
        return $query->where('favoritable_type', $class);
    }
}
