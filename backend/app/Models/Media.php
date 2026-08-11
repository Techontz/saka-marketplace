<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Media\Enums\MediaCollection;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $uuid
 * @property string|null $mediable_type
 * @property int|null $mediable_id
 * @property MediaCollection $collection
 * @property string $disk
 * @property string $path
 * @property string $original_filename
 * @property string $mime_type
 * @property string $extension
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string|null $alt_text
 * @property int $position
 * @property bool $is_primary
 * @property array<array-key, mixed>|null $variants
 * @property string $processing_status
 * @property string|null $checksum
 * @property int|null $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|\Eloquent|null $mediable
 * @property-read User|null $uploader
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereAltText($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereChecksum($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereCollection($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereDisk($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereExtension($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereHeight($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereIsPrimary($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereMediableId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereMediableType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereMimeType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereOriginalFilename($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media wherePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media wherePosition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereProcessingStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereSizeBytes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereUploadedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereVariants($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Media whereWidth($value)
 *
 * @mixin \Eloquent
 */
class Media extends Model
{
    use HasFactory;
    use HasUuid;

    protected $table = 'media';

    protected $fillable = [
        'mediable_type', 'mediable_id', 'collection', 'disk', 'path',
        'original_filename', 'mime_type', 'extension', 'size_bytes',
        'width', 'height', 'alt_text', 'position', 'is_primary',
        'variants', 'processing_status', 'checksum', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'collection' => MediaCollection::class,
            'variants' => 'array',
            'is_primary' => 'boolean',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'position' => 'integer',
        ];
    }

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Public URL for a given variant, resolved against the disk recorded on the
     * row — NOT the currently configured default. That is what lets files
     * written before an S3 migration keep resolving afterwards.
     */
    public function url(?string $variant = null): string
    {
        $path = $variant !== null && isset($this->variants[$variant]['path'])
            ? $this->variants[$variant]['path']
            : $this->path;

        return Storage::disk($this->disk)->url($path);
    }

    /** Signed, expiring URL. Used for private collections (ID documents). */
    public function temporaryUrl(int $minutes = 5): string
    {
        return Storage::disk($this->disk)->temporaryUrl($this->path, now()->addMinutes($minutes));
    }

    public function isPrivate(): bool
    {
        return $this->collection->isPrivate();
    }
}
