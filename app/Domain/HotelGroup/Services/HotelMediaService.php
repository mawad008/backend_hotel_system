<?php

namespace App\Domain\HotelGroup\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelMedia;
use App\Domain\HotelGroup\Support\HotelMediaStore;
use App\Domain\IdentityAccess\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Hotel image management. Owns the create/replace/delete/reorder rules for
 * the `logo` / `cover` / `gallery` collections; the HTTP layer only
 * resolves + authorizes the Hotel and hands validated input here.
 *
 * `logo` and `cover` are single: uploading replaces (and deletes) the
 * previous file. `gallery` is an ordered many.
 */
class HotelMediaService
{
    public function __construct(
        private readonly HotelMediaStore $store,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function upload(Hotel $hotel, string $collection, UploadedFile $file, ?User $actor): HotelMedia
    {
        $this->assertCollection($collection);

        return DB::transaction(function () use ($hotel, $collection, $file, $actor): HotelMedia {
            $single = config("hotel_media.collections.{$collection}.multiple") === false;

            $replaced = [];

            if ($single) {
                $existing = $hotel->media()->where('collection', $collection)->get();
                foreach ($existing as $media) {
                    $replaced[] = ['path' => $media->path, 'disk' => $media->disk];
                    $media->delete();
                }
                $sortOrder = 0;
            } else {
                $sortOrder = (int) $hotel->media()->where('collection', $collection)->max('sort_order') + 1;
            }

            $path = $this->store->store($hotel->id, $collection, $file);

            $media = $hotel->media()->create([
                'collection' => $collection,
                'disk' => $this->store->disk(),
                'path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'sort_order' => $sortOrder,
            ]);

            // Remove the replaced files only after the new row is committed.
            foreach ($replaced as $old) {
                $this->store->delete($old['path'], $old['disk']);
            }

            $this->auditLogger->record(
                $actor,
                'hotel.media.uploaded',
                $media,
                after: ['collection' => $collection, 'media_id' => $media->id],
                hotelId: $hotel->id,
            );

            return $media;
        });
    }

    public function delete(Hotel $hotel, HotelMedia $media, ?User $actor): void
    {
        if ($media->hotel_id !== $hotel->id) {
            throw new InvalidArgumentException('The media does not belong to the hotel.');
        }

        DB::transaction(function () use ($hotel, $media, $actor): void {
            $path = $media->path;
            $disk = $media->disk;

            $media->delete();

            $this->store->delete($path, $disk);

            $this->auditLogger->record(
                $actor,
                'hotel.media.deleted',
                $hotel,
                before: ['collection' => $media->collection, 'media_id' => $media->id],
                hotelId: $hotel->id,
            );
        });
    }

    /**
     * Reorder the gallery. `$orderedIds` must be exactly the hotel's current
     * gallery media ids, in the desired order.
     *
     * @param  list<int>  $orderedIds
     */
    public function reorderGallery(Hotel $hotel, array $orderedIds, ?User $actor): void
    {
        DB::transaction(function () use ($hotel, $orderedIds, $actor): void {
            $current = $hotel->media()
                ->where('collection', HotelMedia::COLLECTION_GALLERY)
                ->pluck('id')
                ->all();

            sort($current);
            $incoming = $orderedIds;
            sort($incoming);

            if ($current !== $incoming) {
                throw new InvalidArgumentException('The ids must match the hotel gallery exactly.');
            }

            foreach ($orderedIds as $position => $id) {
                HotelMedia::query()->whereKey($id)->update(['sort_order' => $position]);
            }

            $this->auditLogger->record(
                $actor,
                'hotel.media.reordered',
                $hotel,
                after: ['gallery_order' => $orderedIds],
                hotelId: $hotel->id,
            );
        });
    }

    private function assertCollection(string $collection): void
    {
        if (! array_key_exists($collection, (array) config('hotel_media.collections'))) {
            throw new InvalidArgumentException("Unknown hotel media collection [{$collection}].");
        }
    }
}
