<?php

namespace App\Domain\Inventory\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The single place Room/RoomType images are written to / removed from
 * storage (mirrors HotelMediaStore) — on the PUBLIC disk, since these are
 * marketing images served by URL.
 *
 * Only the disk-relative path is returned to the domain; the URL is built
 * on read from the recorded disk (RoomMedia::url()). The storage folder is
 * derived from the mediable's own class (`rooms/{id}/...` or
 * `room-types/{id}/...`) so one store serves both without a type flag.
 */
final class RoomMediaStore
{
    public function disk(): string
    {
        return (string) config('room_media.disk', 'public');
    }

    /**
     * Persist one uploaded image under the mediable's folder + collection
     * prefix and return its disk-relative path. The stored filename is
     * random with an extension derived from the file's guessed type —
     * never the client-supplied name.
     */
    public function store(Model $mediable, string $collection, UploadedFile $file): string
    {
        $extension = strtolower($file->extension() ?: 'jpg');
        $name = Str::random(40).'.'.$extension;

        $path = $file->storeAs(
            $this->folder($mediable)."/{$collection}",
            $name,
            ['disk' => $this->disk()],
        );

        if ($path === false) {
            throw new RuntimeException('Failed to store the room media file.');
        }

        return $path;
    }

    public function delete(?string $path, ?string $disk = null): void
    {
        if ($path === null || $path === '') {
            return;
        }

        Storage::disk($disk ?? $this->disk())->delete($path);
    }

    private function folder(Model $mediable): string
    {
        $kind = Str::plural(Str::kebab(class_basename($mediable)));

        return "{$kind}/{$mediable->getKey()}";
    }
}
