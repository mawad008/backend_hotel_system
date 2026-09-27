<?php

namespace Tests\Unit\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Payment\Models\PaymentWebhookEvent;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    public function test_factory_creates_a_valid_payment(): void
    {
        $payment = Payment::factory()->create();

        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        $this->assertSame(Payment::STATUS_NOT_STARTED, $payment->status);
    }

    public function test_belongs_to_a_reservation_and_reservation_has_one_payment(): void
    {
        $reservation = Reservation::factory()->create();
        $payment = Payment::factory()->create(['reservation_id' => $reservation->id, 'hotel_id' => $reservation->hotel_id]);

        $this->assertTrue($payment->reservation->is($reservation));
        $this->assertTrue($reservation->fresh()->payment->is($payment));
    }

    public function test_reservation_id_is_unique(): void
    {
        $payment = Payment::factory()->create();

        $this->expectException(QueryException::class);

        Payment::factory()->create([
            'reservation_id' => $payment->reservation_id,
            'hotel_id' => $payment->hotel_id,
        ]);
    }

    public function test_belongs_to_a_hotel(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = Reservation::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotel->id])->id]);
        $payment = Payment::factory()->create(['reservation_id' => $reservation->id, 'hotel_id' => $hotel->id]);

        $this->assertTrue($payment->hotel->is($hotel));
    }

    public function test_hotel_id_is_required(): void
    {
        $this->expectException(QueryException::class);

        Payment::factory()->create(['hotel_id' => null]);
    }

    public function test_deleting_a_reservation_referenced_by_a_payment_is_blocked(): void
    {
        $payment = Payment::factory()->create();

        $this->expectException(QueryException::class);

        $payment->reservation->delete();
    }

    public function test_amount_persists_as_a_decimal_with_two_places(): void
    {
        $payment = Payment::factory()->create(['amount' => 199.5]);

        $this->assertSame('199.50', $payment->fresh()->amount);
    }

    public function test_amount_supports_large_values_within_decimal_12_2(): void
    {
        $payment = Payment::factory()->create(['amount' => 9999999999.99]);

        $this->assertSame('9999999999.99', $payment->fresh()->amount);
    }

    public function test_amount_is_nullable(): void
    {
        $payment = Payment::factory()->create(['amount' => null]);

        $this->assertNull($payment->fresh()->amount);
    }

    public function test_currency_stores_a_three_letter_code_and_is_nullable(): void
    {
        $withCode = Payment::factory()->create(['currency' => 'EUR']);
        $this->assertSame('EUR', $withCode->fresh()->currency);

        $withoutCode = Payment::factory()->create(['currency' => null]);
        $this->assertNull($withoutCode->fresh()->currency);
    }

    public function test_provider_customer_ref_is_nullable(): void
    {
        $payment = Payment::factory()->create();

        $this->assertNull($payment->fresh()->provider_customer_ref);
    }

    public function test_hold_expires_at_is_nullable_and_casts_to_a_datetime(): void
    {
        $plain = Payment::factory()->create();
        $this->assertNull($plain->fresh()->hold_expires_at);

        $withExpiry = Payment::factory()->holdActive()->create();
        $this->assertNotNull($withExpiry->fresh()->hold_expires_at);
        $this->assertInstanceOf(Carbon::class, $withExpiry->fresh()->hold_expires_at);
    }

    public function test_all_fifteen_approved_statuses_are_accepted_by_the_column(): void
    {
        $this->assertCount(15, Payment::STATUSES);

        foreach (Payment::STATUSES as $status) {
            $payment = Payment::factory()->create(['status' => $status]);
            $this->assertSame($status, $payment->fresh()->status);
        }
    }

    public function test_column_rejects_an_unapproved_status(): void
    {
        $payment = Payment::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('payments')->where('id', $payment->id)->update(['status' => 'callback']);
    }

    public function test_has_many_transactions_and_webhook_events(): void
    {
        $payment = Payment::factory()->create();
        PaymentTransaction::factory()->count(2)->create(['payment_id' => $payment->id]);
        PaymentWebhookEvent::factory()->create(['payment_id' => $payment->id]);

        $this->assertCount(2, $payment->transactions);
        $this->assertCount(1, $payment->webhookEvents);
    }

    public function test_factory_hotel_id_always_matches_the_reservations_hotel(): void
    {
        $payment = Payment::factory()->create();

        $this->assertSame($payment->reservation->hotel_id, $payment->hotel_id);
    }
}
