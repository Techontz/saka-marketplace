<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Order, visibility and headings for a homepage section.
 *
 * `key` joins to a React component, so it is set by the seeder and never
 * editable through the API — renaming it would orphan the section rather than
 * rename it.
 *
 * @property int $id
 * @property string $key
 * @property string $title
 * @property string|null $subtitle
 * @property int $position
 * @property bool $is_active
 * @property int|null $item_limit
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomepageSection newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomepageSection newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomepageSection query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomepageSection whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomepageSection whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomepageSection whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomepageSection whereItemLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomepageSection whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomepageSection wherePosition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomepageSection whereSubtitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomepageSection whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomepageSection whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class HomepageSection extends Model
{
    protected $fillable = ['title', 'subtitle', 'position', 'is_active', 'item_limit'];

    /** Set at seed time; a client must never be able to repoint a section. */
    protected $guarded = ['id', 'key'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
            'item_limit' => 'integer',
        ];
    }
}
