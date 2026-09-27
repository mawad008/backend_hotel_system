<?php

namespace Tests\Feature\Guest;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityVerification\Models\IdentityVerificationAttempt;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\IdentityVerification\Provider\Contracts\IdentityDocumentProviderInterface;
use App\Domain\IdentityVerification\Provider\DummyIdentityDocumentProvider;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\UsesIdentitySpecimen;
use Tests\TestCase;

/**
 * Guest identity verification over HTTP, end to end:
 * upload → OCR (dummy provider, synthetic specimen) → extraction → matching
 * → selfie → verification result → reservation continues (VERIFIED) —
 * plus every document-check failure path.
 */
class GuestIdentityVerificationTest extends TestCase
{
    use UsesIdentitySpecimen;

    private DummyIdentityDocumentProvider $ocr;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['verification.thresholds.auto_approve' => 80, 'verification.max_retries' => 2]);
        $this->useScenario('specimen_passport');
    }

    private function useScenario(string $scenario): void
    {
        $this->ocr = new DummyIdentityDocumentProvider($scenario);
        $this->app->instance(IdentityDocumentProviderInterface::class, $this->ocr);
    }

    private function actingGuest(): Guest
    {
        $guest = Guest::factory()->create();
        $this->withToken($guest->createToken('guest-api')->plainTextToken);

        return $guest;
    }

    private function reservationFor(Guest $guest, string $status = Reservation::STATUS_DEPOSIT_HELD): Reservation
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        return Reservation::factory()->create([
            'guest_id' => $guest->id, 'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => $status,
        ]);
    }

    private function image(string $name = 'x.jpg', int $size = 15): UploadedFile
    {
        return UploadedFile::fake()->image($name, $size, $size);
    }

    private function upload(Reservation $reservation, array $claim = [], ?UploadedFile $file = null, string $type = 'passport')
    {
        return $this->post("/api/v1/guest/reservations/{$reservation->id}/identity/documents", [
            'document' => $file ?? $this->image('doc.jpg'),
            'document_type' => $type,
        ] + $claim + $this->specimenClaimFields());
    }

    private function selfie(Reservation $reservation, string $key = 'k-1')
    {
        return $this->withHeaders(['Idempotency-Key' => $key])
            ->post("/api/v1/guest/reservations/{$reservation->id}/identity/selfie", ['selfie' => $this->image('selfie.jpg')]);
    }

    // ── Authentication / authorization ──────────────────────────────

    public function test_unauthenticated_is_401(): void
    {
        $reservation = Reservation::factory()->create();

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/identity")->assertStatus(401);
    }

    public function test_guest_cannot_reach_another_guests_reservation_identity(): void
    {
        $this->actingGuest();
        $other = Reservation::factory()->create(['status' => Reservation::STATUS_DEPOSIT_HELD]);

        $this->getJson("/api/v1/guest/reservations/{$other->id}/identity")->assertStatus(404);
        $this->upload($other)->assertStatus(404);
        $this->selfie($other)->assertStatus(404);
        $this->assertSame(0, IdentityVerificationAttempt::count());
    }

    public function test_guest_cannot_reach_the_staff_review_action(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->reservationFor($guest);

        $this->postJson("/api/v1/identity-verification/{$reservation->id}/review", ['decision' => 'approved'])
            ->assertStatus(401);
    }

    public function test_status_reflects_the_reservations_session(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->reservationFor($guest);
        IdentityVerificationSession::factory()->autoApproved()->create(['reservation_id' => $reservation->id]);

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/identity")
            ->assertOk()
            ->assertJsonPath('data.status', IdentityVerificationSession::STATUS_AUTO_APPROVED);
    }

    // ── Input validation ────────────────────────────────────────────

    public function test_guest_must_state_document_number_and_birth_date(): void
    {
        $reservation = $this->reservationFor($this->actingGuest());

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/identity/documents", ['document' => $this->image()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['document_number', 'date_of_birth']);
    }

    public function test_a_file_whose_content_is_not_an_image_is_rejected_whatever_its_name(): void
    {
        $reservation = $this->reservationFor($this->actingGuest());
        $fake = UploadedFile::fake()->createWithContent('passport.jpg', "<?php echo 'x'; ?>");

        $this->withHeaders(['Accept' => 'application/json'])->upload($reservation, file: $fake)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['document']);
        $this->assertSame(0, IdentityVerificationAttempt::count());
    }

    public function test_a_pdf_with_active_content_is_rejected(): void
    {
        $reservation = $this->reservationFor($this->actingGuest());
        $pdf = UploadedFile::fake()->createWithContent('id.pdf', "%PDF-1.4\n1 0 obj << /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >> endobj");

        $this->withHeaders(['Accept' => 'application/json'])->upload($reservation, file: $pdf)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['document']);
    }

    public function test_arabic_indic_digits_in_the_claim_are_accepted(): void
    {
        $reservation = $this->reservationFor($this->actingGuest());

        $this->upload($reservation, ['date_of_birth' => '١٩٧٤-٠٨-١٢'])
            ->assertStatus(201)
            ->assertJsonPath('data.document_check.status', 'verified');
    }

    // ── Happy path (E2E) ────────────────────────────────────────────

    public function test_full_journey_upload_ocr_match_selfie_verifies_the_reservation(): void
    {
        $guest = $this->actingGuest();
        $reservation = $this->reservationFor($guest);

        $this->upload($reservation)
            ->assertStatus(201)
            ->assertJsonPath('data.status', IdentityVerificationSession::STATUS_DOCUMENT_UPLOADED)
            ->assertJsonPath('data.document_check.status', 'verified')
            ->assertJsonPath('data.document_check.can_continue', true)
            ->assertJsonPath('data.document_check.document_kind', 'passport')
            ->assertJsonPath('data.document_check.fields.number', 'match')
            ->assertJsonPath('data.document_check.fields.birth', 'match')
            ->assertJsonPath('data.document_check.fields.name', 'strong');

        $this->selfie($reservation)
            ->assertOk()
            ->assertJsonPath('data.status', IdentityVerificationSession::STATUS_AUTO_APPROVED);

        $this->assertSame(Reservation::STATUS_VERIFIED, $reservation->refresh()->status);

        // The provider-side analysis copy was deleted right after extraction.
        $this->assertCount(1, $this->ocr->deleted);
        $this->assertNull(IdentityVerificationAttempt::sole()->provider_artifact_ref);
    }

    public function test_the_stored_document_is_encrypted_at_rest_and_no_pii_is_persisted_or_returned(): void
    {
        $reservation = $this->reservationFor($this->actingGuest());
        $file = $this->image('doc.jpg');
        $plain = file_get_contents($file->getRealPath());

        $response = $this->upload($reservation, file: $file)->assertStatus(201);

        $attempt = IdentityVerificationAttempt::sole();
        $stored = Storage::disk('local')->get($attempt->document_path);

        $this->assertStringEndsWith('.enc', $attempt->document_path);
        $this->assertNotSame($plain, $stored);
        $this->assertStringNotContainsString("\xFF\xD8\xFF", $stored);

        $s = DummyIdentityDocumentProvider::SPECIMEN;
        $blob = json_encode($attempt->getAttributes()).$response->getContent();

        foreach ([$s['document_number'], $s['date_of_birth'], $s['surname'], 'ANNA', '740812', 'P<UTO'] as $pii) {
            $this->assertStringNotContainsString($pii, $blob);
        }
    }

    public function test_the_document_check_log_line_carries_only_safe_metadata(): void
    {
        Log::spy();
        $reservation = $this->reservationFor($this->actingGuest());

        $this->upload($reservation)->assertStatus(201);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []): bool {
            if ($message !== 'identity.document_check') {
                return false;
            }

            $this->assertSame(['attempt_id', 'provider', 'document_type', 'provider_status', 'status', 'reasons', 'duration_ms', 'artifact_deleted'], array_keys($context));
            $flat = json_encode($context);

            foreach (['L898902C3', '1974', 'ERIKSSON', 'ANNA'] as $pii) {
                $this->assertStringNotContainsString($pii, $flat);
            }

            return true;
        })->once();
    }

    // ── Failure paths ───────────────────────────────────────────────

    public function test_mismatched_document_number_is_a_mismatch_the_document_is_deleted_and_the_selfie_is_blocked(): void
    {
        $reservation = $this->reservationFor($this->actingGuest());

        $this->upload($reservation, ['document_number' => 'X1234567'])
            ->assertStatus(201)
            ->assertJsonPath('data.document_check.status', 'mismatch')
            ->assertJsonPath('data.document_check.fields.number', 'mismatch')
            ->assertJsonPath('data.document_check.requires_new_document', true);

        $this->assertNull(IdentityVerificationAttempt::sole()->document_path);
        $this->assertSame([], Storage::disk('local')->allFiles('identity-verification'));

        $this->selfie($reservation)->assertStatus(422);
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $reservation->refresh()->status);
    }

    public function test_mismatch_then_corrected_claim_verifies(): void
    {
        $reservation = $this->reservationFor($this->actingGuest());

        $this->upload($reservation, ['date_of_birth' => '1975-08-12'])
            ->assertJsonPath('data.document_check.status', 'mismatch')
            ->assertJsonPath('data.document_check.fields.birth', 'mismatch');

        $this->upload($reservation)
            ->assertJsonPath('data.document_check.status', 'verified');

        $this->selfie($reservation)->assertJsonPath('data.status', IdentityVerificationSession::STATUS_AUTO_APPROVED);
    }

    public function test_a_different_name_is_a_mismatch_but_arabic_script_goes_to_review(): void
    {
        $reservation = $this->reservationFor($this->actingGuest());

        $this->upload($reservation, ['full_name' => 'John Smith'])
            ->assertJsonPath('data.document_check.status', 'mismatch')
            ->assertJsonPath('data.document_check.fields.name', 'mismatch');

        $this->upload($reservation, ['full_name' => 'آنا ماريا إريكسون'])
            ->assertJsonPath('data.document_check.status', 'needs_review')
            ->assertJsonPath('data.document_check.fields.name', 'unverifiable_script');
    }

    public function test_needs_review_document_can_never_auto_approve(): void
    {
        $this->useScenario('bad_mrz_passport');
        $reservation = $this->reservationFor($this->actingGuest());

        $this->upload($reservation)
            ->assertJsonPath('data.document_check.status', 'needs_review')
            ->assertJsonPath('data.document_check.can_continue', true);

        // A perfect face match still ends in manual review.
        $this->withHeaders(['X-Identity-Simulate' => 'high_match'])->selfie($reservation)
            ->assertOk()
            ->assertJsonPath('data.status', IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW);
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $reservation->refresh()->status);
    }

    public function test_expired_document(): void
    {
        $this->useScenario('expired_passport');
        $reservation = $this->reservationFor($this->actingGuest());

        $this->upload($reservation)
            ->assertJsonPath('data.document_check.status', 'document_expired')
            ->assertJsonPath('data.document_check.fields.expiry', 'expired');
        $this->selfie($reservation)->assertStatus(422);
    }

    public function test_unsupported_document(): void
    {
        $this->useScenario('driver_license');
        $reservation = $this->reservationFor($this->actingGuest());

        $this->upload($reservation)
            ->assertJsonPath('data.document_check.status', 'document_unsupported')
            ->assertJsonPath('data.document_check.reasons.0', 'document_kind_unsupported');
    }

    public function test_ocr_failure_when_no_document_is_found(): void
    {
        $this->useScenario('no_document');
        $reservation = $this->reservationFor($this->actingGuest());

        $this->upload($reservation)
            ->assertJsonPath('data.document_check.status', 'ocr_failed')
            ->assertJsonPath('data.document_check.reasons.0', 'ocr_no_document')
            ->assertJsonPath('data.document_check.requires_new_document', true);
    }

    public function test_ocr_failure_when_the_provider_times_out(): void
    {
        $this->useScenario('provider_timeout');
        $reservation = $this->reservationFor($this->actingGuest());

        $this->upload($reservation)
            ->assertJsonPath('data.document_check.status', 'ocr_failed')
            ->assertJsonPath('data.document_check.reasons.0', 'ocr_timeout');
    }

    public function test_national_id_without_mrz_is_read_but_needs_review(): void
    {
        $this->useScenario('national_id_no_mrz');
        $reservation = $this->reservationFor($this->actingGuest());

        // Legacy generic route (older app builds sent `national_id`).
        $this->upload($reservation, type: 'national_id')
            ->assertJsonPath('data.document_check.status', 'needs_review')
            ->assertJsonPath('data.document_check.fields.number', 'match')
            ->assertJsonPath('data.document_check.reasons.0', 'no_mrz_auto_verification_unavailable');
    }

    // ── Duplicate upload / cost cap ─────────────────────────────────

    public function test_identical_reupload_returns_the_stored_result_without_a_second_ocr_call(): void
    {
        $reservation = $this->reservationFor($this->actingGuest());
        $file = $this->image('doc.jpg');

        $this->upload($reservation, file: $file)->assertJsonPath('data.document_check.status', 'verified');
        $this->upload($reservation, file: $file)->assertJsonPath('data.document_check.status', 'verified');

        $this->assertCount(1, $this->ocr->deleted, 'the provider was called exactly once');
        $this->assertSame(1, IdentityVerificationAttempt::sole()->document_uploads);
    }

    public function test_after_the_upload_cap_a_failing_document_goes_to_a_human(): void
    {
        config(['verification.document_check.max_uploads_per_attempt' => 2]);
        $reservation = $this->reservationFor($this->actingGuest());

        $this->upload($reservation, ['document_number' => 'X1111111'])
            ->assertJsonPath('data.document_check.status', 'mismatch')
            ->assertJsonPath('data.document_check.uploads_remaining', 1);

        $this->upload($reservation, ['document_number' => 'X2222222'])
            ->assertJsonPath('data.document_check.status', 'needs_review')
            ->assertJsonPath('data.document_check.reasons.0', 'claim_mismatch');

        $this->withHeaders(['X-Identity-Simulate' => 'high_match'])->selfie($reservation)
            ->assertJsonPath('data.status', IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW);
    }
}
