<?php

namespace App\Http\Requests\Api\V1\ServiceOrder;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 8 — validation for POST /api/v1/reservations/{reservation}/service-orders.
 *
 * The client supplies ONLY the service and how many. hotel_id, guest_id,
 * unit_price, total_amount, status, and any user/role field are never read
 * from the request — the server derives every one of them
 * (ServiceOrderService). `quantity` is capped at 1,000 so price x quantity
 * always fits the money column (a technical boundary, not a business rule).
 */
class StoreServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
