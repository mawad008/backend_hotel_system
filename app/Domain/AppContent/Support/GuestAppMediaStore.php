<?php

namespace App\Domain\AppContent\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The single place Guest App branding images are written to / removed from
 * storage. Public marketing content, so it shares the hotel-media disk
 * (config/hotel_media.php `disk`). Only the disk-relative path is returned;
 * the URL is built on read (GuestAppContent::imageUrl()).
 */
final class GuestAppMediaStore
{
    public function disk(): string
    {
        return (string) config('hotel_media.disk', 'public');
    }

    /**
     * Stored under a random name with an extension derived from the file's
     * guessed type — never the client-supplied name.
     */
    public function store(string $slot, UploadedFile $file): string
    {
        $extension = strtolower($file->extension() ?: 'jpg');
        $name = Str::random(40).'.'.$extension;

        $path = $file->storeAs("guest-app/{$slot}", $name, ['disk' => $this->disk()]);

        if ($path === false) {
            throw new RuntimeException('Failed to store the guest app image.');
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
