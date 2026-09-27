<?php

namespace App\Http\Controllers\Api\V1\Guest;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Loyalty\Services\LoyaltyService;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Services\ReservationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Loyalty\RedeemLoyaltyRequest;
use App\Http\Resources\V1\LoyaltyAccountResource;
use App\Http\Resources\V1\LoyaltyTransactionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The authenticated guest's own loyalty account, reached through one of
 * their reservations so hotel scope + guest identity are resolved
 * server-side (`/api/v1/guest/reservations/{reservation}/loyalty*`).
 *
 * Dedicated GUEST controller — reuses LoyaltyService unchanged (same as
 * LoyaltyController). The ledger is authoritative: the client supplies only
 * how many points to redeem; the balance, the point value and the resulting
 * ledger delta are all derived server-side.
 *
 * No guest-triggerable `earn`: the staff `earn` endpoint is a manual-trigger
 * escape hatch gated behind the staff `manage` ability, not a guest action —
 * accrual for a completed stay happens through the approved reservation
 * lifecycle, not an HTTP call the guest makes. Exposing it to the guest
 * would let them decide when/whether to earn, which is not an approved
 * guest-facing rule.
 */
class GuestLoyaltyController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly LoyaltyService $loyalty,
    ) {}

    /**
     * GET /guest/hotels/{hotel}/loyalty — the program for the hotel's group
     * plus the guest's balance (booking summary, before a reservation
     * exists). Loyalty disabled = `enabled: false`, all zero.
     */
    public function program(Request $request, int $hotel): JsonResponse
    {
        $found = Hotel::query()->whereKey($hotel)->where('is_active', true)->first();

        if (! $found) {
            abort(404);
        }

        $program = $this->loyalty->programForHotelGroup($found->hotel_group_id);
        $balance = $this->loyalty->accountFor($request->user())->points_balance;

        return $this->success($program + [
            'points_balance' => (int) $balance,
            'redeemable_points' => $program['enabled'] ? min((int) $balance, $program['max_redeem_points']) : 0,
        ], __('api.loyalty.account'));
    }

    public function show(Request $request, int $reservation): JsonResponse
    {
        $found = $this->reservationFor($request, $reservation);

        $account = $this->loyalty->accountFor($found->guest);

        return $this->success(new LoyaltyAccountResource($account), __('api.loyalty.account'));
    }

    public function transactions(Request $request, int $reservation): JsonResponse
    {
        $found = $this->reservationFor($request, $reservation);

        return $this->success(
            LoyaltyTransactionResource::collection($this->loyalty->ledgerFor($found->guest)),
            __('api.loyalty.transactions'),
        );
    }

    public function redeem(RedeemLoyaltyRequest $request, int $reservation): JsonResponse
    {
        $found = $this->reservationFor($request, $reservation);

        $transaction = $this->loyalty->redeemForReservation($found, $request->points(), null);

        return $this->success(new LoyaltyTransactionResource($transaction), __('api.loyalty.redeemed'), 201);
    }

    private function reservationFor(Request $request, int $reservation): Reservation
    {
        $found = $this->reservations->findOwnedByGuest($this->guest($request), $reservation);

        if (! $found) {
            abort(404);
        }

        return $found;
    }

    private function guest(Request $request): Guest
    {
        /** @var Guest $guest */
        $guest = $request->user();

        return $guest;
    }
}
