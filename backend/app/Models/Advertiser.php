<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Who is paying for an advertisement.
 *
 * Separate from `seller_profiles` because most advertisers are not vendors — a
 * bank, a telco, a developer who sells through an agency — and separate from
 * `users` because the person who signs an insertion order rarely has a login.
 * When the advertiser IS a vendor, `sellerProfile` links the two.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property int|null $seller_profile_id
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $notes
 * @property bool $is_active
 */
class Advertiser extends Model
{
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'seller_profile_id',
        'contact_name', 'contact_email', 'contact_phone',
        'notes', 'is_active',
    ];

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<SellerProfile, $this> */
    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class);
    }

    /** @return HasMany<AdCampaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(AdCampaign::class);
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
