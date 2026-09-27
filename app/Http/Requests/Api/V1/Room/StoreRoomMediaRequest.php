<?php

namespace App\Http\Requests\Api\V1\Room;

use App\Domain\Inventory\Models\Room;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /hotels/{hotel}/rooms/{room}/media. Identity (room /
 * hotel scope) is never read from the body — the room comes from the
 * route, the actor from the token. File constraints are config-driven
 * (config/room_media.php).
 */
class StoreRoomMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $room = $this->route('room');

        return $room instanceof Room
            && ($this->user()?->can('manageMedia', $room) ?? false);
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
            $room = $this->route('room');

            if (! $room instanceof Room || $collection === null) {
                return;
            }

            $config = config("room_media.collections.{$collection}");
            $max = $config['max'] ?? null;

            if (($config['multiple'] ?? false) && $max !== null) {
                $current = $room->media()->where('collection', $collection)->count();

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
