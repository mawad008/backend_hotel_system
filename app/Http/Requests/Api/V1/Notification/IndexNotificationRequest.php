<?php

namespace App\Http\Requests\Api\V1\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Explicit allow-list of query parameters for the staff-wide notification
 * feed GET /hotels/{hotel}/notifications.
 */
class IndexNotificationRequest extends FormRequest
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
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{unread_only: bool}
     */
    public function filters(): array
    {
        return [
            'unread_only' => $this->boolean('unread'),
        ];
    }

    public function perPage(): int
    {
        return (int) $this->query('per_page', 20);
    }
}
