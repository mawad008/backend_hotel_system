<?php

namespace App\Http\Requests\Api\V1\Checkout;

use App\Domain\Payment\Gateway\SimulationDirective;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 9D — validation for POST /api/v1/reservations/{reservation}/checkout.
 *
 * The request carries NO body fields of its own. The settlement amount is
 * ALWAYS computed server-side from the authoritative folio — an `amount` in
 * the body is neither read nor honoured. Identity (user / hotel / role) is
 * never read from the request.
 *
 * The idempotency key is the `Idempotency-Key` HTTP header (the canonical
 * mechanism, shared with the final-settlement transaction). The simulation
 * directive is the controlled `X-Payment-Simulate` header — honoured ONLY in
 * local/testing, never parsed elsewhere.
 */
class PerformCheckoutRequest extends FormRequest
{
    private const SIMULATE_HEADER = 'X-Payment-Simulate';

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
        return [
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
