<?php

namespace App\Http\Requests\Api\V1\DigitalAccess;

use App\Domain\DigitalAccess\Provider\SimulationDirective;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 7 — validation for POST /api/v1/access/{reservation}/revoke.
 *
 * Body: an optional short `reason`. The acting user is taken from the token.
 * `X-Digital-Access-Simulate` steers the dummy provider ONLY in local/testing.
 */
class RevokeAccessRequest extends FormRequest
{
    private const SIMULATE_HEADER = 'X-Digital-Access-Simulate';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->simulationExposed() && $this->hasHeader(self::SIMULATE_HEADER)) {
            $this->merge(['simulate' => $this->header(self::SIMULATE_HEADER)]);
        }
    }

    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],

            'simulate' => ['sometimes', 'string', Rule::in(array_map(
                static fn (SimulationDirective $d) => $d->value,
                SimulationDirective::cases(),
            ))],
        ];
    }

    public function reason(): ?string
    {
        $value = $this->validated('reason');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function simulationDirective(): ?SimulationDirective
    {
        if (! $this->simulationExposed()) {
            return null;
        }

        $value = $this->validated('simulate');

        return is_string($value) ? SimulationDirective::tryFrom($value) : null;
    }

    private function simulationExposed(): bool
    {
        return app()->environment('local', 'testing');
    }
}
