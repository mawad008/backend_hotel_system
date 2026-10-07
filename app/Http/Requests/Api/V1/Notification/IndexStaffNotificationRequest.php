<?php

namespace App\Http\Requests\Api\V1\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query allow-list for GET /me/notifications.
 */
class IndexStaffNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unread' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function unreadOnly(): bool
    {
        return $this->boolean('unread');
    }

    public function perPage(): int
    {
        return (int) $this->query('per_page', 15);
    }
}
