<?php

namespace Tests\Feature\Reservation;

use App\Domain\DigitalAccess\Exceptions\CheckInEligibilityException;
use App\Domain\DigitalAccess\Services\DigitalAccessService;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Gateway\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Gateway\Data\GatewayHoldRequest;
use App\Domain\Payment\Gateway\Data\GatewayOperationRequest;
use App\Domain\Payment\Gateway\Data\GatewayResult;
use App\Domain\Payment\Gateway\Data\NormalizedWebhook;
use App\Domain\Payment\Gateway\GatewayOperation;
use App\Domain\Payment\Gateway\GatewayResultStatus;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Services\ReservationExpiryService;
use Closure;
use Tests\TestCase;

/**
 * Batch 1 (2026-10-08): abandoned-booking expiry, create idempotency and
 * the cancellation / check-in race.
 */
class ReservationCompletionWindowTest extends TestCase
{
    private function actingGuest(): Guest
    {
        $guest = Guest::factory()->create();
        $this->withToken($guest->createToken('guest-api')->plainTextToken);

        return $guest;
    }

    private function roomType(int $rooms = 1, array $hotel = []): RoomType
    {
        $h = Hotel::factory()->create($hotel + ['check_in_time' => '15:00:00', 'timezone' => 'UTC', 'deposit_percentage' => 20]);
        $rt = RoomType::factory()->create(['hotel_id' => $h->id, 'base_price' => 100, 'capacity' => 3, 'refundable' => true]);
        Room::factory()->count($rooms)->create(['hotel_id' => $h->id, 'room_type_id' => $rt->id]);

        return $rt;
    }

    private function payload(RoomType $rt, int $inDays = 10, int $nights = 2, int $adults = 2): array
    {
        return [
            'room_type_id' => $rt->id,
            'check_in' => now()->addDays($inDays)->toDateString(),
            'check_out' => now()->addDays($inDays + $nights)->toDateString(),
            'adults' => $adults,
            'children' => 0,
        ];
    }

    private function book(RoomType $rt, array $headers = []): Reservation
    {
        $id = $this->postJson('/api/v1/guest/reservations', $this->payload($rt), $headers)
            ->assertCreated()->json('data.id');

        return Reservation::findOrFail($id);
    }

    private function hold(Reservation $reservation): void
    {
        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/payment/hold")->assertCreated();
    }

    private function sweep(): array
    {
        return app(ReservationExpiryService::class)->sweep();
    }

    /** Wraps the real gateway; $onCancelHold may replace the release result. */
    private function gatewayWith(?Closure $onCancelHold): void
    {
        $real = app(PaymentGatewayInterface::class);
        $this->app->instance(PaymentGatewayInterface::class, new class($real, $onCancelHold) implements PaymentGatewayInterface
        {
            public function __construct(private readonly PaymentGatewayInterface $inner, private readonly ?Closure $onCancelHold) {}

            public function initiateHold(GatewayHoldRequest $request): GatewayResult
            {
                return $this->inner->initiateHold($request);
            }

            public function cancelHold(GatewayOperationRequest $request): GatewayResult
            {
                $override = $this->onCancelHold ? ($this->onCancelHold)() : null;

                return $override ?? $this->inner->cancelHold($request);
            }

            public function capture(GatewayOperationRequest $request): GatewayResult
            {
                return $this->inner->capture($request);
            }

            public function settle(GatewayOperationRequest $request): GatewayResult
            {
                return $this->inner->settle($request);
            }

            public function verify(GatewayOperationRequest $request): GatewayResult
            {
                return $this->inner->verify($request);
            }

            public function parseWebhook(string $rawBody): NormalizedWebhook
            {
                return $this->inner->parseWebhook($rawBody);
            }

            public function verifySignature(string $rawBody, ?string $signature): bool
            {
                return $this->inner->verifySignature($rawBody, $signature);
            }
        });
    }

    private function failedRelease(): GatewayResult
    {
        return new GatewayResult(GatewayOperation::CancelHold, GatewayResultStatus::Failed, 'ref', 'provider_unavailable', 'down');
    }

    // ── Deadline ─────────────────────────────────────────────────────────

