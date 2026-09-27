<?php

namespace Tests\Feature\Payment;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Gateway\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Gateway\GatewayResultStatus;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Reservation\Models\Reservation;
use App\Http\Controllers\Api\V1\PaymentController;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Payment\Workflow\Fakes\RecordingPaymentGateway;

/**
 * Phase 5D — POST /api/v1/reservations/{reservation}/payment/hold.
 *
 * Proves the HTTP / auth / policy / request / resource / idempotency
 * integration around the Phase 5C PaymentWorkflowService. The workflow's
 * own state-machine / concurrency / late-success behaviour is covered by
 * the Phase 5C unit suite and is not re-proved exhaustively here.
 */
class PaymentHoldApiTest extends TestCase
{
    private function reservation(string $status = Reservation::STATUS_PENDING, ?Hotel $hotel = null): Reservation
    {
        $hotel ??= Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        return Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => $status,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function hold(User $actor, Reservation $reservation, array $body = ['amount' => '100.00'], array $headers = []): TestResponse
    {
        return $this->actingAs($actor, 'sanctum')
            ->withHeaders($headers)
            ->postJson("/api/v1/reservations/{$reservation->id}/payment/hold", $body);
    }

    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    // ══ A. Authentication ═══════════════════════════════════════════

    public function test_unauthenticated_request_is_rejected(): void
    {
        $reservation = $this->reservation();

        $this->postJson("/api/v1/reservations/{$reservation->id}/payment/hold", ['amount' => '100.00'])
            ->assertStatus(401)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('payments', 0);
    }

    // ══ B. Authorization / hotel scope ═════════════════════════════

    public function test_group_owner_can_initiate_a_hold(): void
    {
        $this->hold($this->owner(), $this->reservation())->assertStatus(201);
    }

    public function test_assigned_hotel_manager_can_initiate_a_hold(): void
    {
        $hotel = Hotel::factory()->create();
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        $this->hold($manager, $this->reservation(hotel: $hotel))->assertStatus(201);
    }

    public function test_reception_is_forbidden_from_initiating_a_hold(): void
    {
        $hotel = Hotel::factory()->create();
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);

        $this->hold($reception, $this->reservation(hotel: $hotel))
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_guest_role_user_cannot_reach_the_endpoint(): void
    {
        // No hotel access at all → resolved out of scope → clean 404, no leak.
        $this->hold(User::factory()->guest()->create(), $this->reservation())
            ->assertStatus(404);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_manager_in_an_unassigned_hotel_gets_a_scope_404(): void
    {
        $assigned = Hotel::factory()->create();
        $other = Hotel::factory()->create();
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($assigned);

        $this->hold($manager, $this->reservation(hotel: $other))->assertStatus(404);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_nonexistent_reservation_returns_404(): void
    {
        $this->actingAs($this->owner(), 'sanctum')
            ->postJson('/api/v1/reservations/999999/payment/hold', ['amount' => '100.00'])
            ->assertStatus(404);
    }

    public function test_client_supplied_hotel_id_and_actor_are_ignored(): void
    {
        $hotel = Hotel::factory()->create();
        $otherHotel = Hotel::factory()->create();
        $reservation = $this->reservation(hotel: $hotel);

        $this->hold($this->owner(), $reservation, [
            'amount' => '100.00',
            'hotel_id' => $otherHotel->id,
            'user_id' => 999999,
            'actor_id' => 999999,
            'role' => 'group_owner',
        ])->assertStatus(201)
            ->assertJsonPath('data.hotel_id', $hotel->id);

        $this->assertSame($hotel->id, Payment::sole()->hotel_id);
    }

    // ══ C. Validation ═════════════════════════════════════════════

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'missing' => [null],
            'zero' => ['0'],
            'zero decimal' => ['0.00'],
            'negative' => ['-5.00'],
            'malformed' => ['abc'],
            'three decimals' => ['10.999'],
            'oversized' => ['100000000000'],
            'numeric not string' => [100],
            'empty string' => [''],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amount_is_a_422_validation_error(mixed $amount): void
    {
        $body = $amount === null ? [] : ['amount' => $amount];

        $this->hold($this->owner(), $this->reservation(), $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function validAmounts(): array
    {
        return [
            'two decimals' => ['150.25', '150.25'],
            'one decimal' => ['150.2', '150.20'],
            'integer string' => ['150', '150.00'],
            'max value' => ['9999999999.99', '9999999999.99'],
            'smallest' => ['0.01', '0.01'],
        ];
    }

    #[DataProvider('validAmounts')]
    public function test_valid_amount_is_accepted_and_normalized(string $amount, string $expected): void
    {
        $this->hold($this->owner(), $this->reservation(), ['amount' => $amount])
            ->assertStatus(201)
            ->assertJsonPath('data.amount', $expected);
    }

    public function test_invalid_currency_is_a_422_validation_error(): void
    {
        $this->hold($this->owner(), $this->reservation(), ['amount' => '100.00', 'currency' => 'EURO'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['currency']);
    }

    public function test_valid_currency_is_accepted_and_uppercased(): void
    {
        $this->hold($this->owner(), $this->reservation(), ['amount' => '100.00', 'currency' => 'eur'])
            ->assertStatus(201)
            ->assertJsonPath('data.currency', 'EUR');
    }

    public function test_omitted_currency_stays_null(): void
    {
        config(['payment.currency' => null]);

        $this->hold($this->owner(), $this->reservation(), ['amount' => '100.00'])
            ->assertStatus(201)
            ->assertJsonPath('data.currency', null);
    }

    // ══ D. Happy path ═════════════════════════════════════════════

    public function test_successful_hold_moves_payment_reservation_and_transaction(): void
    {
        $reservation = $this->reservation();

        $response = $this->hold($this->owner(), $reservation, ['amount' => '150.00', 'currency' => 'EUR']);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', Payment::STATUS_HOLD_ACTIVE)
            ->assertJsonPath('data.reservation_id', $reservation->id);

        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, Payment::sole()->status);
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $reservation->fresh()->status);
        $this->assertSame(PaymentTransaction::STATUS_SUCCEEDED, PaymentTransaction::sole()->status);
    }

    public function test_happy_path_audit_comes_from_the_workflow_not_the_controller(): void
    {
        $reservation = $this->reservation();
        $this->hold($this->owner(), $reservation, ['amount' => '150.00']);

        // Exactly the Phase 5C audit trail — no extra controller-level entry.
        $this->assertSame(1, AuditLog::where('action', 'payment.hold_requested')->count());
        $this->assertSame(1, AuditLog::where('action', 'payment.hold_succeeded')->count());
        $this->assertSame(0, AuditLog::where('action', 'like', 'payment.hold%api%')->count());
    }

    // ══ E / F / G / H. Provider outcomes via the guarded sim header ═

    public function test_pending_provider_result_reports_hold_requested_without_claiming_success(): void
    {
        $reservation = $this->reservation();

        $this->hold($this->owner(), $reservation, ['amount' => '100.00'], ['X-Payment-Simulate' => 'pending'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', Payment::STATUS_HOLD_REQUESTED);

        $this->assertSame(Reservation::STATUS_PENDING, $reservation->fresh()->status);
        $this->assertSame(PaymentTransaction::STATUS_PENDING, PaymentTransaction::sole()->status);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function terminalOutcomes(): array
    {
        return [
            'failure' => ['failure', Payment::STATUS_HOLD_FAILED, PaymentTransaction::STATUS_FAILED],
            'cancelled' => ['cancelled', Payment::STATUS_CANCELLED, PaymentTransaction::STATUS_CANCELLED],
            'expired' => ['expired', Payment::STATUS_EXPIRED, PaymentTransaction::STATUS_EXPIRED],
        ];
    }

    #[DataProvider('terminalOutcomes')]
    public function test_terminal_provider_outcome_returns_422_and_cancels_the_reservation(
        string $directive,
        string $paymentStatus,
        string $transactionStatus,
    ): void {
        $reservation = $this->reservation();

        $this->hold($this->owner(), $reservation, ['amount' => '100.00'], ['X-Payment-Simulate' => $directive])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.status', $paymentStatus);

        $this->assertSame($paymentStatus, Payment::sole()->status);
        $this->assertSame($transactionStatus, PaymentTransaction::sole()->status);
        $this->assertSame(Reservation::STATUS_CANCELLED, $reservation->fresh()->status);
    }

    // ══ I. Idempotency ═══════════════════════════════════════════

    public function test_same_idempotency_key_replays_without_a_second_payment_or_provider_call(): void
    {
        $reservation = $this->reservation();
        $owner = $this->owner();
        $headers = ['Idempotency-Key' => 'reservation-hold-1-001'];

        $first = $this->hold($owner, $reservation, ['amount' => '100.00'], $headers)->assertStatus(201);
        $second = $this->hold($owner, $reservation->fresh(), ['amount' => '100.00'], $headers)->assertStatus(201);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    public function test_same_idempotency_key_replays_a_pending_hold(): void
    {
        $reservation = $this->reservation();
        $owner = $this->owner();
        $headers = ['Idempotency-Key' => 'k-pending', 'X-Payment-Simulate' => 'pending'];

        $this->hold($owner, $reservation, ['amount' => '100.00'], $headers)->assertStatus(200);
        $this->hold($owner, $reservation->fresh(), ['amount' => '100.00'], $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.status', Payment::STATUS_HOLD_REQUESTED);

        $this->assertDatabaseCount('payment_transactions', 1);
    }

    public function test_idempotency_key_reused_across_reservations_is_a_422_conflict(): void
    {
        $owner = $this->owner();
        $headers = ['Idempotency-Key' => 'shared-key'];

        $this->hold($owner, $this->reservation(), ['amount' => '100.00'], $headers)->assertStatus(201);

        $this->hold($owner, $this->reservation(), ['amount' => '100.00'], $headers)
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('payment_transactions', 1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidIdempotencyKeys(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['has spaces'],
            'illegal chars' => ['key/with/slashes'],
            'too long' => [str_repeat('a', 256)],
        ];
    }

    #[DataProvider('invalidIdempotencyKeys')]
    public function test_malformed_idempotency_key_header_is_a_422(string $key): void
    {
        $this->hold($this->owner(), $this->reservation(), ['amount' => '100.00'], ['Idempotency-Key' => $key])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_no_idempotency_header_lets_the_workflow_generate_a_uuid(): void
    {
        $this->hold($this->owner(), $this->reservation(), ['amount' => '100.00'])->assertStatus(201);

        $key = PaymentTransaction::sole()->idempotency_key;
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $key);
    }

    // ══ J. Already initiated ═════════════════════════════════════

    public function test_second_differently_keyed_hold_on_a_pending_payment_is_a_422(): void
    {
        $reservation = $this->reservation();
        $owner = $this->owner();

        $this->hold($owner, $reservation, ['amount' => '100.00'], ['Idempotency-Key' => 'k1', 'X-Payment-Simulate' => 'pending'])
            ->assertStatus(200);

        $this->hold($owner, $reservation->fresh(), ['amount' => '100.00'], ['Idempotency-Key' => 'k2', 'X-Payment-Simulate' => 'pending'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('payments', 1);
    }

    // ══ K. Invalid reservation state ═════════════════════════════

    /**
     * @return array<string, array{string}>
     */
    public static function nonPendingStatuses(): array
    {
        return [
            'deposit_held' => [Reservation::STATUS_DEPOSIT_HELD],
            'verified' => [Reservation::STATUS_VERIFIED],
            'checked_in' => [Reservation::STATUS_CHECKED_IN],
            'cancelled' => [Reservation::STATUS_CANCELLED],
            'checked_out' => [Reservation::STATUS_CHECKED_OUT],
            'invoiced' => [Reservation::STATUS_INVOICED],
        ];
    }

    #[DataProvider('nonPendingStatuses')]
    public function test_hold_on_a_non_pending_reservation_is_a_422(string $status): void
    {
        $this->hold($this->owner(), $this->reservation($status), ['amount' => '100.00'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('payments', 0);
    }

    // ══ L. Security — response never leaks internals ═════════════

    public function test_success_response_exposes_only_the_allowed_payment_fields(): void
    {
        $response = $this->hold($this->owner(), $this->reservation(), ['amount' => '100.00', 'currency' => 'EUR'])
            ->assertStatus(201);

        $response->assertJsonStructure(['success', 'message', 'data' => [
            'id', 'reservation_id', 'hotel_id', 'status', 'amount', 'currency',
            'hold_expires_at', 'created_at', 'updated_at',
        ]]);

        $data = $response->json('data');
        foreach (['metadata', 'provider_reference', 'provider_customer_ref', 'idempotency_key', 'transactions'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function outcomesToScan(): array
    {
        return [
            'success' => ['success'],
            'failure' => ['failure'],
            'pending' => ['pending'],
        ];
    }

    #[DataProvider('outcomesToScan')]
    public function test_no_response_leaks_secrets_or_internals(string $directive): void
    {
        config(['payment.providers.dummy.webhook_secret' => 'super-secret-value']);

        $body = $this->hold($this->owner(), $this->reservation(), ['amount' => '100.00'], ['X-Payment-Simulate' => $directive])
            ->getContent();

        foreach (['super-secret-value', 'webhook_secret', 'SQLSTATE', 'stack trace', '#0 /', 'card_number', 'cvv', 'DummyPaymentGateway', '.php:'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $body, "response leaked '{$needle}'");
        }
    }

    // ══ N. HTTP envelope ═══════════════════════════════════════

    public function test_responses_use_the_standard_envelope(): void
    {
        $this->hold($this->owner(), $this->reservation(), ['amount' => '100.00'])
            ->assertStatus(201)
            ->assertJsonStructure(['success', 'message', 'data']);

        $this->hold($this->owner(), $this->reservation(), ['amount' => '100.00'], ['X-Payment-Simulate' => 'failure'])
            ->assertStatus(422)
            ->assertJsonStructure(['success', 'message'])
            ->assertJsonPath('success', false);
    }

    // ══ O. Simulation safety ═══════════════════════════════════

    public function test_simulation_header_is_ignored_outside_local_and_testing(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        // Default directive is success; the header must NOT force a failure.
        $this->hold($this->owner(), $this->reservation(), ['amount' => '100.00'], ['X-Payment-Simulate' => 'failure'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', Payment::STATUS_HOLD_ACTIVE);
    }

    public function test_config_default_directive_also_drives_the_outcome(): void
    {
        config(['payment.providers.dummy.default_directive' => 'failure']);

        $this->hold($this->owner(), $this->reservation(), ['amount' => '100.00'])
            ->assertStatus(422);
    }

    public function test_unknown_simulation_directive_is_a_422_in_testing(): void
    {
        $this->hold($this->owner(), $this->reservation(), ['amount' => '100.00'], ['X-Payment-Simulate' => 'explode'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['simulate']);
    }

    // ══ §34. Provider boundary regression ══════════════════════

    public function test_controller_reaches_the_provider_only_through_the_interface(): void
    {
        // Rebinding the CONTRACT (not the concrete DummyPaymentGateway)
        // is enough to intercept the call — proof the request path is
        // Controller -> PaymentWorkflowService -> PaymentGatewayInterface,
        // never Controller -> DummyPaymentGateway.
        $spy = new RecordingPaymentGateway(GatewayResultStatus::Succeeded);
        $this->app->instance(PaymentGatewayInterface::class, $spy);

        $this->hold($this->owner(), $this->reservation(), ['amount' => '100.00'])->assertStatus(201);

        $this->assertSame(1, $spy->initiateHoldCalls);
    }

    public function test_controller_source_never_names_the_concrete_gateway(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(PaymentController::class))->getFileName()
        );

        $this->assertStringNotContainsString('DummyPaymentGateway', $source);
        $this->assertStringNotContainsString('PaymentGatewayInterface', $source);
        $this->assertStringNotContainsString('DB::', $source);
        $this->assertStringNotContainsString('PaymentStateMachine', $source);
    }
}
