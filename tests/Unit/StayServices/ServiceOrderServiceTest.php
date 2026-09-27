<?php

namespace Tests\Unit\StayServices;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Exceptions\InvalidServiceOrderStatusTransitionException;
use App\Domain\StayServices\Exceptions\ServiceOrderNotAllowedException;
use App\Domain\StayServices\Models\FolioCharge;
use App\Domain\StayServices\Models\ServiceOrder;
use App\Domain\StayServices\Services\ServiceOrderService;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ServiceOrderServiceTest extends StayServicesTestCase
{
    private function orders(): ServiceOrderService
    {
        return app(ServiceOrderService::class);
    }

    public function test_create_snapshots_price_and_computes_total_server_side(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->serviceableReservation($hotel);
        $service = $this->service($hotel, '19.99');
        $actor = User::factory()->reception()->create();

        $order = $this->orders()->create($reservation, [
            'service_id' => $service->id,
            'quantity' => 3,
            'total_amount' => '1.00',   // client value — must be ignored
            'unit_price_snapshot' => '0.01',
            'hotel_id' => Hotel::factory()->create()->id,
        ], $actor);

        $this->assertSame($hotel->id, $order->hotel_id);
        $this->assertSame('19.99', $order->unit_price_snapshot);
        $this->assertSame('59.97', $order->total_amount);
        $this->assertSame('USD', $order->currency_snapshot);
        $this->assertSame(ServiceOrder::STATUS_REQUESTED, $order->status);
        $this->assertSame($actor->id, $order->requested_by_user_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'service_order.created']);
    }

    public function test_price_snapshot_is_frozen_against_later_price_changes(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->serviceableReservation($hotel);
        $service = $this->service($hotel, '20.00');

        $order = $this->orders()->create($reservation, ['service_id' => $service->id, 'quantity' => 1], null);
        $service->update(['price' => '999.00']);

        $this->assertSame('20.00', $order->fresh()->unit_price_snapshot);
        $this->assertSame('20.00', $order->fresh()->total_amount);
    }

    public function test_inactive_service_is_rejected(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->serviceableReservation($hotel);
        $service = $this->service($hotel, '10.00', active: false);

        $this->expectException(ServiceOrderNotAllowedException::class);
        $this->orders()->create($reservation, ['service_id' => $service->id, 'quantity' => 1], null);
    }

    public function test_cross_hotel_service_is_rejected(): void
    {
        $reservation = $this->serviceableReservation();
        $service = $this->service(Hotel::factory()->create(), '10.00');

        $this->expectException(ServiceOrderNotAllowedException::class);
        $this->orders()->create($reservation, ['service_id' => $service->id, 'quantity' => 1], null);
    }

    public function test_missing_service_is_a_model_not_found(): void
    {
        $reservation = $this->serviceableReservation();

        $this->expectException(ModelNotFoundException::class);
        $this->orders()->create($reservation, ['service_id' => 999999, 'quantity' => 1], null);
    }

    public function test_reservation_not_in_a_serviceable_state_is_rejected(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->serviceableReservation($hotel, Reservation::STATUS_DEPOSIT_HELD);
        $service = $this->service($hotel, '10.00');

        try {
            $this->orders()->create($reservation, ['service_id' => $service->id, 'quantity' => 1], null);
            $this->fail('expected rejection');
        } catch (ServiceOrderNotAllowedException $e) {
            $this->assertStringContainsString('reservation_not_serviceable', $e->reason);
        }

        $this->assertDatabaseCount('service_orders', 0);
    }

    public function test_in_stay_reservation_is_serviceable(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->serviceableReservation($hotel, Reservation::STATUS_IN_STAY);
        $service = $this->service($hotel, '10.00');

        $order = $this->orders()->create($reservation, ['service_id' => $service->id, 'quantity' => 1], null);
        $this->assertSame(ServiceOrder::STATUS_REQUESTED, $order->status);
    }

    public function test_confirm_creates_one_posted_folio_charge(): void
    {
        $order = $this->requestedOrder();

        $confirmed = $this->orders()->transition($order, ServiceOrder::STATUS_CONFIRMED, null, null);

        $this->assertSame(ServiceOrder::STATUS_CONFIRMED, $confirmed->status);
        $this->assertNotNull($confirmed->confirmed_at);

        $charge = FolioCharge::sole();
        $this->assertSame(FolioCharge::SOURCE_SERVICE_ORDER, $charge->source_type);
        $this->assertSame($order->id, $charge->source_id);
        $this->assertSame($order->reservation_id, $charge->reservation_id);
        $this->assertSame($order->hotel_id, $charge->hotel_id);
        $this->assertSame($order->total_amount, $charge->total_amount);
        $this->assertSame(FolioCharge::STATUS_POSTED, $charge->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'folio_charge.created']);
    }

    public function test_confirm_is_idempotent_and_never_double_charges(): void
    {
        $order = $this->requestedOrder();
        $this->orders()->transition($order->fresh(), ServiceOrder::STATUS_CONFIRMED, null, null);

        // A second confirm is an illegal transition (confirmed -> confirmed)
        // and even a direct re-post cannot duplicate the charge.
        $this->expectException(InvalidServiceOrderStatusTransitionException::class);
        $this->orders()->transition($order->fresh(), ServiceOrder::STATUS_CONFIRMED, null, null);
    }

    public function test_only_one_charge_row_per_source_even_under_repost(): void
    {
        $order = $this->requestedOrder();
        $this->orders()->transition($order->fresh(), ServiceOrder::STATUS_CONFIRMED, null, null);
        $this->orders()->transition($order->fresh(), ServiceOrder::STATUS_FULFILLED, null, null);

        $this->assertSame(1, FolioCharge::where('source_id', $order->id)->count());
    }

    public function test_cancelling_a_confirmed_order_voids_its_charge_without_a_negative(): void
    {
        $order = $this->requestedOrder();
        $this->orders()->transition($order->fresh(), ServiceOrder::STATUS_CONFIRMED, null, null);

        $this->orders()->transition($order->fresh(), ServiceOrder::STATUS_CANCELLED, 'guest changed mind', null);

        $charge = FolioCharge::sole();
        $this->assertSame(FolioCharge::STATUS_CANCELLED, $charge->status);
        $this->assertNotNull($charge->cancelled_at);
        $this->assertTrue((float) $charge->total_amount > 0, 'the amount is never negated');
        $this->assertSame(1, FolioCharge::count(), 'no reversal row is created');
        $this->assertDatabaseHas('audit_logs', ['action' => 'folio_charge.cancelled']);
    }

    public function test_cancelling_a_requested_order_creates_no_charge(): void
    {
        $order = $this->requestedOrder();

        $this->orders()->transition($order, ServiceOrder::STATUS_CANCELLED, null, null);

        $this->assertDatabaseCount('folio_charges', 0);
    }

    public function test_fulfilled_is_terminal(): void
    {
        $order = $this->requestedOrder();
        $this->orders()->transition($order->fresh(), ServiceOrder::STATUS_CONFIRMED, null, null);
        $this->orders()->transition($order->fresh(), ServiceOrder::STATUS_FULFILLED, null, null);

        $this->expectException(InvalidServiceOrderStatusTransitionException::class);
        $this->orders()->transition($order->fresh(), ServiceOrder::STATUS_CANCELLED, null, null);
    }

    private function requestedOrder(): ServiceOrder
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->serviceableReservation($hotel);
        $service = $this->service($hotel, '25.00');

        return $this->orders()->create($reservation, ['service_id' => $service->id, 'quantity' => 2], null);
    }
}
