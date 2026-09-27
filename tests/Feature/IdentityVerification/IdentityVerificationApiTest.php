<?php

namespace Tests\Feature\IdentityVerification;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use App\Domain\IdentityVerification\Provider\Contracts\IdentityDocumentProviderInterface;
use App\Domain\IdentityVerification\Provider\DummyIdentityDocumentProvider;
use Tests\Concerns\UsesIdentitySpecimen;
use Tests\TestCase;

/**
 * Phase 6 — the identity verification HTTP surface
 * (documents / selfie / status / review).
 */
class IdentityVerificationApiTest extends TestCase
{
    use UsesIdentitySpecimen;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['verification.thresholds.auto_approve' => 80, 'verification.max_retries' => 2]);
    }

    private function reservation(string $status = Reservation::STATUS_DEPOSIT_HELD, ?Hotel $hotel = null): Reservation
    {
        $hotel ??= Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        return Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => $status,
        ]);
    }

    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    private function image(string $name = 'x.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 15, 15);
    }

    private function postDocument(User $actor, Reservation $reservation, array $data = [], array $headers = []): TestResponse
    {
        return $this->actingAs($actor, 'sanctum')->withHeaders($headers)->post(
            "/api/v1/identity-verification/{$reservation->id}/documents",
            $data + ['document' => $this->image('doc.jpg')] + $this->specimenClaimFields(),
        );
    }

    private function postSelfie(User $actor, Reservation $reservation, array $data = [], array $headers = []): TestResponse
    {
        return $this->actingAs($actor, 'sanctum')->withHeaders($headers)->post(
            "/api/v1/identity-verification/{$reservation->id}/selfie",
            $data + ['selfie' => $this->image('selfie.jpg')],
        );
    }

    private function driveToPending(User $actor, Reservation $reservation): void
    {
        $this->postDocument($actor, $reservation)->assertStatus(201);
        $this->postSelfie($actor, $reservation, [], ['X-Identity-Simulate' => 'low_match'])->assertOk();
    }

    // ── A. Authentication ───────────────────────────────────────────

    public function test_unauthenticated_document_submission_is_rejected(): void
    {
        $reservation = $this->reservation();

        $this->postJson("/api/v1/identity-verification/{$reservation->id}/documents")
            ->assertStatus(401);

        $this->assertDatabaseCount('identity_verification_sessions', 0);
    }

    public function test_unauthenticated_status_is_rejected(): void
    {
        $reservation = $this->reservation();

        $this->getJson("/api/v1/identity-verification/{$reservation->id}/status")->assertStatus(401);
    }

    // ── B. Authorization / hotel scope ─────────────────────────────

    public function test_assigned_hotel_manager_can_submit(): void
    {
        $hotel = Hotel::factory()->create();
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        $this->postDocument($manager, $this->reservation(hotel: $hotel))->assertStatus(201);
    }

    public function test_reception_can_submit_and_review(): void
    {
        $hotel = Hotel::factory()->create();
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);
        $reservation = $this->reservation(hotel: $hotel);

        $this->driveToPending($reception, $reservation);

        $this->actingAs($reception, 'sanctum')
            ->postJson("/api/v1/identity-verification/{$reservation->id}/review", ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', IdentityVerificationSession::STATUS_STAFF_APPROVED);
    }

    public function test_guest_role_user_gets_a_scope_404(): void
    {
        $this->postDocument(User::factory()->guest()->create(), $this->reservation())
            ->assertStatus(404);

        $this->assertDatabaseCount('identity_verification_sessions', 0);
    }

    public function test_manager_in_an_unassigned_hotel_gets_a_scope_404(): void
    {
        $assigned = Hotel::factory()->create();
        $other = Hotel::factory()->create();
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($assigned);

        $this->postDocument($manager, $this->reservation(hotel: $other))->assertStatus(404);
    }

    public function test_nonexistent_reservation_returns_404(): void
    {
        $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/identity-verification/999999/status')
            ->assertStatus(404);
    }

    // ── C. Validation ─────────────────────────────────────────────

    public function test_document_is_required(): void
    {
        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/identity-verification/{$this->reservation()->id}/documents", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['document']);
    }

    public function test_document_must_be_an_accepted_type(): void
    {
        $this->postDocument($this->owner(), $this->reservation(), [
            'document' => UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload'),
        ])->assertStatus(422)->assertJsonValidationErrors(['document']);
    }

    public function test_oversized_document_is_rejected(): void
    {
        config(['verification.storage.max_file_kb' => 1]);

        $this->postDocument($this->owner(), $this->reservation(), [
            'document' => UploadedFile::fake()->create('big.pdf', 2048, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors(['document']);
    }

    public function test_selfie_must_be_an_image(): void
    {
        $owner = $this->owner();
        $reservation = $this->reservation();
        $this->postDocument($owner, $reservation)->assertStatus(201);

        $this->postSelfie($owner, $reservation, [
            'selfie' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors(['selfie']);
    }

    public function test_review_decision_must_be_valid(): void
    {
        $owner = $this->owner();
        $reservation = $this->reservation();
        $this->driveToPending($owner, $reservation);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/identity-verification/{$reservation->id}/review", ['decision' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['decision']);
    }

    // ── D. Happy path ────────────────────────────────────────────

    public function test_document_then_selfie_auto_approves_and_verifies_reservation(): void
    {
        $owner = $this->owner();
        $reservation = $this->reservation();

        $this->postDocument($owner, $reservation)
            ->assertStatus(201)
            ->assertJsonPath('data.status', IdentityVerificationSession::STATUS_DOCUMENT_UPLOADED);

        $this->postSelfie($owner, $reservation, [], ['X-Identity-Simulate' => 'high_match'])
            ->assertOk()
            ->assertJsonPath('data.status', IdentityVerificationSession::STATUS_AUTO_APPROVED);

        $this->assertSame(Reservation::STATUS_VERIFIED, $reservation->fresh()->status);
    }

    public function test_status_endpoint_returns_not_started_when_nothing_exists(): void
    {
        $reservation = $this->reservation();

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/identity-verification/{$reservation->id}/status")
            ->assertOk()
            ->assertJsonPath('data.status', IdentityVerificationSession::STATUS_NOT_STARTED)
            ->assertJsonPath('data.attempts', 0);
    }

    public function test_low_match_routes_to_manual_review_then_staff_can_approve(): void
    {
        $owner = $this->owner();
        $reservation = $this->reservation();
        $this->driveToPending($owner, $reservation);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/identity-verification/{$reservation->id}/status")
            ->assertOk()
            ->assertJsonPath('data.status', IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW)
            ->assertJsonPath('data.latest_decision.result', 'manual_review_required');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/identity-verification/{$reservation->id}/review", ['decision' => 'approve', 'reason' => 'verified in person'])
            ->assertOk()
            ->assertJsonPath('data.status', IdentityVerificationSession::STATUS_STAFF_APPROVED);

        $this->assertSame(Reservation::STATUS_VERIFIED, $reservation->fresh()->status);
    }

    // ── E. Invalid state ────────────────────────────────────────

    public function test_document_on_a_non_deposit_held_reservation_is_a_422(): void
    {
        $this->postDocument($this->owner(), $this->reservation(Reservation::STATUS_PENDING))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_selfie_before_a_document_is_a_422(): void
    {
        $this->postSelfie($this->owner(), $this->reservation())
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_review_when_not_pending_is_a_422(): void
    {
        $owner = $this->owner();
        $reservation = $this->reservation();
        $this->postDocument($owner, $reservation)->assertStatus(201);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/identity-verification/{$reservation->id}/review", ['decision' => 'approve'])
            ->assertStatus(422);
    }

    // ── F. Idempotency ─────────────────────────────────────────

    public function test_same_idempotency_key_replays_the_selfie(): void
    {
        $owner = $this->owner();
        $reservation = $this->reservation();
        $this->postDocument($owner, $reservation)->assertStatus(201);

        $headers = ['Idempotency-Key' => 'idv-1', 'X-Identity-Simulate' => 'high_match'];
        $first = $this->postSelfie($owner, $reservation, [], $headers)->assertOk();
        $second = $this->postSelfie($owner, $reservation, [], $headers)->assertOk();

        $this->assertSame($first->json('data.status'), $second->json('data.status'));
        $this->assertDatabaseCount('identity_verification_attempts', 1);
    }

    // ── G. Security ─────────────────────────────────────────────

    public function test_response_never_exposes_storage_paths_or_provider_internals(): void
    {
        $owner = $this->owner();
        $reservation = $this->reservation();
        $this->postDocument($owner, $reservation)->assertStatus(201);

        $body = $this->postSelfie($owner, $reservation, [], ['X-Identity-Simulate' => 'high_match'])->getContent();

        foreach (['document_path', 'selfie_path', 'identity-verification/', '.jpg', 'DummyIdentityVerificationProvider', 'SQLSTATE', '.php:'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "response leaked '{$needle}'");
        }
    }

    public function test_client_supplied_hotel_id_is_ignored(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->reservation(hotel: $hotel);

        $this->postDocument($this->owner(), $reservation, ['hotel_id' => Hotel::factory()->create()->id])
            ->assertStatus(201)
            ->assertJsonPath('data.hotel_id', $hotel->id);
    }

    // ── H. Simulation safety ───────────────────────────────────

    public function test_simulation_header_is_ignored_outside_local_and_testing(): void
    {
        // The dummy OCR provider is refused in production; bind it explicitly
        // so this test isolates the simulation-header rule.
        $this->app->instance(IdentityDocumentProviderInterface::class, new DummyIdentityDocumentProvider);
        $this->app->detectEnvironment(fn () => 'production');

        $owner = $this->owner();
        $reservation = $this->reservation();
        $this->postDocument($owner, $reservation)->assertStatus(201);

        // Default directive is high_match; the header must not force low_match.
        $this->postSelfie($owner, $reservation, [], ['X-Identity-Simulate' => 'low_match'])
            ->assertOk()
            ->assertJsonPath('data.status', IdentityVerificationSession::STATUS_AUTO_APPROVED);
    }

    public function test_unknown_simulation_directive_is_a_422_in_testing(): void
    {
        $owner = $this->owner();
        $reservation = $this->reservation();
        $this->postDocument($owner, $reservation)->assertStatus(201);

        $this->postSelfie($owner, $reservation, [], ['X-Identity-Simulate' => 'explode'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['simulate']);
    }
}
