<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Attribute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Attribute
 *
 * This is what lets the frontend build its filter UI dynamically: input type,
 * unit, bounds and options all come from the API, so a new vertical needs no
 * frontend release.
 */
class AttributeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'input_type' => $this->input_type->value,
            'data_type' => $this->data_type->value,
            'unit' => $this->unit,
            'is_filterable' => (bool) $this->is_filterable,
            'is_required' => (bool) ($this->getAttribute('is_required') ?? $this->is_required),
            'min_value' => $this->min_value !== null ? (float) $this->min_value : null,
            'max_value' => $this->max_value !== null ? (float) $this->max_value : null,
            'options' => $this->whenLoaded('options', fn () => $this->options
                ->map(fn ($o) => ['value' => $o->value, 'label' => $o->label])->all()),
        ];
    }
}
