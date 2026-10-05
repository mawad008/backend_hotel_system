<?php

namespace App\Http\Requests\Api\V1\User;

use App\Domain\IdentityAccess\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:filter', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            // The internal `guest` role is not assignable to dashboard users.
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->whereNot('slug', Role::GUEST)],
            'is_active' => ['sometimes', 'boolean'],
            'hotel_ids' => ['sometimes', 'array'],
            'hotel_ids.*' => ['integer', 'exists:hotels,id'],
        ];
    }
}
