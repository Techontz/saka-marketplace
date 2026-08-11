<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One EAV cell. Exactly one typed value column is populated, chosen by the
 * attribute's data_type.
 *
 * @property int $id
 * @property int $listing_id
 * @property int $attribute_id
 * @property int|null $attribute_option_id
 * @property string|null $value_string
 * @property int|null $value_integer
 * @property numeric|null $value_decimal
 * @property bool|null $value_boolean
 * @property Carbon|null $value_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Attribute $attribute
 * @property-read Listing|null $listing
 * @property-read AttributeOption|null $option
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingAttributeValue newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingAttributeValue newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingAttributeValue query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingAttributeValue whereAttributeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingAttributeValue whereAttributeOptionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingAttributeValue whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingAttributeValue whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingAttributeValue whereListingId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingAttributeValue whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingAttributeValue whereValueBoolean($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingAttributeValue whereValueDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingAttributeValue whereValueDecimal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingAttributeValue whereValueInteger($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListingAttributeValue whereValueString($value)
 *
 * @mixin \Eloquent
 */
class ListingAttributeValue extends Model
{
    use HasFactory;

    protected $table = 'listing_attribute_values';

    protected $fillable = [
        'listing_id', 'attribute_id', 'attribute_option_id',
        'value_string', 'value_integer', 'value_decimal', 'value_boolean', 'value_date',
    ];

    protected function casts(): array
    {
        return [
            'value_integer' => 'integer',
            'value_decimal' => 'decimal:4',
            'value_boolean' => 'boolean',
            'value_date' => 'date',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(AttributeOption::class, 'attribute_option_id');
    }

    /** The populated value, whichever column it lives in. */
    public function value(): mixed
    {
        return $this->value_string
            ?? $this->value_integer
            ?? $this->value_decimal
            ?? $this->value_boolean
            ?? $this->value_date;
    }
}
