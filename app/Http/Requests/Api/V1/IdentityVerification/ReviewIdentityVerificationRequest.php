<?php

namespace App\Http\Requests\Api\V1\IdentityVerification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 6 — validation for
 * POST /api/v1/identity-verification/{reservation}/review.
 *
 * A staff manual-review decision on a PENDING_MANUAL_REVIEW session. The
 * acting user is taken from the token, never the body.
 */
class ReviewIdentityVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['approve', 'reject'])],

            // Optional short staff note. Not a place for PII by policy.
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function reason(): ?string
    {
        $value = $this->validated('reason');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
