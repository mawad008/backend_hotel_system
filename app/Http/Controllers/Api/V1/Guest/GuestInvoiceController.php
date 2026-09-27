<?php

namespace App\Http\Controllers\Api\V1\Guest;

use App\Domain\Checkout\Services\InvoiceService;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Services\ReservationService;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\InvoiceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The authenticated guest's own reservation's final invoice
 * (`/api/v1/guest/reservations/{reservation}/invoice`). Read-only, reuses
 * InvoiceService unchanged.
 */
class GuestInvoiceController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly InvoiceService $invoices,
    ) {}

    public function show(Request $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findOwnedByGuest($this->guest($request), $reservation);

        if (! $found) {
            abort(404);
        }

        $invoice = $this->invoices->findForReservation($found);

        if ($invoice === null) {
            abort(404);
        }

        return $this->success(new InvoiceResource($invoice), __('api.checkout.invoice'));
    }

    private function guest(Request $request): Guest
    {
        /** @var Guest $guest */
        $guest = $request->user();

        return $guest;
    }
}
