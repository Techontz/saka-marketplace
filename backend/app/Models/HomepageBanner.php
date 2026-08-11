<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A promotional banner on the marketing surface.
 *
 * @property int $id
 * @property string $uuid
 * @property string $title
 * @property string|null $subtitle
 * @property string|null $link_url
 * @property string|null $link_label
 * @property int|null $image_media_id
 * @property string $placement
 * @property int $position
 * @property bool $is_active
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Media|null $image
 *
 * @method static Builder<static>|HomepageBanner live()
 * @method static Builder<static>|HomepageBanner newModelQuery()
 * @method static Builder<static>|HomepageBanner newQuery()
 * @method static Builder<static>|HomepageBanner query()
 * @method static Builder<static>|HomepageBanner whereCreatedAt($value)
 * @method static Builder<static>|HomepageBanner whereEndsAt($value)
 * @method static Builder<static>|HomepageBanner whereId($value)
 * @method static Builder<static>|HomepageBanner whereImageMediaId($value)
 * @method static Builder<static>|HomepageBanner whereIsActive($value)
 * @method static Builder<static>|HomepageBanner whereLinkLabel($value)
 * @method static Builder<static>|HomepageBanner whereLinkUrl($value)
 * @method static Builder<static>|HomepageBanner wherePlacement($value)
 * @method static Builder<static>|HomepageBanner wherePosition($value)
 * @method static Builder<static>|HomepageBanner whereStartsAt($value)
 * @method static Builder<static>|HomepageBanner whereSubtitle($value)
 * @method static Builder<static>|HomepageBanner whereTitle($value)
 * @method static Builder<static>|HomepageBanner whereUpdatedAt($value)
 * @method static Builder<static>|HomepageBanner whereUuid($value)
 *
 * @mixin \Eloquent
 */
class HomepageBanner extends Model
{
    use HasUuid;

    protected $fillable = [
        'title', 'subtitle', 'link_url', 'link_label',
        'image_media_id', 'placement', 'position', 'is_active',
        'starts_at', 'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Media, $this> */
    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_media_id');
    }

    /**
     * Active AND within its schedule.
     *
     * `is_active` alone is not enough: a banner whose campaign has ended is
     * still flagged active, and showing an expired promotion is worse than
     * showing none.
     *
     * @param  Builder<HomepageBanner>  $query
     * @return Builder<HomepageBanner>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }
}
