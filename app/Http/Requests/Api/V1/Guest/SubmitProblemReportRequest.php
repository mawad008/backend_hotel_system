<?php

namespace App\Http\Requests\Api\V1\Guest;

use App\Domain\Support\Models\ProblemReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST .../problems. The client supplies only category + urgency + an
 * optional note — ownership, the hotel, and the initial status are all
 * resolved server-side (mobile/Design/13 · Report a problem.png).
 */
class SubmitProblemReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'string', Rule::in(ProblemReport::CATEGORIES)],
            'urgency' => ['required', 'string', Rule::in(ProblemReport::URGENCIES)],
            'notes' => [
                'nullable', 'string', 'max:2000',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null && trim($value) === '') {
                        $fail(__('api.validation_failed'));
                    }
                },
            ],
        ];
    }

    public function category(): string
    {
        return (string) $this->validated('category');
    }

    public function urgency(): string
    {
        return (string) $this->validated('urgency');
    }

    public function notes(): ?string
    {
        $notes = $this->validated('notes');

        return $notes === null ? null : trim($notes);
    }
}
