<?php

namespace App\Domain\Inventory\Models;

use Database\Factories\RoomMediaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/**
 * One stored image belonging to a Room or a Room Type (see the
 * `mediable_type`/`mediable_id` columns). `path` is disk-relative; the
 * public URL is derived on read from the recorded `disk`.
 */
class RoomMedia extends Model
{
    use HasFactory;

    protected $table = 'room_media';

    public const COLLECTION_GALLERY = 'gallery';

    protected $fillable = [
        'mediable_id',
        'mediable_type',
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

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Absolute URL for the stored file. Uses the disk recorded on the row so
     * older rows keep resolving after a disk config change.
     */
    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    protected static function newFactory(): RoomMediaFactory
    {
        return RoomMediaFactory::new();
    }
}
