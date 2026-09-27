<?php

namespace App\Http\Requests\Api\V1\Review;

use App\Domain\Review\Models\Review;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /reviews/{review}/moderate. Staff-only decision — `published` makes
 * the review visible on the public hotel listing, `rejected` keeps it
 * hidden. No further states are approved (no "needs edit", no re-review).
 */
class ModerateReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in([Review::STATUS_PUBLISHED, Review::STATUS_REJECTED])],
        ];
    }

    public function decision(): string
    {
        return (string) $this->validated('decision');
    }
}
