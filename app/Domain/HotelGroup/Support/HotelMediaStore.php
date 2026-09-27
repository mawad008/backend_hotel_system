<?php

namespace App\Domain\HotelGroup\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The single place hotel images are written to / removed from storage
 * (mirrors IdentityFileStore for identity documents, but on the PUBLIC
 * disk — hotel images are marketing content served by URL).
 *
 * Only the disk-relative path is returned to the domain; the URL is built
 * on read from the recorded disk (HotelMedia::url()).
 */
final class HotelMediaStore
{
    public function disk(): string
    {
        return (string) config('hotel_media.disk', 'public');
    }

    /**
     * Persist one uploaded image under the hotel + collection prefix and
     * return its disk-relative path. The stored filename is random with an
     * extension derived from the file's guessed type — never the
     * client-supplied name.
     */
    public function store(int $hotelId, string $collection, UploadedFile $file): string
    {
        $extension = strtolower($file->extension() ?: 'jpg');
        $name = Str::random(40).'.'.$extension;

        $path = $file->storeAs("hotels/{$hotelId}/{$collection}", $name, ['disk' => $this->disk()]);

        if ($path === false) {
            throw new RuntimeException('Failed to store the hotel media file.');
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
}
