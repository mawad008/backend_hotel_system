<?php

namespace App\Http\Requests\Api\V1\Hotel;

use App\Domain\HotelGroup\Models\Hotel;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /hotels/{hotel}/media. Identity (user/hotel scope) is
 * never read from the body — the hotel comes from the route, the actor from
 * the token. File constraints are config-driven (config/hotel_media.php).
 */
class StoreHotelMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $hotel = $this->route('hotel');

        return $hotel instanceof Hotel
            && ($this->user()?->can('manageMedia', $hotel) ?? false);
    }

    public function rules(): array
    {
        $collections = array_keys((array) config('hotel_media.collections'));
        $maxKb = (int) config('hotel_media.max_file_kb', 5120);
        $mimes = implode(',', (array) config('hotel_media.mimes', ['jpg', 'jpeg', 'png', 'webp']));

        return [
            'collection' => ['required', 'string', Rule::in($collections)],
            'image' => ['required', 'file', 'image', 'mimes:'.$mimes, 'max:'.$maxKb],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $collection = $this->input('collection');
            $hotel = $this->route('hotel');

            if (! $hotel instanceof Hotel || $collection === null) {
                return;
            }

            $config = config("hotel_media.collections.{$collection}");
            $max = $config['max'] ?? null;

            if (($config['multiple'] ?? false) && $max !== null) {
                $current = $hotel->media()->where('collection', $collection)->count();

                if ($current >= $max) {
                    $validator->errors()->add('image', __('api.hotel_media.gallery_full', ['max' => $max]));
                }
            }
        });
    }

    public function collection(): string
    {
        return (string) $this->validated('collection');
    }
}
