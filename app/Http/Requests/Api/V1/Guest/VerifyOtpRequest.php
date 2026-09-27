<?php

namespace App\Http\Requests\Api\V1\Guest;

use App\Domain\GuestAccess\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
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
        $length = (int) config('otp.code_length');

        return [
            'challenge_id' => ['required', 'string', 'uuid'],
            'phone' => ['required', 'string', 'regex:'.PhoneNumber::PATTERN],
            'code' => ['required', 'string', 'digits:'.$length],
        ];
    }
}
