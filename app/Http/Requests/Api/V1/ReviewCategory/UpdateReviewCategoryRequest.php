<?php

namespace App\Http\Requests\Api\V1\ReviewCategory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReviewCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $hotel = $this->route('hotel');
        $category = $this->route('reviewCategory');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255',
                Rule::unique('review_categories', 'name')->where('hotel_id', $hotel->id)->ignore($category->id)],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:64'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
