<?php

namespace App\Http\Requests\Api\V1\Room;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Explicit allow-list of query parameters for GET /hotels/{hotel}/rooms.
 * Anything not listed here is ignored — no arbitrary column filtering.
 * Both filters only narrow the list; the hotel always comes from the
 * authorized route.
 */
class IndexRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:50'],
            'room_type_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function roomTypeId(): ?int
    {
        return $this->filled('room_type_id') ? (int) $this->query('room_type_id') : null;
    }

    public function perPage(): int
    {
        return (int) $this->query('per_page', 15);
    }

    public function search(): ?string
    {
        $search = trim((string) $this->query('search', ''));

        return $search !== '' ? $search : null;
    }
}
