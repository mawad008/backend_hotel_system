<?php

namespace App\Domain\Inventory\Concerns;

use App\Domain\Inventory\Models\RoomMedia;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Shared by Room and RoomType: both can carry photos through the single
 * polymorphic `room_media` table. See RoomMediaService for the upload /
 * delete / reorder rules.
 */
trait HasRoomMedia
{
    /**
     * All media rows, in display order. Filtered per-collection by the
     * dedicated accessors below.
     */
    public function media(): MorphMany
    {
        return $this->morphMany(RoomMedia::class, 'mediable')->orderBy('sort_order')->orderBy('id');
    }

    public function galleryMedia(): MorphMany
    {
        return $this->media()->where('collection', RoomMedia::COLLECTION_GALLERY);
    }
}
