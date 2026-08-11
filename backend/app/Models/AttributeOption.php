<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $attribute_id
 * @property string $value
 * @property string $label
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Attribute $attribute
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption whereAttributeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption whereLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption wherePosition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeOption whereValue($value)
 *
 * @mixin \Eloquent
 */
class AttributeOption extends Model
{
    use HasFactory;

    protected $fillable = ['attribute_id', 'value', 'label', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }
}
