<?php

namespace App\Http\Requests\Api\V1\Report;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Explicit allow-list of query parameters for the staff reports
 * (GET /reports/{occupancy|revenue|hotel-comparison}). `from`/`to` are
 * required — no implicit business default window is ever invented; the
 * dashboard's date picker supplies both. `hotel_id` is optional: omitted
 * means every hotel the caller can access.
 */
class IndexReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'hotel_id' => ['sometimes', 'nullable', 'integer', Rule::exists('hotels', 'id')],
        ];
    }

    public function from(): string
    {
        return $this->validated('from');
    }

    public function to(): string
    {
        return $this->validated('to');
    }

    public function hotelId(): ?int
    {
        $id = $this->validated('hotel_id');

        return $id !== null ? (int) $id : null;
    }
}
