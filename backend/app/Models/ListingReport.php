<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One abuse report against a listing.
 *
 * The reasons are a fixed vocabulary rather than free text so a moderator can
 * filter by them and so the same complaint is not filed under nine spellings.
 * `details` stays free text because the useful part of a report is usually the
 * bit that does not fit a dropdown.
 */
class ListingReport extends Model
{
    use HasUuid;

    public const REASONS = [
        'scam' => 'Scam or fraud',
        'not_available' => 'Already sold or not available',
        'wrong_category' => 'Listed in the wrong category',
        'duplicate' => 'Duplicate listing',
        'misleading' => 'Misleading photos or description',
        'offensive' => 'Offensive or inappropriate',
        'stolen_content' => 'Photos or text taken from someone else',
        'other' => 'Something else',
    ];

    protected $fillable = [
        'listing_id', 'reporter_id', 'reason', 'details',
        'contact_email', 'reporter_ip_hash',
    ];

    protected $guarded = ['id', 'uuid', 'status', 'resolved_by', 'resolved_at', 'resolution_note'];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
