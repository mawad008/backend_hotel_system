<?php

namespace App\Http\Requests\Api\V1\AppContent;

use App\Domain\AppContent\Models\GuestAppContent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /app-content/images. File constraints reuse the
 * hotel-media config (same public marketing-image class).
 */
class UploadGuestAppImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxKb = (int) config('hotel_media.max_file_kb', 5120);
        $mimes = implode(',', (array) config('hotel_media.mimes', ['jpg', 'jpeg', 'png', 'webp']));

        $image = ['required', 'file', 'image', 'mimes:'.$mimes, 'max:'.$maxKb];

        // The app icon is a square launcher-style icon (favicon / home screen).
        if ($this->input('slot') === GuestAppContent::IMAGE_APP_ICON) {
            $image[] = 'dimensions:ratio=1,min_width=192';
        }

        return [
            'slot' => ['required', 'string', Rule::in(GuestAppContent::IMAGE_SLOTS)],
            'image' => $image,
        ];
    }

    public function slot(): string
    {
        return (string) $this->validated('slot');
    }
}
