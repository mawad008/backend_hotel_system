<?php

namespace App\Http\Requests\Api\V1\ReviewCategory;

use Illuminate\Foundation\Http\FormRequest;

class ReorderReviewCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ];
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_map('intval', $this->validated('ids'));
    }
}
