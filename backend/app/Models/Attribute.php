<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Catalog\Enums\AttributeDataType;
use App\Domain\Catalog\Enums\AttributeInputType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property AttributeInputType $input_type
 * @property AttributeDataType $data_type
 * @property string|null $unit
 * @property bool $is_filterable
 * @property bool $is_searchable
 * @property bool $is_required
 * @property int $position
 * @property numeric|null $min_value
 * @property numeric|null $max_value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Category> $categories
 * @property-read int|null $categories_count
 * @property-read Collection<int, AttributeOption> $options
 * @property-read int|null $options_count
 * @property-read Collection<int, ListingAttributeValue> $values
 * @property-read int|null $values_count
 *
 * @method static Builder<static>|Attribute filterable()
 * @method static Builder<static>|Attribute newModelQuery()
 * @method static Builder<static>|Attribute newQuery()
 * @method static Builder<static>|Attribute query()
 * @method static Builder<static>|Attribute whereCode($value)
 * @method static Builder<static>|Attribute whereCreatedAt($value)
 * @method static Builder<static>|Attribute whereDataType($value)
 * @method static Builder<static>|Attribute whereId($value)
 * @method static Builder<static>|Attribute whereInputType($value)
 * @method static Builder<static>|Attribute whereIsFilterable($value)
 * @method static Builder<static>|Attribute whereIsRequired($value)
 * @method static Builder<static>|Attribute whereIsSearchable($value)
 * @method static Builder<static>|Attribute whereMaxValue($value)
 * @method static Builder<static>|Attribute whereMinValue($value)
 * @method static Builder<static>|Attribute whereName($value)
 * @method static Builder<static>|Attribute wherePosition($value)
 * @method static Builder<static>|Attribute whereUnit($value)
 * @method static Builder<static>|Attribute whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Attribute extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'input_type', 'data_type', 'unit',
        'is_filterable', 'is_searchable', 'is_required', 'position',
        'min_value', 'max_value',
    ];

    protected function casts(): array
    {
        return [
            'input_type' => AttributeInputType::class,
            'data_type' => AttributeDataType::class,
            'is_filterable' => 'boolean',
            'is_searchable' => 'boolean',
            'is_required' => 'boolean',
            'position' => 'integer',
            'min_value' => 'decimal:4',
            'max_value' => 'decimal:4',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** @return HasMany<AttributeOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(AttributeOption::class)->orderBy('position');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_attribute');
    }

    public function values(): HasMany
    {
        return $this->hasMany(ListingAttributeValue::class);
    }

    public function scopeFilterable(Builder $query): Builder
    {
        return $query->where('is_filterable', true);
    }

    /** Column on listing_attribute_values this attribute writes to. */
    public function valueColumn(): string
    {
        return $this->data_type->column();
    }
}
