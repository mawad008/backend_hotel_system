<?php

namespace App\Http\Requests\Api\V1\Guest;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Structure for POST /api/v1/guest/reservations/{reservation}/payment/hold.
 *
 * The guest deliberately supplies NO amount: a guest must not be able to
 * name their own deposit, and there is no approved deposit-amount rule
 * (config/guest_booking.php → deposit.rule is null). Until a rule exists the
 * controller refuses this endpoint with a machine-readable reason. The
 * canonical idempotency mechanism (the `Idempotency-Key` header, already
 * honoured by PaymentWorkflowService) is surfaced here so wiring it is a
 * one-line change once the deposit rule is approved.
 */
class InitiateGuestPaymentHoldRequest extends FormRequest
{
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
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['sometimes', 'filled', 'string', 'max:255', 'regex:/^[A-Za-z0-9._:\-]+$/'],
        ];
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->validated('idempotency_key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
