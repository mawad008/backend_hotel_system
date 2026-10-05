<?php

namespace App\Http\Requests\Api\V1\User;

use App\Domain\IdentityAccess\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email:filter', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            // The internal `guest` role is not assignable to dashboard users.
            'role_id' => ['sometimes', 'integer', Rule::exists('roles', 'id')->whereNot('slug', Role::GUEST)],
            'is_active' => ['sometimes', 'boolean'],
            'hotel_ids' => ['sometimes', 'array'],
            'hotel_ids.*' => ['integer', 'exists:hotels,id'],
        ];
    }
}
