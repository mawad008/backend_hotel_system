<?php

namespace App\Http\Requests\Api\V1\Guest;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/v1/guest/reservations/{reservation}/extend.
 *
 * The client supplies ONLY the desired new checkout date. Whether the
 * reservation is even eligible to extend (`checked_in` / `in_stay`), whether
 * the added nights are available, and the resulting price are all derived
 * server-side by ReservationExtensionService — not duplicated here (mirrors
 * RedeemLoyaltyRequest: this only rejects obviously bad input).
 *
 * The `Idempotency-Key` header is surfaced the same way
 * InitiateGuestPaymentHoldRequest already does.
 */
class ExtendGuestReservationRequest extends FormRequest
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
            'new_check_out' => ['required', 'date', 'after:today'],
            'idempotency_key' => ['sometimes', 'filled', 'string', 'max:255', 'regex:/^[A-Za-z0-9._:\-]+$/'],
        ];
    }

    public function newCheckOut(): string
    {
        return $this->validated('new_check_out');
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->validated('idempotency_key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
