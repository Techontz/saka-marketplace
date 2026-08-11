<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * CMS page. Terms and Privacy both point at /about in the frontend today.
 *
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string|null $body
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|Page newModelQuery()
 * @method static Builder<static>|Page newQuery()
 * @method static Builder<static>|Page published()
 * @method static Builder<static>|Page query()
 * @method static Builder<static>|Page whereBody($value)
 * @method static Builder<static>|Page whereCreatedAt($value)
 * @method static Builder<static>|Page whereId($value)
 * @method static Builder<static>|Page whereMetaDescription($value)
 * @method static Builder<static>|Page whereMetaTitle($value)
 * @method static Builder<static>|Page wherePublishedAt($value)
 * @method static Builder<static>|Page whereSlug($value)
 * @method static Builder<static>|Page whereTitle($value)
 * @method static Builder<static>|Page whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Page extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'title', 'body', 'meta_title', 'meta_description', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }
}
