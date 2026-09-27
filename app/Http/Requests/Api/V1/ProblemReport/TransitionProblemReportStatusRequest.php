<?php

namespace App\Http\Requests\Api\V1\ProblemReport;

use App\Domain\Support\Models\ProblemReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /problems/{problem}/status. Staff-only triage decision — the actual
 * forward-only transition rules live in ProblemReportService, this only
 * restricts the request to a known target status.
 */
class TransitionProblemReportStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(ProblemReport::STATUSES)],
        ];
    }

    public function status(): string
    {
        return (string) $this->validated('status');
    }
}
