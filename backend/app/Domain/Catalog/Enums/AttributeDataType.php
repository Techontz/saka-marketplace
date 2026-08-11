<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * Which typed column in `listing_attribute_values` a value is written to.
 *
 * Typed columns (rather than one VARCHAR) are what make `beds >= 2` an index
 * range scan instead of a string comparison.
 */
enum AttributeDataType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Date = 'date';

    /** Column on listing_attribute_values that stores this type. */
    public function column(): string
    {
        return match ($this) {
            self::String => 'value_string',
            self::Integer => 'value_integer',
            self::Decimal => 'value_decimal',
            self::Boolean => 'value_boolean',
            self::Date => 'value_date',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
