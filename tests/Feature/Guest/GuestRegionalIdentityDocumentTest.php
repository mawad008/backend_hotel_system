<?php

namespace Tests\Feature\Guest;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityVerification\Models\IdentityVerificationAttempt;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\IdentityVerification\Provider\AzureDocumentIntelligenceProvider;
use App\Domain\IdentityVerification\Provider\Contracts\IdentityDocumentProviderInterface;
use App\Domain\IdentityVerification\Provider\DocumentProviderRouter;
use App\Domain\IdentityVerification\Provider\DummyIdentityDocumentProvider;
use App\Domain\IdentityVerification\DocumentCheck\IdentityDocumentCatalog;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Egyptian National ID / Saudi National ID / Saudi Iqama over the guest API:
 * type selection, front/back images, per-type validation, every
 * document-check outcome, configuration safety, storage and retention.
 * The OCR provider is the deterministic dummy (synthetic specimens) except
 * where the REAL router is bound to prove there is no dummy fallback.
 */
class GuestRegionalIdentityDocumentTest extends TestCase
{
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

    private function reservation(?Guest $guest = null): Reservation
    {
        $guest ??= Guest::factory()->create();
        $this->withToken($guest->createToken('guest-api')->plainTextToken);
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        return Reservation::factory()->create([
            'guest_id' => $guest->id, 'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_DEPOSIT_HELD,
        ]);
    }

    private function img(string $name, int $size = 40): UploadedFile
    {
        return UploadedFile::fake()->image($name, $size, $size);
    }

    private function egypt(array $over = []): array
    {
        return $over + [
            'document_type' => 'egyptian_national_id',
            'front_image' => $this->img('front.jpg'),
            'back_image' => $this->img('back.jpg', 41),
            'full_name' => 'سامي عادل فؤاد منصور',
            'document_number' => '٢٩٠٠١١٥٠١١٢٣٥٧',
        ];
    }

    private function saudiId(array $over = []): array
    {
        return $over + [
            'document_type' => 'saudi_national_id',
            'front_image' => $this->img('front.jpg'),
            'full_name' => 'فهد سالم ناصر الحربي',
            'document_number' => '1098765432',
            'date_of_birth' => '1989-10-16',
        ];
    }

    private function iqama(array $over = []): array
    {
        return $over + [
            'document_type' => 'saudi_iqama',
            'front_image' => $this->img('front.jpg'),
            'full_name' => 'Rajeev Kumar Sharma',
            'document_number' => '2098765432',
            'date_of_birth' => '1985-06-20',
            'nationality' => 'IND',
        ];
    }

    private function upload(Reservation $r, array $payload)
    {
        return $this->withHeaders(['Accept' => 'application/json'])
            ->post("/api/v1/guest/reservations/{$r->id}/identity/documents", $payload);
    }

    private function selfie(Reservation $r)
    {
        return $this->withHeaders(['Idempotency-Key' => 'k-'.$r->id, 'X-Identity-Simulate' => 'high_match'])
            ->post("/api/v1/guest/reservations/{$r->id}/identity/selfie", ['selfie' => $this->img('selfie.jpg')]);
    }

    // ── Catalog ─────────────────────────────────────────────────────

    public function test_guest_catalog_lists_the_types_and_sides_without_provider_detail(): void
    {
        $this->reservation();

        $res = $this->getJson('/api/v1/guest/identity/document-types')->assertOk();

        $this->assertSame(['egyptian_national_id', 'saudi_national_id', 'saudi_iqama', 'passport'], array_column($res->json('data'), 'type'));
        $res->assertJsonPath('data.0.back_image', 'required')
            ->assertJsonPath('data.0.country', 'EGY')
            ->assertJsonPath('data.0.date_of_birth_in_number', true)
            ->assertJsonPath('data.0.automatic_check', false)
            ->assertJsonPath('data.3.back_image', 'none');

        foreach (['provider', 'model', 'key', 'endpoint', 'azure'] as $secretish) {
            $this->assertStringNotContainsStringIgnoringCase($secretish, $res->getContent());
        }
    }

