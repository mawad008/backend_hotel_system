<?php

namespace Tests\Feature\Guest;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityVerification\Models\IdentityVerificationAttempt;
use App\Domain\IdentityVerification\Models\IdentityVerificationDecision;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\IdentityVerification\Provider\Contracts\IdentityDocumentProviderInterface;
use App\Domain\IdentityVerification\Provider\Contracts\IdentityVerificationProviderInterface;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Document-only mode (config `verification.document_only`): the guest only
 * photographs the ID — no typed details, no OCR, no selfie — and the
 * reservation continues (VERIFIED).
 */
class GuestIdentityDocumentOnlyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['verification.document_only' => true]);

        // Neither provider may be called in this mode.
        $this->mock(IdentityDocumentProviderInterface::class)->shouldNotReceive('extract');
        $this->mock(IdentityVerificationProviderInterface::class)->shouldNotReceive('verify');
    }

    private function reservation(string $status = Reservation::STATUS_DEPOSIT_HELD): Reservation
    {
        $guest = Guest::factory()->create();
        $this->withToken($guest->createToken('guest-api')->plainTextToken);
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        return Reservation::factory()->create([
            'guest_id' => $guest->id, 'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => $status,
        ]);
    }

    public function test_photo_alone_approves_identity_and_verifies_the_reservation(): void
    {
        $reservation = $this->reservation();

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/identity/documents", [
            'front_image' => UploadedFile::fake()->image('id.jpg', 40, 40),
            'document_type' => 'passport',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', IdentityVerificationSession::STATUS_AUTO_APPROVED)
            ->assertJsonMissingPath('data.document_check');

        $this->assertSame(Reservation::STATUS_VERIFIED, $reservation->fresh()->status);

        $attempt = IdentityVerificationAttempt::sole();
        $this->assertSame(IdentityVerificationAttempt::STATUS_COMPLETED, $attempt->status);
        $this->assertNotNull($attempt->document_path);
        $this->assertNull($attempt->document_check_status);
        Storage::disk('local')->assertExists($attempt->document_path);

        $decision = IdentityVerificationDecision::sole();
        $this->assertSame(IdentityVerificationDecision::RESULT_AUTO_APPROVED, $decision->result);
        $this->assertSame('document_only', $decision->reason);
    }

    public function test_back_image_policy_still_applies(): void
    {
        $reservation = $this->reservation();

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/identity/documents", [
            'front_image' => UploadedFile::fake()->image('front.jpg', 40, 40),
            'document_type' => 'egyptian_national_id',
        ])->assertStatus(422)->assertJsonValidationErrors(['back_image']);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/identity/documents", [
            'front_image' => UploadedFile::fake()->image('front.jpg', 40, 40),
            'back_image' => UploadedFile::fake()->image('back.jpg', 40, 40),
            'document_type' => 'egyptian_national_id',
        ])->assertStatus(201)->assertJsonPath('data.status', IdentityVerificationSession::STATUS_AUTO_APPROVED);
    }

    public function test_reservation_must_still_hold_the_deposit(): void
    {
        $reservation = $this->reservation(Reservation::STATUS_PENDING);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/identity/documents", [
            'front_image' => UploadedFile::fake()->image('id.jpg', 40, 40),
            'document_type' => 'passport',
        ])->assertStatus(422);

        $this->assertSame(Reservation::STATUS_PENDING, $reservation->fresh()->status);
    }

    public function test_document_types_advertise_the_mode(): void
    {
        $this->reservation();

        $this->getJson('/api/v1/guest/identity/document-types')
            ->assertOk()
            ->assertJsonPath('data.0.details_required', false)
            ->assertJsonPath('data.0.selfie_required', false)
            ->assertJsonPath('data.0.automatic_check', true);
    }
}
