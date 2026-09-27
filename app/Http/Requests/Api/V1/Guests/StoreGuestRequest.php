<?php

namespace App\Http\Requests\Api\V1\Guests;

use App\Domain\GuestAccess\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff registers a walk-in guest — front desk, no OTP (the phone is not
 * verified until its owner completes that themselves, same as the guest
 * app's own flow). `phone` is the identity key: normalized the same way
 * RequestOtpRequest/VerifyOtpRequest do, so a later app login with the same
 * number resolves to this exact row rather than creating a duplicate.
 */
class StoreGuestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge(['phone' => PhoneNumber::normalize((string) $this->input('phone'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:'.PhoneNumber::PATTERN, 'unique:guests,phone'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }

    /**
     * @return array{name: string|null, phone: string, email: string|null}
     */
    public function guestData(): array
    {
        return [
            'name' => $this->validated('name'),
            'phone' => $this->validated('phone'),
            'email' => $this->validated('email'),
        ];
    }
}
