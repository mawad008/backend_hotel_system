<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Checkout\Exceptions\InvoiceGenerationException;
use App\Domain\Checkout\Models\Invoice;
use App\Domain\Checkout\Models\InvoiceItem;
use App\Domain\Checkout\Repositories\Contracts\InvoiceRepositoryInterface;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\FolioCharge;
use App\Domain\StayServices\Repositories\Contracts\FolioChargeRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Phase 9E — invoice generation / finalization.
 *
 * One final invoice per reservation (DB-unique `reservation_id`). Totals are
 * copied from the authoritative final folio passed in by CheckoutService —
 * never the client. Items are a frozen snapshot of the reservation's
 * `posted` folio charges (Phase 8), so a later folio-charge change never
 * rewrites invoice history.
 *
 * Every method here MUST be called from within CheckoutService's finalize
 * transaction (the reservation row is already locked). It opens no
 * transaction of its own and calls no external provider.
 *
 * No tax / discount / fee logic — none are approved (Phase 9 scope).
 */
class InvoiceService
{
    public function __construct(
        private readonly InvoiceRepositoryInterface $invoices,
        private readonly FolioChargeRepositoryInterface $folioCharges,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * The reservation's final invoice, or null.
     */
    public function findForReservation(Reservation $reservation): ?Invoice
    {
        return $this->invoices->findByReservation($reservation->id);
    }

    /**
     * The staff invoices ledger for a hotel — a pure read.
     *
     * @param  array{status?: string|null}  $filters
     */
    public function listForHotel(int $hotelId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->invoices->paginateForHotel($hotelId, $filters, $perPage);
    }

    /**
     * Create (once) and issue the reservation's final invoice. Idempotent:
     * an already-issued invoice is returned untouched; a leftover draft is
     * completed and issued.
     *
     * @param  array{charges_total: string, payments_total: string, outstanding_total: string}  $totals
     */
    public function finalizeFor(Reservation $reservation, array $totals, ?string $currency, ?User $actor): Invoice
    {
        $invoice = $this->invoices->findByReservationForUpdate($reservation->id);

        if ($invoice === null) {
            $invoice = $this->createDraft($reservation, $actor);

            $this->auditLogger->record(
                $actor, 'invoice.created', $invoice,
                after: $this->auditSnapshot($invoice), hotelId: $invoice->hotel_id,
            );
        }

        if ($invoice->status === Invoice::STATUS_ISSUED) {
            return $this->invoices->find($invoice->id);
        }

        $this->syncItems($invoice, $reservation);
        $this->assertItemsMatchSubtotal($invoice->load('items'), $totals['charges_total']);

        $invoice = $this->invoices->update($invoice, [
            'invoice_number' => $invoice->invoice_number ?? $this->numberFor($invoice),
            'status' => Invoice::STATUS_ISSUED,
            'currency' => $currency,
            'subtotal' => $totals['charges_total'],
            'payments_total' => $totals['payments_total'],
            'outstanding_total' => $totals['outstanding_total'],
            'issued_at' => now(),
        ]);

        $this->auditLogger->record(
            $actor, 'invoice.issued', $invoice,
            after: $this->auditSnapshot($invoice), hotelId: $invoice->hotel_id,
        );

        return $invoice;
    }

    private function createDraft(Reservation $reservation, ?User $actor): Invoice
    {
        try {
            return $this->invoices->create([
                'reservation_id' => $reservation->id,
                'hotel_id' => $reservation->hotel_id,
                'invoice_number' => null,
                'status' => Invoice::STATUS_DRAFT,
                'currency' => null,
                'subtotal' => '0.00',
                'payments_total' => '0.00',
                'outstanding_total' => '0.00',
                'created_by_user_id' => $actor?->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent checkout created it first. UNIQUE(reservation_id)
            // is authoritative — re-read under the lock.
            $winner = $this->invoices->findByReservationForUpdate($reservation->id);

            return $winner ?? throw new InvoiceGenerationException('lost_creation_race');
        }
    }

    /**
     * Snapshot every `posted` folio charge as an invoice item. Idempotent
     * via UNIQUE(invoice_id, source_type, source_id).
     */
    private function syncItems(Invoice $invoice, Reservation $reservation): void
    {
        $charges = $this->folioCharges->allForReservation($reservation->id)
            ->where('status', FolioCharge::STATUS_POSTED);

        $existing = $invoice->items()->pluck('source_id')->all();

        foreach ($charges as $charge) {
            if (in_array($charge->id, $existing, true)) {
                continue;
            }

            try {
                $this->invoices->addItem($invoice, [
                    'source_type' => InvoiceItem::SOURCE_FOLIO_CHARGE,
                    'source_id' => $charge->id,
                    'description' => $charge->description,
                    'quantity' => $charge->quantity,
                    'unit_amount' => $charge->unit_amount,
                    'total_amount' => $charge->total_amount,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Concurrent finalize added the same item — safe to ignore.
            }
        }
    }

    private function assertItemsMatchSubtotal(Invoice $invoice, string $chargesTotal): void
    {
        $sum = '0.00';

        foreach ($invoice->items as $item) {
            $sum = bcadd($sum, (string) $item->total_amount, 2);
        }

        if (bccomp($sum, $chargesTotal, 2) !== 0) {
            throw new InvoiceGenerationException('items_total_mismatch');
        }
    }

    private function numberFor(Invoice $invoice): string
    {
        // Technical format — no approved legal numbering scheme exists
        // (Phase 9 report). Derived from the row id, so it is deterministic
        // and unique at the database level.
        return sprintf('INV-%06d', $invoice->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(Invoice $invoice): array
    {
        return array_filter([
            'invoice_number' => $invoice->invoice_number,
            'invoice_status' => $invoice->status,
            'currency' => $invoice->currency,
            'subtotal' => $invoice->subtotal,
            'payments_total' => $invoice->payments_total,
            'outstanding_total' => $invoice->outstanding_total,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
        ], fn ($value) => $value !== null);
    }
}
