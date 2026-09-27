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

        return [
            'slot' => ['required', 'string', Rule::in(GuestAppContent::IMAGE_SLOTS)],
            'image' => ['required', 'file', 'image', 'mimes:'.$mimes, 'max:'.$maxKb],
        ];
    }

    public function slot(): string
    {
        return (string) $this->validated('slot');
    }
}
