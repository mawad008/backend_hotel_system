<?php

namespace App\Http\Requests\Api\V1\ServiceOrder;

use App\Domain\StayServices\Models\ServiceOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 8 — validation for
 * POST /api/v1/reservations/{reservation}/service-orders/{serviceOrder}/transition.
 *
 * Mirrors TransitionReservationRequest: the client supplies only
 * `target_status` (and an optional free-text `reason` used when cancelling).
 * Structural transition validity stays in ServiceOrderStateMachine.
 */
class TransitionServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_status' => [
                'required', 'string',
                Rule::in([
                    ServiceOrder::STATUS_CONFIRMED,
                    ServiceOrder::STATUS_FULFILLED,
                    ServiceOrder::STATUS_CANCELLED,
                ]),
            ],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
