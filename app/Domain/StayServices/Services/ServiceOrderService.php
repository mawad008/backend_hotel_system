<?php

namespace App\Domain\StayServices\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Repositories\Contracts\ReservationRepositoryInterface;
use App\Domain\StayServices\Exceptions\FolioChargeAmountException;
use App\Domain\StayServices\Exceptions\InvalidServiceOrderStatusTransitionException;
use App\Domain\StayServices\Exceptions\ServiceOrderNotAllowedException;
use App\Domain\StayServices\Models\FolioCharge;
use App\Domain\StayServices\Models\HotelService;
use App\Domain\StayServices\Models\ServiceOrder;
use App\Domain\StayServices\Repositories\Contracts\FolioChargeRepositoryInterface;
use App\Domain\StayServices\Repositories\Contracts\HotelServiceRepositoryInterface;
use App\Domain\StayServices\Repositories\Contracts\ServiceOrderRepositoryInterface;
use App\Domain\StayServices\StateMachine\ServiceOrderStateMachine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8C/8D — the service-order workflow: requesting a service for a
 * reservation, driving it through the ServiceOrderStateMachine, and the
 * folio charge it produces.
 *
 * ── Server-derived values (Phase 8C) ──
 * hotel_id, the unit price snapshot, the currency snapshot and the line
 * total are ALWAYS computed here from the resolved reservation + service.
 * Nothing financial or authority-bearing is ever read from the client.
 *
 * ── Lock order ──
 *   Reservation -> ServiceOrder -> FolioCharge
 * in every transactional method.
 *
 * ── Folio charge lifecycle (technical decision — no explicit rule in the
 * baseline) ──
 * A charge is created when an order enters `confirmed`. Cancelling a
 * `confirmed` order voids (never negates) its charge. `requested` and
 * `fulfilled` have no financial effect. Charge creation is idempotent via
 * the `folio_charges (source_type, source_id)` UNIQUE constraint plus a
 * pre-check, so a retried transition never double-charges.
 */
class ServiceOrderService
{
    /**
     * Reservation statuses during which a guest may consume services
     * (Phase 0 R20: "Digital Check-in -> Stay/Services"). The exact window
     * is not spelled out in the approved baseline — this is the
     * conservative reading and is flagged as a deferred clarification.
     *
     * @var list<string>
     */
    public const SERVICEABLE_RESERVATION_STATUSES = [
        Reservation::STATUS_CHECKED_IN,
        Reservation::STATUS_IN_STAY,
    ];

