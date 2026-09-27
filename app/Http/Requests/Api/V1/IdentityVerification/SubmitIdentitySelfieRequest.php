<?php

namespace App\Http\Requests\Api\V1\IdentityVerification;

use App\Domain\IdentityVerification\Provider\SimulationDirective;
use App\Rules\IdentityUploadContent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 6 — validation for
 * POST /api/v1/identity-verification/{reservation}/selfie.
 *
 * Carries the live selfie image only. The idempotency key is the
 * `Idempotency-Key` HTTP header (the canonical mechanism), never a body
 * field. The simulation directive is a controlled `X-Identity-Simulate`
 * header honoured ONLY in local/testing — in every other environment it is
 * not even parsed.
 */
class SubmitIdentitySelfieRequest extends FormRequest
{
    private const SIMULATE_HEADER = 'X-Identity-Simulate';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $key = $this->header('Idempotency-Key');

        if ($key !== null) {
            $this->merge(['idempotency_key' => $key]);
        }

        if ($this->simulationExposed() && $this->hasHeader(self::SIMULATE_HEADER)) {
            $this->merge(['simulate' => $this->header(self::SIMULATE_HEADER)]);
        }
    }

    public function rules(): array
    {
        $maxKb = (int) config('verification.storage.max_file_kb', 8192);

        return [
            'selfie' => ['required', 'file', 'mimes:jpg,jpeg,png', "max:{$maxKb}", new IdentityUploadContent(['jpeg', 'png'])],

            'idempotency_key' => ['sometimes', 'filled', 'string', 'max:255', 'regex:/^[A-Za-z0-9._:\-]+$/'],

            'simulate' => ['sometimes', 'string', Rule::in(array_map(
                static fn (SimulationDirective $d) => $d->value,
                SimulationDirective::cases(),
            ))],
        ];
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->validated('idempotency_key');

        return is_string($key) && $key !== '' ? $key : null;
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
