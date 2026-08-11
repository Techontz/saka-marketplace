<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Assigns a UUID on create and routes model binding through it.
 *
 * Auto-increment ids are never exposed by the API: they leak inventory volume
 * and invite enumeration.
 */
trait HasUuid
{
    protected static function bootHasUuid(): void
    {
        static::creating(function (self $model): void {
            $column = $model->getUuidColumn();

            if (empty($model->{$column})) {
                $model->{$column} = (string) Str::uuid7();
            }
        });
    }

    public function getUuidColumn(): string
    {
        return 'uuid';
    }

    public function getRouteKeyName(): string
    {
        return $this->getUuidColumn();
    }
}
