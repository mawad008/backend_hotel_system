<?php

namespace App\Http\Requests\Api\V1\RoomType;

use App\Domain\Inventory\Models\RoomType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /hotels/{hotel}/room-types/{roomType}/media.
 * Identity (room type / hotel scope) is never read from the body — the
 * room type comes from the route, the actor from the token. File
 * constraints are config-driven (config/room_media.php).
 */
class StoreRoomTypeMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $roomType = $this->route('roomType');

        return $roomType instanceof RoomType
            && ($this->user()?->can('manageMedia', $roomType) ?? false);
    }

    public function rules(): array
    {
        $collections = array_keys((array) config('room_media.collections'));
        $maxKb = (int) config('room_media.max_file_kb', 5120);
        $mimes = implode(',', (array) config('room_media.mimes', ['jpg', 'jpeg', 'png', 'webp']));

        return [
            'collection' => ['required', 'string', Rule::in($collections)],
            'image' => ['required', 'file', 'image', 'mimes:'.$mimes, 'max:'.$maxKb],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $collection = $this->input('collection');
            $roomType = $this->route('roomType');

            if (! $roomType instanceof RoomType || $collection === null) {
                return;
            }

            $config = config("room_media.collections.{$collection}");
            $max = $config['max'] ?? null;

            if (($config['multiple'] ?? false) && $max !== null) {
                $current = $roomType->media()->where('collection', $collection)->count();

                if ($current >= $max) {
                    $validator->errors()->add('image', __('api.room_media.gallery_full', ['max' => $max]));
                }
            }
        });
    }

    public function collection(): string
    {
        return (string) $this->validated('collection');
    }
}
