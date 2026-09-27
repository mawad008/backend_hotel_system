<?php

namespace App\Http\Requests\Api\V1\Payment;

use App\Domain\Payment\Gateway\SimulationDirective;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 5D — validation for POST /reservations/{reservation}/payment/hold.
 *
 * The request carries only `amount` and an optional `currency`. Identity
 * (user/hotel/role) is never read from the body. The idempotency key is an
 * HTTP header (`Idempotency-Key`) — the canonical mechanism — not a body
 * field. Amount/currency are validated to a safe shape here; the final
 * business authority stays in PaymentWorkflowService (Phase 5C).
 *
 * No currency default and no deposit calculation are invented: currency is
 * optional and, when omitted, null is passed to the workflow (which falls
 * back to config, itself possibly null).
 */
class StorePaymentHoldRequest extends FormRequest
{
    /**
     * Header carrying a controlled simulation directive. Honoured ONLY in
     * local/testing; in every other environment it is not even parsed, so
     * an API client can never steer provider behaviour in production
     * (Phase 5D §21).
     */
    private const SIMULATE_HEADER = 'X-Payment-Simulate';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // The canonical idempotency mechanism is the header. A body value is
        // never consulted; an empty header is surfaced so the rules reject it.
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
            // Money as a decimal string (matches the documented request
            // body). A positive value that fits DECIMAL(12,2) without
            // silent rounding — anything else is a 422 here rather than a
            // downstream exception.
            'amount' => ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/', 'gt:0'],

            // Currency stays an unresolved business decision — optional,
            // never defaulted. A 3-letter ISO-style code when supplied;
            // PaymentWorkflowService normalizes case.
            'currency' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],

            // `sometimes` + `filled`: when the header is present it is merged
            // in and must be a valid non-empty token — an empty
            // `Idempotency-Key:` header is a 422, not a silent "no key".
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