    public function test_staff_catalog_shows_route_configuration_but_never_secrets(): void
    {
        config([
            'verification.document_types.egyptian_national_id.model' => 'egypt-id-v3',
            'verification.document_providers.azure_document_intelligence.key' => 'SUPER-SECRET-KEY',
        ]);

        $res = $this->actingAs(User::factory()->groupOwner()->create(), 'sanctum')
            ->getJson('/api/v1/identity-verification/document-types')->assertOk();

        $egypt = collect($res->json('data'))->firstWhere('type', 'egyptian_national_id');
        $this->assertSame('egypt-id-v3', $egypt['model']);
        $this->assertTrue($egypt['model_configured']);
        $this->assertTrue($egypt['manual_review_required']);
        $this->assertFalse(collect($res->json('data'))->firstWhere('type', 'saudi_iqama')['model_configured']);
        $this->assertStringNotContainsString('SUPER-SECRET-KEY', $res->getContent());
    }

    public function test_staff_catalog_requires_a_permitted_staff_user(): void
    {
        $this->getJson('/api/v1/identity-verification/document-types')->assertStatus(401);

        $this->actingAs(User::factory()->guest()->create(), 'sanctum')
            ->getJson('/api/v1/identity-verification/document-types')->assertStatus(403);
    }

    // ── Validation ──────────────────────────────────────────────────

    public function test_egypt_requires_the_back_image_but_not_a_typed_birth_date(): void
    {
        $r = $this->reservation();

        $payload = $this->egypt();
        unset($payload['back_image']);
        $this->upload($r, $payload)->assertStatus(422)->assertJsonValidationErrors(['back_image']);

        $this->upload($r, $this->egypt())->assertStatus(201);
    }

    public function test_a_passport_refuses_a_back_image(): void
    {
        $r = $this->reservation();

        $this->upload($r, [
            'document_type' => 'passport', 'front_image' => $this->img('p.jpg'), 'back_image' => $this->img('b.jpg'),
            'document_number' => 'L898902C3', 'date_of_birth' => '1974-08-12',
        ])->assertStatus(422)->assertJsonValidationErrors(['back_image']);
    }

    public function test_structurally_impossible_numbers_are_rejected_before_any_ocr(): void
    {
        $r = $this->reservation();

        $this->upload($r, $this->egypt(['document_number' => '12345678901234']))->assertStatus(422)->assertJsonValidationErrors(['document_number']);
        $this->upload($r, $this->saudiId(['document_number' => '2098765432']))->assertStatus(422)->assertJsonValidationErrors(['document_number']);
        $this->upload($r, $this->iqama(['document_number' => '1098765432']))->assertStatus(422)->assertJsonValidationErrors(['document_number']);
        $this->assertSame(0, IdentityVerificationAttempt::count());
        $this->assertSame([], $this->ocr->deleted, 'no OCR ran');
    }

    public function test_country_must_agree_and_unknown_or_disabled_types_are_refused(): void
    {
        $r = $this->reservation();

        $this->upload($r, $this->egypt(['country' => 'SAU']))->assertStatus(422)->assertJsonValidationErrors(['country']);
        $this->upload($r, $this->egypt(['document_type' => 'kuwaiti_civil_id']))->assertStatus(422)->assertJsonValidationErrors(['document_type']);

        config(['verification.document_types.saudi_iqama.enabled' => false]);
        $this->upload($r, $this->iqama())->assertStatus(422)->assertJsonValidationErrors(['document_type']);
    }

    public function test_a_fake_back_image_is_rejected_by_content(): void
    {
        $r = $this->reservation();

        $this->upload($r, $this->egypt(['back_image' => UploadedFile::fake()->createWithContent('back.jpg', 'GIF89a not a jpeg')]))
            ->assertStatus(422)->assertJsonValidationErrors(['back_image']);
    }

