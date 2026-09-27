<?php

namespace App\Http\Requests\Api\V1\Reservation;

use App\Domain\Reservation\StateMachine\ReservationStateMachine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the requested target status is accepted — hotel_id, user_id,
     * guest_id, current_status, price, created_by_staff_id and the
     * cancellation fields are all deliberately absent and ignored.
     *
     * `Rule::in` is fed straight from ReservationStateMachine::statuses()
     * so the valid-status set is never restated here — the state machine
     * stays authoritative. This rule only proves the status *exists*;
     * whether the transition from the reservation's current status is
     * *allowed* is decided by ReservationStateMachine::assertCanTransition()
     * inside ReservationService::transitionTo(), and surfaces as the
     * standard 422 business error (InvalidReservationStatusTransitionException).
     */
    public function rules(): array
    {
        return [
            'target_status' => ['required', 'string', Rule::in(ReservationStateMachine::statuses())],
        ];
    }
}
