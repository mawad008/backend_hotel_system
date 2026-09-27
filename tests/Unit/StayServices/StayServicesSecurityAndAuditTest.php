<?php

namespace Tests\Unit\StayServices;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\ServiceOrder;
use App\Domain\StayServices\Services\ServiceOrderService;

class StayServicesSecurityAndAuditTest extends StayServicesTestCase
{
    private function orders(): ServiceOrderService
    {
        return app(ServiceOrderService::class);
    }

    public function test_client_supplied_financial_and_identity_fields_are_ignored(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->serviceableReservation($hotel);
        $service = $this->service($hotel, '33.00');
        $actor = User::factory()->reception()->create();
        $someoneElse = User::factory()->groupOwner()->create();

        $order = $this->orders()->create($reservation, [
            'service_id' => $service->id,
            'quantity' => 2,
            'unit_price_snapshot' => '0.01',
            'total_amount' => '0.02',
            'currency_snapshot' => 'XYZ',
            'hotel_id' => Hotel::factory()->create()->id,
            'reservation_id' => Reservation::factory()->create()->id,
            'status' => ServiceOrder::STATUS_FULFILLED,
            'requested_by_user_id' => $someoneElse->id,
        ], $actor);

        $this->assertSame('33.00', $order->unit_price_snapshot);
        $this->assertSame('66.00', $order->total_amount);
        $this->assertSame('USD', $order->currency_snapshot);
        $this->assertSame($hotel->id, $order->hotel_id);
        $this->assertSame($reservation->id, $order->reservation_id);
        $this->assertSame(ServiceOrder::STATUS_REQUESTED, $order->status);
        $this->assertSame($actor->id, $order->requested_by_user_id, 'actor comes from the token, not the body');
    }

    public function test_every_mutation_is_audited_with_a_safe_snapshot(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->serviceableReservation($hotel);
        $service = $this->service($hotel, '10.00');
        $actor = User::factory()->groupOwner()->create();

        $order = $this->orders()->create($reservation, ['service_id' => $service->id, 'quantity' => 1], $actor);
        $this->orders()->transition($order->fresh(), ServiceOrder::STATUS_CONFIRMED, null, $actor);
        $this->orders()->transition($order->fresh(), ServiceOrder::STATUS_CANCELLED, 'reason text', $actor);

        $actions = AuditLog::pluck('action')->all();
        foreach ([
            'service_order.created',
            'service_order.confirmed',
            'folio_charge.created',
            'service_order.cancelled',
            'folio_charge.cancelled',
        ] as $expected) {
            $this->assertContains($expected, $actions, "missing audit action {$expected}");
        }

        // No audit row carries a stack trace / SQLSTATE / free-text reason leak.
        foreach (AuditLog::all() as $log) {
            $blob = json_encode([$log->before, $log->after]);
            $this->assertStringNotContainsString('SQLSTATE', $blob);
            $this->assertStringNotContainsString('.php', $blob);
            $this->assertStringNotContainsString('reason text', $blob, 'free-text cancellation reason is not copied into audit');
        }
    }
}
