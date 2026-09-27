<?php

namespace App\Http\Requests\Api\V1\ServiceReview;

use App\Domain\StayServices\Models\ServiceReview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /service-reviews/{serviceReview}/moderate. Staff-only decision —
 * mirrors ModerateReviewRequest exactly.
 */
class ModerateServiceReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in([ServiceReview::STATUS_PUBLISHED, ServiceReview::STATUS_REJECTED])],
        ];
    }

    public function decision(): string
    {
        return (string) $this->validated('decision');
    }
}
