<?php

namespace App\Http\Requests\Api\V1\Reservation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff-side validation for POST /api/v1/reservations/{reservation}/extend —
 * the dashboard equivalent of ExtendGuestReservationRequest, same rules and
 * the same `Idempotency-Key` header convention.
 */
class ExtendReservationRequest extends FormRequest
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