    public function __construct(
        private readonly ServiceOrderRepositoryInterface $orders,
        private readonly HotelServiceRepositoryInterface $services,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly FolioChargeRepositoryInterface $charges,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return LengthAwarePaginator<ServiceOrder>
     */
    public function listForReservation(Reservation $reservation, int $perPage = 15): LengthAwarePaginator
    {
        return $this->orders->paginateForReservation($reservation->id, $perPage);
    }

    /**
     * A single order that belongs to $reservation, or null. Never leaks an
     * order from another reservation/hotel.
     */
    public function findForReservation(Reservation $reservation, int $orderId): ?ServiceOrder
    {
        $order = $this->orders->find($orderId);

        return $order !== null && $order->reservation_id === $reservation->id ? $order : null;
    }

    /**
     * Request a service for a reservation.
     *
     * @param  array<string, mixed>  $data  validated input — only `service_id`, `quantity`, `notes` are read
     *
     * @throws ModelNotFoundException if the reservation or service does not exist
     * @throws ServiceOrderNotAllowedException on a business precondition failure
     * @throws FolioChargeAmountException if the computed line total is out of range
     */
    public function create(Reservation $reservation, array $data, ?User $actor): ServiceOrder
    {
        return DB::transaction(function () use ($reservation, $data, $actor) {
            $locked = $this->reservations->findForUpdate($reservation->id);

            if ($locked === null) {
                throw (new ModelNotFoundException)->setModel(Reservation::class, [$reservation->id]);
            }

            if (! in_array($locked->status, self::SERVICEABLE_RESERVATION_STATUSES, true)) {
                throw ServiceOrderNotAllowedException::reservationNotServiceable($locked->status);
            }

            $service = $this->services->findForUpdate((int) ($data['service_id'] ?? 0));

            if ($service === null) {
                throw (new ModelNotFoundException)->setModel(HotelService::class, [$data['service_id'] ?? null]);
            }

            // Service must belong to the reservation's own hotel — the hotel
            // is derived, never supplied.
            if ($service->hotel_id !== $locked->hotel_id) {
                throw ServiceOrderNotAllowedException::serviceHotelMismatch();
            }

            if (! $service->is_active) {
                throw ServiceOrderNotAllowedException::serviceInactive();
            }

            $quantity = (int) ($data['quantity'] ?? 0);

            if ($quantity < 1) {
                throw ServiceOrderNotAllowedException::invalidQuantity();
            }

            $unitPrice = (string) $service->price;
            $total = $this->lineTotal($unitPrice, $quantity);

            $order = $this->orders->create([
                'reservation_id' => $locked->id,
                'hotel_id' => $locked->hotel_id,
                'service_id' => $service->id,
                'quantity' => $quantity,
                'unit_price_snapshot' => $unitPrice,
                'currency_snapshot' => $service->currency,
                'total_amount' => $total,
                'status' => ServiceOrderStateMachine::INITIAL_STATUS,
                'notes' => $this->cleanNotes($data['notes'] ?? null),
                'requested_by_user_id' => $actor?->id,
                'requested_at' => now(),
            ]);

            $this->auditLogger->record(
                $actor,
                'service_order.created',
                $order,
                after: $this->auditSnapshot($order),
                hotelId: $order->hotel_id,
            );

            return $order;
        });
    }

    /**
     * Drive an order to $targetStatus. Structural validity is decided
     * exclusively by ServiceOrderStateMachine.
     *
     * @throws ModelNotFoundException if the order no longer exists
     * @throws InvalidServiceOrderStatusTransitionException
     */
    public function transition(
        ServiceOrder $order,
        string $targetStatus,
        ?string $reason = null,
        ?User $actor = null,
    ): ServiceOrder {
        return DB::transaction(function () use ($order, $targetStatus, $reason, $actor) {
            $locked = $this->orders->findForUpdate($order->id);

            if ($locked === null) {
                throw (new ModelNotFoundException)->setModel(ServiceOrder::class, [$order->id]);
            }

            ServiceOrderStateMachine::assertCanTransition($locked->status, $targetStatus);

            $before = $this->auditSnapshot($locked);

            $attributes = ['status' => $targetStatus];
            $action = 'service_order.transitioned';

            switch ($targetStatus) {
                case ServiceOrder::STATUS_CONFIRMED:
                    $attributes['confirmed_at'] = now();
                    $action = 'service_order.confirmed';
                    break;

                case ServiceOrder::STATUS_FULFILLED:
                    $attributes['fulfilled_at'] = now();
                    $action = 'service_order.fulfilled';
                    break;

                case ServiceOrder::STATUS_CANCELLED:
                    $attributes['cancelled_at'] = now();
                    $attributes['cancellation_reason'] = $this->cleanNotes($reason);
                    $action = 'service_order.cancelled';
                    break;
            }

            $locked = $this->orders->update($locked, $attributes);

            if ($targetStatus === ServiceOrder::STATUS_CONFIRMED) {
                $this->postChargeFor($locked, $actor);
            }

            if ($targetStatus === ServiceOrder::STATUS_CANCELLED) {
                $this->voidChargeFor($locked, $actor);
            }

            $this->auditLogger->record(
                $actor,
                $action,
                $locked,
                before: $before,
                after: $this->auditSnapshot($locked),
                hotelId: $locked->hotel_id,
            );

            return $locked;
        });
    }

    // ── Folio charge integration ──────────────────────────────────────

    /**
     * Create the folio charge for a confirmed order. Idempotent: the
     * `(source_type, source_id)` UNIQUE constraint plus this pre-check make
     * a retried confirm a no-op rather than a duplicate charge. Called only
     * from inside the transition transaction (Reservation/Order already
     * locked).
     */
    private function postChargeFor(ServiceOrder $order, ?User $actor): FolioCharge
    {
        $existing = $this->charges->findBySourceForUpdate(FolioCharge::SOURCE_SERVICE_ORDER, $order->id);

        if ($existing !== null) {
            return $existing;
        }

        try {
            $charge = $this->charges->create([
                'reservation_id' => $order->reservation_id,
                'hotel_id' => $order->hotel_id,
                'source_type' => FolioCharge::SOURCE_SERVICE_ORDER,
                'source_id' => $order->id,
                'description' => $this->chargeDescription($order),
                'quantity' => $order->quantity,
                'unit_amount' => (string) $order->unit_price_snapshot,
                'total_amount' => (string) $order->total_amount,
                'currency' => $order->currency_snapshot,
                'status' => FolioCharge::STATUS_POSTED,
                'charged_at' => now(),
                'created_by_user_id' => $actor?->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent confirm won the race — the UNIQUE constraint is
            // authoritative. Re-read and return the winning charge.
            return $this->charges->findBySource(FolioCharge::SOURCE_SERVICE_ORDER, $order->id)
                ?? throw new FolioChargeAmountException('charge_race_lost');
        }

        $this->auditLogger->record(
            $actor,
            'folio_charge.created',
            $charge,
            after: $this->chargeAuditSnapshot($charge),
            hotelId: $charge->hotel_id,
        );

        return $charge;
    }

    /**
     * Void (never negate) the folio charge of a cancelled order. No refund
     * behaviour is invented — a financial reversal, if ever required, is a
     * later phase and the schema stays open for it.
     */
    private function voidChargeFor(ServiceOrder $order, ?User $actor): void
    {
        $charge = $this->charges->findBySourceForUpdate(FolioCharge::SOURCE_SERVICE_ORDER, $order->id);

        if ($charge === null || $charge->status !== FolioCharge::STATUS_POSTED) {
            return;
        }

        $before = $this->chargeAuditSnapshot($charge);
        $charge = $this->charges->update($charge, [
            'status' => FolioCharge::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        $this->auditLogger->record(
            $actor,
            'folio_charge.cancelled',
            $charge,
            before: $before,
            after: $this->chargeAuditSnapshot($charge),
            hotelId: $charge->hotel_id,
        );
    }

    // ── Internals ─────────────────────────────────────────────────────

    /**
     * unit x quantity as a canonical DECIMAL(12,2) string, using bcmath —
     * never float arithmetic. Rejects a result that would not fit the
     * money column.
     */
    private function lineTotal(string $unitPrice, int $quantity): string
    {
        $total = bcmul($unitPrice, (string) $quantity, 2);

        if (! preg_match('/^\d{1,10}(\.\d{2})?$/', $total)) {
            throw new FolioChargeAmountException;
        }

        return $total;
    }

    private function chargeDescription(ServiceOrder $order): string
    {
        $name = $order->service?->name ?? 'Service';

        return mb_substr($name.' x'.$order->quantity, 0, 500);
    }

    private function cleanNotes(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 500);
    }

    /**
     * A safe, flat snapshot for the audit trail — business fields only.
     *
     * @return array<string, mixed>
     */
    private function auditSnapshot(ServiceOrder $order): array
    {
        return array_filter([
            'service_order_status' => $order->status,
            'service_id' => $order->service_id,
            'quantity' => $order->quantity,
            'unit_price_snapshot' => $order->unit_price_snapshot,
            'currency_snapshot' => $order->currency_snapshot,
            'total_amount' => $order->total_amount,
            'requested_at' => $order->requested_at?->toIso8601String(),
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'fulfilled_at' => $order->fulfilled_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function chargeAuditSnapshot(FolioCharge $charge): array
    {
        return array_filter([
            'folio_charge_status' => $charge->status,
            'source_type' => $charge->source_type,
            'source_id' => $charge->source_id,
            'quantity' => $charge->quantity,
            'unit_amount' => $charge->unit_amount,
            'total_amount' => $charge->total_amount,
            'currency' => $charge->currency,
            'charged_at' => $charge->charged_at?->toIso8601String(),
            'cancelled_at' => $charge->cancelled_at?->toIso8601String(),
        ], fn ($value) => $value !== null);
    }
}