    public function test_another_guests_reservation_is_404_even_with_a_valid_payload(): void
    {
        $other = $this->reservation();
        $this->reservation(); // switches the token to a different guest

        $this->upload($other, $this->egypt())->assertStatus(404);
        $this->assertSame(0, IdentityVerificationAttempt::count());
    }

    // ── Outcomes ────────────────────────────────────────────────────

    public function test_egypt_matching_document_is_manual_review_by_default(): void
    {
        $r = $this->reservation();

        $this->upload($r, $this->egypt())
            ->assertStatus(201)
            ->assertJsonPath('data.document_check.status', 'needs_review')
            ->assertJsonPath('data.document_check.document_type', 'egyptian_national_id')
            ->assertJsonPath('data.document_check.back_image', true)
            ->assertJsonPath('data.document_check.reasons', ['manual_review_required_for_document_type'])
            ->assertJsonPath('data.document_check.fields.birth', 'match')
            ->assertJsonMissingPath('data.document_check.provider');

        // Even a perfect face match cannot auto-approve.
        $this->selfie($r)->assertJsonPath('data.status', IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW);
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $r->refresh()->status);
    }

    public function test_egypt_verified_when_auto_verify_is_enabled_and_the_reservation_continues(): void
    {
        config(['verification.document_types.egyptian_national_id.auto_verify' => true]);
        $r = $this->reservation();

        $this->upload($r, $this->egypt())->assertJsonPath('data.document_check.status', 'verified');
        $this->selfie($r)->assertJsonPath('data.status', IdentityVerificationSession::STATUS_AUTO_APPROVED);
        $this->assertSame(Reservation::STATUS_VERIFIED, $r->refresh()->status);
    }

    public function test_saudi_id_verified_when_enabled(): void
    {
        config(['verification.document_types.saudi_national_id.auto_verify' => true]);

        $this->upload($this->reservation(), $this->saudiId())
            ->assertJsonPath('data.document_check.status', 'verified')
            ->assertJsonPath('data.document_check.document_type', 'saudi_national_id');
    }

    public function test_iqama_verified_when_enabled_with_a_latin_name_claim(): void
    {
        config(['verification.document_types.saudi_iqama.auto_verify' => true]);

        $this->upload($this->reservation(), $this->iqama())
            ->assertJsonPath('data.document_check.status', 'verified')
            ->assertJsonPath('data.document_check.fields.nationality', 'match')
            ->assertJsonPath('data.document_check.fields.name', 'strong');
    }

    public function test_mismatch_deletes_front_and_back_and_blocks_the_selfie(): void
    {
        $r = $this->reservation();

        $this->upload($r, $this->egypt(['full_name' => 'خالد يوسف ابراهيم']))
            ->assertJsonPath('data.document_check.status', 'mismatch')
            ->assertJsonPath('data.document_check.fields.name', 'mismatch');

        $attempt = IdentityVerificationAttempt::sole();
        $this->assertNull($attempt->document_path);
        $this->assertNull($attempt->document_back_path);
        $this->assertSame([], Storage::disk('local')->allFiles('identity-verification'));
        $this->selfie($r)->assertStatus(422);
    }

    public function test_wrong_dob_on_saudi_id_is_a_mismatch(): void
    {
        $this->upload($this->reservation(), $this->saudiId(['date_of_birth' => '1989-10-17']))
            ->assertJsonPath('data.document_check.status', 'mismatch')
            ->assertJsonPath('data.document_check.fields.birth', 'mismatch');
    }

    public function test_expired_unsupported_and_unreadable(): void
    {
        $this->useScenario('expired_document');
        $this->upload($this->reservation(), $this->iqama())->assertJsonPath('data.document_check.status', 'document_expired');
    }

    public function test_unsupported_document(): void
    {
        $this->useScenario('driver_license');
        $this->upload($this->reservation(), $this->saudiId())->assertJsonPath('data.document_check.status', 'document_unsupported');
    }

    public function test_unreadable_document(): void
    {
        $this->useScenario('no_document');
        $this->upload($this->reservation(), $this->egypt())
            ->assertJsonPath('data.document_check.status', 'ocr_failed')
            ->assertJsonPath('data.document_check.requires_new_document', true);
    }

    // ── Storage / duplicates / retention ────────────────────────────

    public function test_back_image_is_encrypted_at_rest(): void
    {
        $r = $this->reservation();
        $back = $this->img('back.jpg', 41);
        $plain = file_get_contents($back->getRealPath());

        $this->upload($r, $this->egypt(['back_image' => $back]))->assertStatus(201);

        $path = IdentityVerificationAttempt::sole()->document_back_path;
        $this->assertMatchesRegularExpression('#^identity-verification/\d+/\d+-document_back-[A-Za-z0-9]{20}\.enc$#', $path);
        $this->assertNotSame($plain, Storage::disk('local')->get($path));
    }

    public function test_identical_front_back_and_claim_is_not_ocr_charged_twice(): void
    {
        $r = $this->reservation();
        $front = $this->img('front.jpg');
        $back = $this->img('back.jpg', 41);

        $this->upload($r, $this->egypt(['front_image' => $front, 'back_image' => $back]))->assertStatus(201);
        $this->upload($r, $this->egypt(['front_image' => $front, 'back_image' => $back]))->assertStatus(201);

        $this->assertCount(1, $this->ocr->deleted);
        $this->assertSame(1, IdentityVerificationAttempt::sole()->document_uploads);
    }

    public function test_retention_purges_the_back_image_too(): void
    {
        $r = $this->reservation();
        $this->upload($r, $this->egypt())->assertStatus(201);
        $attempt = IdentityVerificationAttempt::sole();
        $paths = [$attempt->document_path, $attempt->document_back_path];

        Reservation::query()->whereKey($r->id)->update(['status' => Reservation::STATUS_CHECKED_OUT, 'updated_at' => now()->subDays(31)]);
        Artisan::call('identity:purge-expired-images');

        foreach ($paths as $p) {
            Storage::disk('local')->assertMissing($p);
        }
        $this->assertNull($attempt->refresh()->document_back_path);
    }

    // ── Real router: no dummy fallback ──────────────────────────────

    public function test_real_router_with_a_missing_custom_model_never_verifies_and_never_calls_azure(): void
    {
        config(['verification.document_types.egyptian_national_id.model' => null]);
        $http = new Factory;
        $http->fake();
        $this->app->instance(IdentityDocumentProviderInterface::class, new DocumentProviderRouter(
            new AzureDocumentIntelligenceProvider($http, 'https://unit.cognitiveservices.azure.com', 'k'),
            new IdentityDocumentCatalog,
        ));
        Log::spy();
        $r = $this->reservation();

        $this->upload($r, $this->egypt())
            ->assertStatus(201)
            ->assertJsonPath('data.document_check.status', 'needs_review')
            ->assertJsonPath('data.document_check.reasons', ['provider_not_configured'])
            ->assertJsonPath('data.document_check.can_continue', true);

        $http->assertNothingSent();
        Log::shouldHaveReceived('error')->withArgs(fn (string $m, array $c = []) => $m === 'identity.document_check'
            && $c['provider_status'] === 'model_not_configured' && $c['document_type'] === 'egyptian_national_id')->once();

        $this->selfie($r)->assertJsonPath('data.status', IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW);
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $r->refresh()->status);
    }

    public function test_the_config_status_command_fails_loudly_for_missing_production_models(): void
    {
        config(['verification.document_provider' => 'azure_document_intelligence']);

        $this->assertSame(1, Artisan::call('identity:document-providers'));
        $this->assertStringContainsString('MISSING', Artisan::output());
    }
}
