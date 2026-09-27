<?php

namespace Tests\Unit\Repositories;

use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Repositories\Contracts\PaymentRepositoryInterface;
use App\Domain\Payment\Repositories\EloquentPaymentRepository;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentRepositoryTest extends TestCase
{
    private EloquentPaymentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EloquentPaymentRepository;
    }

    public function test_it_is_bound_to_the_contract(): void
    {
        $this->assertInstanceOf(EloquentPaymentRepository::class, app(PaymentRepositoryInterface::class));
    }

    public function test_find_returns_a_payment_by_id(): void
    {
        $payment = Payment::factory()->create();

        $this->assertTrue($this->repository->find($payment->id)->is($payment));
        $this->assertNull($this->repository->find(999999));
    }

    public function test_find_by_reservation_returns_the_single_payment(): void
    {
        $reservation = Reservation::factory()->create();
        $payment = Payment::factory()->create(['reservation_id' => $reservation->id, 'hotel_id' => $reservation->hotel_id]);

        $this->assertTrue($this->repository->findByReservation($reservation->id)->is($payment));
    }

    public function test_find_by_reservation_returns_null_when_no_payment_started(): void
    {
        $reservation = Reservation::factory()->create();

        $this->assertNull($this->repository->findByReservation($reservation->id));
    }

    public function test_create_persists_a_payment(): void
    {
        $reservation = Reservation::factory()->create();

        $payment = $this->repository->create([
            'reservation_id' => $reservation->id,
            'hotel_id' => $reservation->hotel_id,
            'status' => Payment::STATUS_NOT_STARTED,
            'currency' => null,
            'amount' => null,
        ]);

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'reservation_id' => $reservation->id]);
        $this->assertSame(Payment::STATUS_NOT_STARTED, $payment->status);
    }

    public function test_it_is_bound_as_the_contract_in_the_container(): void
    {
        $this->assertInstanceOf(EloquentPaymentRepository::class, app(PaymentRepositoryInterface::class));
    }

    public function test_find_for_update_returns_the_payment_within_a_transaction(): void
    {
        $payment = Payment::factory()->create();

        DB::transaction(function () use ($payment) {
            $this->assertTrue($this->repository->findForUpdate($payment->id)->is($payment));
            $this->assertNull($this->repository->findForUpdate(999999));
        });
    }

    public function test_find_by_reservation_for_update_returns_the_payment_within_a_transaction(): void
    {
        $payment = Payment::factory()->create();

        DB::transaction(function () use ($payment) {
            $this->assertTrue($this->repository->findByReservationForUpdate($payment->reservation_id)->is($payment));
            $this->assertNull($this->repository->findByReservationForUpdate(999999));
        });
    }

    public function test_update_persists_and_returns_the_refreshed_model(): void
    {
        $payment = Payment::factory()->create(['status' => Payment::STATUS_HOLD_REQUESTED, 'amount' => null]);

        $updated = $this->repository->update($payment, [
            'status' => Payment::STATUS_HOLD_ACTIVE,
            'amount' => '125.00',
        ]);

        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, $updated->status);
        $this->assertSame('125.00', $updated->amount);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => Payment::STATUS_HOLD_ACTIVE]);
    }
}
