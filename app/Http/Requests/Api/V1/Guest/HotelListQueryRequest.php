<?php

namespace App\Http\Requests\Api\V1\Guest;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /guest/hotels` query contract (Slice 1 discovery + the Home/Search
 * sort & filter pass). `sort` mirrors the three unlocked options in
 * `15 · Search, filters & sort` — "nearest" is shown locked there (needs
 * guest geolocation, not built) and is deliberately not a value here.
 */
class HotelListQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'city' => ['sometimes', 'string'],
            'q' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'sort' => ['sometimes', 'in:recommended,highest_rated,cheapest'],
            'min_price' => ['sometimes', 'numeric', 'min:0'],
            'max_price' => ['sometimes', 'numeric', 'min:0'],
            'facilities' => ['sometimes', 'string'],
        ];
    }

    public function sort(): string
    {
        return $this->validated('sort') ?? 'recommended';
    }

    public function minPrice(): ?float
    {
        $value = $this->validated('min_price');

        return $value === null ? null : (float) $value;
    }

    public function maxPrice(): ?float
    {
        $value = $this->validated('max_price');

        return $value === null ? null : (float) $value;
    }

    /**
     * @return array<int, string>
     */
    public function facilities(): array
    {
        $raw = $this->validated('facilities');

        if ($raw === null || $raw === '') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn (string $key) => trim($key))
            ->filter(fn (string $key) => $key !== '')
            ->values()
            ->all();
    }
}
