<?php

namespace Tests\Feature\Reservation;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Checkout\Models\Checkout;
use App\Domain\Checkout\Models\Invoice;
use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\Permission;
use App\Domain\IdentityAccess\Models\Role;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityVerification\Models\IdentityVerificationDecision;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Models\Payment;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\FolioCharge;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Management override: a dashboard "move to …" runs the step's real flow
 * (the same one the guest app runs), so the folio, settlement, invoice,
 * payment, identity and digital key all follow — never a bare status write.
 */
class StaffReservationLifecycleTest extends TestCase
{
    /** A stay that began two days ago and ends today, so checkout bills it in full. */
    private function pendingReservation(array $hotel = []): Reservation
    {
        $h = Hotel::factory()->create($hotel + ['deposit_percentage' => 20]);
        $roomType = RoomType::factory()->create(['hotel_id' => $h->id]);
        Room::factory()->create(['hotel_id' => $h->id, 'room_type_id' => $roomType->id]);

        return Reservation::factory()->create([
            'hotel_id' => $h->id,
            'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_PENDING,
            'check_in' => now()->subDays(2)->toDateString(),
            'check_out' => now()->toDateString(),
            'price_snapshot' => '400.00',
            'service_fee_amount' => '0.00',
            'tax_rate' => '0.00',
            'currency' => 'SAR',
        ]);
    }

    private function moveTo(User $actor, Reservation $reservation, string $target): TestResponse
    {
        return $this->actingAs($actor, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/transition", ['target_status' => $target]);
    }

    public function test_the_whole_stay_can_be_driven_from_the_dashboard_through_the_real_flows(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $reservation = $this->pendingReservation();

        // Deposit: the app's rule (20% of 400) through the gateway.
        $this->moveTo($owner, $reservation, Reservation::STATUS_DEPOSIT_HELD)
            ->assertOk()->assertJsonPath('data.status', Reservation::STATUS_DEPOSIT_HELD);
        $payment = Payment::where('reservation_id', $reservation->id)->firstOrFail();
        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, $payment->status);
        $this->assertSame('80.00', (string) $payment->amount);

        // Identity: approved by staff override, without documents.
        $this->moveTo($owner, $reservation, Reservation::STATUS_VERIFIED)
            ->assertOk()->assertJsonPath('data.status', Reservation::STATUS_VERIFIED);
        $session = IdentityVerificationSession::where('reservation_id', $reservation->id)->firstOrFail();
        $this->assertSame(IdentityVerificationSession::STATUS_STAFF_APPROVED, $session->status);
        $this->assertDatabaseHas('identity_verification_decisions', [
            'session_id' => $session->id,
            'result' => IdentityVerificationDecision::RESULT_STAFF_APPROVED,
            'decided_by_user_id' => $owner->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'identity_verification.staff_override_approved']);

        // Check-in keeps its rules: no room yet, so it is refused.
        $this->moveTo($owner, $reservation, Reservation::STATUS_CHECKED_IN)->assertStatus(422);
        $this->assertSame(Reservation::STATUS_VERIFIED, $reservation->fresh()->status);

        $room = Room::where('room_type_id', $reservation->room_type_id)->firstOrFail();
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/room", ['room_id' => $room->id])
            ->assertOk();

        // Check-in issues the key and moves on to in_stay, as in the app.
        $this->moveTo($owner, $reservation, Reservation::STATUS_CHECKED_IN)
            ->assertOk()->assertJsonPath('data.status', Reservation::STATUS_IN_STAY);
        $this->assertSame(AccessGrant::STATUS_ACTIVE, AccessGrant::where('reservation_id', $reservation->id)->value('status'));

        // Checkout: folio lines, final settlement through the deposit, invoice.
        $this->moveTo($owner, $reservation, Reservation::STATUS_CHECKOUT_IN_PROGRESS)
            ->assertOk()->assertJsonPath('data.status', Reservation::STATUS_INVOICED);
        $this->assertDatabaseHas('folio_charges', [
            'reservation_id' => $reservation->id,
            'source_type' => FolioCharge::SOURCE_ACCOMMODATION,
            'total_amount' => '400.00',
            'status' => FolioCharge::STATUS_POSTED,
        ]);
        $this->assertSame(Checkout::STATUS_COMPLETED, Checkout::where('reservation_id', $reservation->id)->value('status'));
        $this->assertSame('400.00', (string) Invoice::where('reservation_id', $reservation->id)->value('subtotal'));

        $this->assertSame(4, AuditLog::where('action', 'reservation.staff_advanced')->count());
    }

    public function test_a_zero_percent_hotel_is_confirmed_without_a_hold_as_in_the_app(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $reservation = $this->pendingReservation(['deposit_percentage' => 0]);

        $this->moveTo($owner, $reservation, Reservation::STATUS_DEPOSIT_HELD)
            ->assertOk()->assertJsonPath('data.status', Reservation::STATUS_DEPOSIT_HELD);

        $this->assertDatabaseMissing('payments', ['reservation_id' => $reservation->id]);
    }

    public function test_a_declined_deposit_is_reported_and_cancels_the_booking_as_in_the_app(): void
    {
        config(['payment.providers.dummy.default_directive' => 'failure']);
        $owner = User::factory()->groupOwner()->create();
        $reservation = $this->pendingReservation();

        $this->moveTo($owner, $reservation, Reservation::STATUS_DEPOSIT_HELD)
            ->assertStatus(422)
            ->assertJsonPath('errors.reason', 'payment_'.Payment::STATUS_HOLD_FAILED)
            ->assertJsonPath('errors.status', Reservation::STATUS_CANCELLED);

        // PaymentWorkflowService::applyHoldResult: a declined hold releases
        // the room — the same outcome a declined card has in the app.
        $this->assertSame(Reservation::STATUS_CANCELLED, $reservation->fresh()->status);
    }

    public function test_the_override_needs_its_own_permission_while_cancelling_does_not(): void
    {
        // A role that manages reservations but holds no override.
        $role = Role::factory()->create(['slug' => 'desk_supervisor']);
        $role->permissions()->sync(Permission::whereIn('slug', ['reservations.view', 'reservations.manage'])->pluck('id'));
        $supervisor = User::factory()->create(['role_id' => $role->id]);
        $reservation = $this->pendingReservation();
        $supervisor->hotels()->attach($reservation->hotel_id);

        $this->moveTo($supervisor, $reservation, Reservation::STATUS_DEPOSIT_HELD)->assertForbidden();
        $this->assertSame(Reservation::STATUS_PENDING, $reservation->fresh()->status);

        $this->moveTo($supervisor, $reservation, Reservation::STATUS_CANCELLED)
            ->assertOk()->assertJsonPath('data.status', Reservation::STATUS_CANCELLED);
    }

    public function test_hotel_managers_hold_the_override_and_reception_does_not(): void
    {
        $this->assertTrue(User::factory()->hotelManager()->create()->hasPermission('reservations.override'));
        $this->assertFalse(User::factory()->reception()->create()->hasPermission('reservations.override'));
    }
}
