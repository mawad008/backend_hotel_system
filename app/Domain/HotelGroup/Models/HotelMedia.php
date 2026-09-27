<?php

namespace App\Domain\HotelGroup\Models;

use Database\Factories\HotelMediaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One stored image belonging to a Hotel. `path` is disk-relative; the public
 * URL is derived on read from the recorded `disk`.
 */
class HotelMedia extends Model
{
    use HasFactory;

    protected $table = 'hotel_media';

    public const COLLECTION_LOGO = 'logo';

    public const COLLECTION_COVER = 'cover';

    public const COLLECTION_GALLERY = 'gallery';

    protected $fillable = [
        'hotel_id',
        'collection',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'size',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /**
     * Absolute URL for the stored file. Uses the disk recorded on the row so
     * older rows keep resolving after a disk config change.
     */
    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    protected static function newFactory(): HotelMediaFactory
    {
        return HotelMediaFactory::new();
    }
}
