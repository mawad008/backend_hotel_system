<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomMedia;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Inventory\Support\RoomMediaStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Room / Room Type image management. Owns the create/replace/delete/
 * reorder rules for the `gallery` collection; the HTTP layer only
 * resolves + authorizes the mediable (a Room or a RoomType) and hands
 * validated input here. Mirrors HotelMediaService.
 */
class RoomMediaService
{
    public function __construct(
        private readonly RoomMediaStore $store,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  Room|RoomType  $mediable
     */
    public function upload(Model $mediable, string $collection, UploadedFile $file, ?User $actor): RoomMedia
    {
        $this->assertCollection($collection);

        return DB::transaction(function () use ($mediable, $collection, $file, $actor): RoomMedia {
            $single = config("room_media.collections.{$collection}.multiple") === false;

            $replaced = [];

            if ($single) {
                $existing = $mediable->media()->where('collection', $collection)->get();
                foreach ($existing as $media) {
                    $replaced[] = ['path' => $media->path, 'disk' => $media->disk];
                    $media->delete();
                }
                $sortOrder = 0;
            } else {
                $sortOrder = (int) $mediable->media()->where('collection', $collection)->max('sort_order') + 1;
            }

            $path = $this->store->store($mediable, $collection, $file);

            $media = $mediable->media()->create([
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
                $this->eventName($mediable, 'uploaded'),
                $media,
                after: ['collection' => $collection, 'media_id' => $media->id],
                hotelId: $mediable->hotel_id,
            );

            return $media;
        });
    }

    /**
     * @param  Room|RoomType  $mediable
     */
    public function delete(Model $mediable, RoomMedia $media, ?User $actor): void
    {
        if ($media->mediable_type !== $mediable::class || $media->mediable_id !== $mediable->getKey()) {
            throw new InvalidArgumentException('The media does not belong to this Room/Room Type.');
        }

        DB::transaction(function () use ($mediable, $media, $actor): void {
            $path = $media->path;
            $disk = $media->disk;

            $media->delete();

            $this->store->delete($path, $disk);

            $this->auditLogger->record(
                $actor,
                $this->eventName($mediable, 'deleted'),
                $mediable,
                before: ['collection' => $media->collection, 'media_id' => $media->id],
                hotelId: $mediable->hotel_id,
            );
        });
    }

    /**
     * Reorder the gallery. `$orderedIds` must be exactly the mediable's
     * current gallery media ids, in the desired order.
     *
     * @param  Room|RoomType  $mediable
     * @param  list<int>  $orderedIds
     */
    public function reorderGallery(Model $mediable, array $orderedIds, ?User $actor): void
    {
        DB::transaction(function () use ($mediable, $orderedIds, $actor): void {
            $current = $mediable->media()
                ->where('collection', RoomMedia::COLLECTION_GALLERY)
                ->pluck('id')
                ->all();

            sort($current);
            $incoming = $orderedIds;
            sort($incoming);

            if ($current !== $incoming) {
                throw new InvalidArgumentException('The ids must match the gallery exactly.');
            }

            foreach ($orderedIds as $position => $id) {
                RoomMedia::query()->whereKey($id)->update(['sort_order' => $position]);
            }

            $this->auditLogger->record(
                $actor,
                $this->eventName($mediable, 'reordered'),
                $mediable,
                after: ['gallery_order' => $orderedIds],
                hotelId: $mediable->hotel_id,
            );
        });
    }

    private function eventName(Model $mediable, string $action): string
    {
        $kind = $mediable instanceof RoomType ? 'room-type' : 'room';

        return "{$kind}.media.{$action}";
    }

    private function assertCollection(string $collection): void
    {
        if (! array_key_exists($collection, (array) config('room_media.collections'))) {
            throw new InvalidArgumentException("Unknown room media collection [{$collection}].");
        }
    }
}
