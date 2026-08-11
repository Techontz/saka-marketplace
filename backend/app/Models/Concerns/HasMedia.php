<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Domain\Media\Enums\MediaCollection;
use App\Models\Media;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Attaches the polymorphic media table to any model.
 */
trait HasMedia
{
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->orderBy('position');
    }

    public function gallery(): MorphMany
    {
        return $this->media()->where('collection', MediaCollection::Gallery->value);
    }

    public function primaryMedia(): MorphOne
    {
        return $this->morphOne(Media::class, 'mediable')
            ->where('collection', MediaCollection::Gallery->value)
            ->orderByDesc('is_primary')
            ->orderBy('position');
    }
}
