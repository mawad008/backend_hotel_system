<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Checkout\Models\Invoice;
use App\Domain\Checkout\Services\InvoiceService;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Reservation\Services\ReservationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Invoice\IndexInvoiceRequest;
use App\Http\Resources\V1\InvoiceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 9E — read a reservation's final invoice (Phase 0 §16).
 *
 * Separate from the checkout response because the invoice is retrieved
 * long after checkout (a guest re-opening their e-invoice, staff review) and
 * carries the full line-item detail the checkout summary omits.
 *
 * {reservation} is an int id resolved through ReservationService — a
 * cross-hotel or missing id is an identical plain 404.
 */
class InvoiceController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly InvoiceService $invoices,
    ) {}

    /**
     * GET /api/v1/reservations/{reservation}/invoice
     */
    public function show(Request $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findAccessibleBy($request->user(), $reservation);

        if (! $found) {
            abort(404);
        }

        $this->authorize('view', [Invoice::class, $found]);

        $invoice = $this->invoices->findForReservation($found);

        if ($invoice === null) {
            abort(404);
        }

        return $this->success(new InvoiceResource($invoice), __('api.checkout.invoice'));
    }

    /**
     * GET /api/v1/hotels/{hotel}/invoices
     *
     * The staff invoices ledger for one hotel.
     */
    public function index(IndexInvoiceRequest $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('viewLedger', [Invoice::class, $hotel]);

        $invoices = $this->invoices->listForHotel($hotel->id, $request->filters(), $request->perPage());

        return $this->success(InvoiceResource::collection($invoices), __('api.checkout.invoices_ledger'));
    }
}