    public function test_a_new_booking_gets_a_15_minute_deadline_reset_by_the_deposit_hold_and_cleared_when_verified(): void
    {
        $this->actingGuest();
        $this->travelTo(now()->startOfMinute());
        $reservation = $this->book($this->roomType());

        $this->assertEquals(now()->addMinutes(15)->timestamp, $reservation->completion_deadline_at->timestamp);
        $this->getJson("/api/v1/guest/reservations/{$reservation->id}")
            ->assertJsonPath('data.completion_deadline_at', now()->addMinutes(15)->toIso8601String());

        $this->travel(10)->minutes();
        $this->hold($reservation);
        $this->assertEquals(now()->addMinutes(15)->timestamp, $reservation->fresh()->completion_deadline_at->timestamp);

        app(\App\Domain\Reservation\Services\ReservationService::class)
            ->transitionTo($reservation->fresh(), Reservation::STATUS_VERIFIED);
        $this->assertNull($reservation->fresh()->completion_deadline_at);
    }

    public function test_the_window_is_configurable_and_zero_disables_expiry(): void
    {
        $this->actingGuest();
        config(['guest_booking.completion_window_minutes' => 30]);
        $this->assertEqualsWithDelta(now()->addMinutes(30)->timestamp, $this->book($this->roomType())->completion_deadline_at->timestamp, 5);

        config(['guest_booking.completion_window_minutes' => 0]);
        $off = $this->book($this->roomType());
        $this->assertNull($off->completion_deadline_at);

        $this->travel(2)->hours();
        $this->sweep();
        $this->assertSame(Reservation::STATUS_PENDING, $off->fresh()->status);
    }

    // ── Expiry ───────────────────────────────────────────────────────────

