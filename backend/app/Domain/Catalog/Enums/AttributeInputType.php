<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * How an attribute is rendered and filtered.
 *
 * This is what lets a new vertical ship without a migration: the frontend
 * builds its filter UI from the category's attribute set, and the backend
 * builds its validation rules from the same data.
 */
enum AttributeInputType: string
{
    case Text = 'text';
    case Number = 'number';
    case Select = 'select';
    case MultiSelect = 'multiselect';
    case Boolean = 'boolean';
    case Range = 'range';
    case Date = 'date';

    public function expectsOptions(): bool
    {
        return in_array($this, [self::Select, self::MultiSelect], strict: true);
    }

    public function isMultiValued(): bool
    {
        return $this === self::MultiSelect;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