    public function test_an_abandoned_pending_booking_expires_and_frees_the_room(): void
    {
        $this->actingGuest();
        $rt = $this->roomType(rooms: 1);
        $reservation = $this->book($rt);

        // The only room is taken while the attempt is alive.
        $this->actingGuest();
        $this->postJson('/api/v1/guest/reservations', $this->payload($rt))->assertStatus(422);

        $this->travel(14)->minutes();
        $this->assertSame(0, $this->sweep()['expired']);
        $this->assertSame(Reservation::STATUS_PENDING, $reservation->fresh()->status);

        $this->travel(2)->minutes();
        $this->assertSame(1, $this->sweep()['expired']);

        $expired = $reservation->fresh();
        $this->assertSame(Reservation::STATUS_CANCELLED, $expired->status);
        $this->assertSame(ReservationExpiryService::REASON_EXPIRED, $expired->cancellation_reason);
        $this->assertNotNull($expired->cancelled_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reservation.cancelled', 'auditable_id' => $reservation->id]);

        // The room is bookable again.
        $this->postJson('/api/v1/guest/reservations', $this->payload($rt))->assertCreated();
    }

    public function test_an_abandoned_deposit_held_booking_expires_and_its_hold_is_released(): void
    {
        $this->actingGuest();
        $reservation = $this->book($this->roomType());
        $this->hold($reservation);
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $reservation->fresh()->status);

        $this->travel(16)->minutes();
        $result = $this->sweep();

        $this->assertSame(1, $result['expired']);
        $this->assertSame(Reservation::STATUS_CANCELLED, $reservation->fresh()->status);
        $this->assertSame(Payment::STATUS_CANCELLED, Payment::where('reservation_id', $reservation->id)->value('status'));
        $this->assertDatabaseHas('payment_transactions', ['type' => PaymentTransaction::TYPE_CANCEL_HOLD, 'status' => PaymentTransaction::STATUS_SUCCEEDED]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.hold_released']);
    }

    public function test_a_booking_whose_deposit_is_still_processing_is_not_expired(): void
    {
        $this->actingGuest();
        $reservation = $this->book($this->roomType());
        Payment::factory()->create([
            'reservation_id' => $reservation->id,
            'hotel_id' => $reservation->hotel_id,
            'status' => Payment::STATUS_HOLD_REQUESTED,
        ]);

        $this->travel(30)->minutes();
        $result = $this->sweep();

        $this->assertSame(['expired' => 0, 'skipped' => 1], array_intersect_key($result, ['expired' => 0, 'skipped' => 0]));
        $this->assertSame(Reservation::STATUS_PENDING, $reservation->fresh()->status);
    }

    public function test_a_booking_whose_identity_is_in_manual_review_is_not_expired(): void
    {
        $this->actingGuest();
        $reservation = $this->book($this->roomType());
        $this->hold($reservation);
        IdentityVerificationSession::factory()->pendingManualReview()->create([
            'reservation_id' => $reservation->id,
            'guest_id' => $reservation->guest_id,
        ]);

        $this->travel(30)->minutes();
        $this->assertSame(0, $this->sweep()['expired']);
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $reservation->fresh()->status);
        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, Payment::where('reservation_id', $reservation->id)->value('status'));
    }

    public function test_verified_and_older_bookings_without_a_deadline_are_never_expired(): void
    {
        $this->actingGuest();
        $reservation = $this->book($this->roomType());
        $legacy = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING, 'completion_deadline_at' => null]);
        app(\App\Domain\Reservation\Services\ReservationService::class)->transitionTo($reservation, Reservation::STATUS_DEPOSIT_HELD);
        app(\App\Domain\Reservation\Services\ReservationService::class)->transitionTo($reservation->fresh(), Reservation::STATUS_VERIFIED);

        $this->travel(1)->day();
        $this->assertSame(0, $this->sweep()['expired']);
        $this->assertSame(Reservation::STATUS_VERIFIED, $reservation->fresh()->status);
        $this->assertSame(Reservation::STATUS_PENDING, $legacy->fresh()->status);
    }

    public function test_a_failed_expiry_release_cancels_the_booking_and_is_retried_by_the_next_sweep(): void
    {
        $this->actingGuest();
        $reservation = $this->book($this->roomType());
        $this->hold($reservation);

        $this->gatewayWith(fn () => $this->failedRelease());
        $this->travel(16)->minutes();
        $first = $this->sweep();

        // The room is released at once; the money is not yet.
        $this->assertSame(1, $first['expired']);
        $this->assertSame(1, $first['holds_pending']);
        $this->assertSame(Reservation::STATUS_CANCELLED, $reservation->fresh()->status);
        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, Payment::where('reservation_id', $reservation->id)->value('status'));

        $this->gatewayWith(null);
        $second = $this->sweep();
        $this->assertSame(1, $second['holds_released']);
        $this->assertSame(Payment::STATUS_CANCELLED, Payment::where('reservation_id', $reservation->id)->value('status'));
    }

    // ── Superseding ──────────────────────────────────────────────────────

    public function test_a_new_attempt_supersedes_the_guests_own_unpaid_attempt_for_the_same_room_and_dates(): void
    {
        $this->actingGuest();
        $rt = $this->roomType(rooms: 1);
        $old = $this->book($rt, ['Idempotency-Key' => 'rsv-old']);

        // The single room would otherwise be blocked by the guest's own attempt.
        $new = $this->book($rt, ['Idempotency-Key' => 'rsv-new']);

        $this->assertNotSame($old->id, $new->id);
        $this->assertSame(Reservation::STATUS_CANCELLED, $old->fresh()->status);
        $this->assertSame(ReservationExpiryService::REASON_SUPERSEDED, $old->fresh()->cancellation_reason);
        $this->assertSame(Reservation::STATUS_PENDING, $new->status);
    }

    public function test_a_paid_booking_is_never_superseded(): void
    {
        $this->actingGuest();
        $rt = $this->roomType(rooms: 2);
        $paid = $this->book($rt);
        $this->hold($paid);

        $this->book($rt);

        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $paid->fresh()->status);
    }

    // ── Idempotency ──────────────────────────────────────────────────────

    public function test_the_same_idempotency_key_replays_the_booking_instead_of_creating_another(): void
    {
        $this->actingGuest();
        $rt = $this->roomType(rooms: 3);
        $headers = ['Idempotency-Key' => 'rsv-0123abcd'];

        $first = $this->postJson('/api/v1/guest/reservations', $this->payload($rt), $headers)->assertCreated()->json('data.id');
        $second = $this->postJson('/api/v1/guest/reservations', $this->payload($rt), $headers)->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Reservation::query()->where('room_type_id', $rt->id)->count());
        $this->assertSame(Reservation::STATUS_PENDING, Reservation::find($first)->status);
    }

    public function test_reusing_a_key_for_a_different_booking_is_refused(): void
    {
        $this->actingGuest();
        $rt = $this->roomType(rooms: 3);
        $headers = ['Idempotency-Key' => 'rsv-0123abcd'];
        $this->postJson('/api/v1/guest/reservations', $this->payload($rt), $headers)->assertCreated();

        $this->postJson('/api/v1/guest/reservations', $this->payload($rt, adults: 1), $headers)
            ->assertStatus(422)
            ->assertJsonPath('errors.reason', 'idempotency_key_conflict');
        $this->assertSame(1, Reservation::query()->where('room_type_id', $rt->id)->count());
    }

    public function test_keys_are_per_guest_and_must_be_header_safe(): void
    {
        $rt = $this->roomType(rooms: 3);
        $headers = ['Idempotency-Key' => 'rsv-shared'];

        $this->actingGuest();
        $a = $this->postJson('/api/v1/guest/reservations', $this->payload($rt), $headers)->assertCreated()->json('data.id');
        $this->actingGuest();
        $b = $this->postJson('/api/v1/guest/reservations', $this->payload($rt), $headers)->assertCreated()->json('data.id');
        $this->assertNotSame($a, $b);

        $this->postJson('/api/v1/guest/reservations', $this->payload($rt), ['Idempotency-Key' => 'a|b'])->assertStatus(422);
    }

    public function test_without_a_key_two_confirms_still_cannot_overbook_the_last_room(): void
    {
        $rt = $this->roomType(rooms: 1);
        $this->actingGuest();
        $this->postJson('/api/v1/guest/reservations', $this->payload($rt))->assertCreated();
        $this->actingGuest();
        $this->postJson('/api/v1/guest/reservations', $this->payload($rt))->assertStatus(422);

        $this->assertSame(1, Reservation::query()->where('room_type_id', $rt->id)->count());
    }

    // ── Cancellation vs check-in ─────────────────────────────────────────

    private function verifiedWithRoom(Guest $guest): Reservation
    {
        $rt = $this->roomType();
        $reservation = Reservation::factory()->withRoom()->create([
            'guest_id' => $guest->id,
            'hotel_id' => $rt->hotel_id,
            'room_type_id' => $rt->id,
            'status' => Reservation::STATUS_VERIFIED,
            'check_in' => now()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'is_refundable' => true,
            'free_cancellation_until' => now()->addHour(),
        ]);
        $payment = Payment::factory()->holdActive()->create(['reservation_id' => $reservation->id, 'hotel_id' => $rt->hotel_id]);
        PaymentTransaction::factory()->succeeded()->create(['payment_id' => $payment->id, 'type' => PaymentTransaction::TYPE_HOLD]);
        IdentityVerificationSession::factory()->autoApproved()->create(['reservation_id' => $reservation->id]);

        return $reservation;
    }

    public function test_check_in_cannot_slip_in_while_a_cancellation_releases_the_deposit(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->verifiedWithRoom($guest);
        $blocked = null;

        // While the provider is releasing the hold, reception tries to check the guest in.
        $this->gatewayWith(function () use ($reservation, &$blocked) {
            try {
                app(DigitalAccessService::class)->checkIn($reservation->fresh());
            } catch (CheckInEligibilityException $e) {
                $blocked = $e->reason;
            }

            return null;
        });

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/cancel")->assertOk()
            ->assertJsonPath('data.status', Reservation::STATUS_CANCELLED);

        $this->assertSame('cancellation_in_progress', $blocked);
        $this->assertSame(Reservation::STATUS_CANCELLED, $reservation->fresh()->status);
        $this->assertDatabaseCount('access_grants', 0);
        $this->assertSame(Payment::STATUS_CANCELLED, Payment::where('reservation_id', $reservation->id)->value('status'));
    }

    public function test_a_failed_release_leaves_the_booking_checkable_in(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->verifiedWithRoom($guest);

        $this->gatewayWith(fn () => $this->failedRelease());
        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/cancel")
            ->assertStatus(422)->assertJsonPath('errors.reason', 'refund_failed');

        $this->assertSame(Reservation::STATUS_VERIFIED, $reservation->fresh()->status);
        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, Payment::where('reservation_id', $reservation->id)->value('status'));

        // The failed release no longer fences: check-in works.
        $this->gatewayWith(null);
        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/check-in")->assertStatus(201);
    }

    public function test_a_stale_pending_release_from_a_crashed_process_does_not_block_check_in_forever(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->verifiedWithRoom($guest);
        $payment = Payment::where('reservation_id', $reservation->id)->firstOrFail();
        $stale = PaymentTransaction::factory()->pending()->create(['payment_id' => $payment->id, 'type' => PaymentTransaction::TYPE_CANCEL_HOLD]);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/check-in")
            ->assertStatus(422);

        $stale->forceFill(['created_at' => now()->subMinutes(5)])->saveQuietly();
        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/check-in")->assertStatus(201);
    }

    public function test_cancelling_after_check_in_is_refused_and_keeps_the_deposit(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->verifiedWithRoom($guest);
        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/check-in")->assertStatus(201);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/cancel")
            ->assertStatus(422)->assertJsonPath('errors.reason', 'status_not_cancellable');

        $this->assertSame(Reservation::STATUS_IN_STAY, $reservation->fresh()->status);
        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, Payment::where('reservation_id', $reservation->id)->value('status'));
    }
}
