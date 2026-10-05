<?php

namespace Tests\Feature\Guest;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityVerification\DocumentCheck\IdentityClaim;
use App\Domain\IdentityVerification\DocumentCheck\IdentityDocumentType;
use App\Domain\IdentityVerification\Provider\Exceptions\UnsupportedIdentityVerificationProviderException;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\IdentityVerification\Provider\Contracts\IdentityDocumentProviderInterface;
use App\Domain\IdentityVerification\Provider\DummyIdentityDocumentProvider;
use App\Domain\IdentityVerification\Services\IdentityDocumentCheckService;
use App\Domain\IdentityVerification\Support\IdentityFileStore;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The dummy `egyptian_id_fixture` scenario, end to end over the guest API
 * (Flutter → backend) and the staff review endpoint (dashboard): no OCR, a
 * fixed Egyptian National ID fixture, the REAL evaluator comparing it with
 * the guest's claim. Synthetic identity only.
 */
class GuestEgyptianIdFixtureTest extends TestCase
{
    private const NAME = 'سامي عادل فؤاد منصور';

    private const NUMBER = '29001150112357';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'verification.document_provider' => 'dummy',
            'verification.document_providers.dummy.scenario' => DummyIdentityDocumentProvider::SCENARIO_EGYPTIAN_ID_FIXTURE,
            'verification.thresholds.auto_approve' => 80,
            'verification.max_retries' => 2,
        ]);
        // Rebuild the singleton from the config above (the real binding).
        $this->app->forgetInstance(IdentityDocumentProviderInterface::class);
    }

    private function reservation(): Reservation
    {
        $guest = Guest::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->withToken($guest->createToken('guest-api')->plainTextToken);
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        return Reservation::factory()->create([
            'guest_id' => $guest->id, 'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_DEPOSIT_HELD,
        ]);
    }

    private function upload(Reservation $r, array $over = [])
    {
        return $this->withHeaders(['Accept' => 'application/json'])
            ->post("/api/v1/guest/reservations/{$r->id}/identity/documents", $over + [
                'document_type' => 'egyptian_national_id',
                'front_image' => UploadedFile::fake()->image('front.jpg', 40, 40),
                'back_image' => UploadedFile::fake()->image('back.jpg', 41, 41),
                'full_name' => self::NAME,
                'document_number' => self::NUMBER,
            ]);
    }

    private function selfie(Reservation $r)
    {
        return $this->withHeaders(['Accept' => 'application/json', 'Idempotency-Key' => 'k-'.$r->id])
            ->post("/api/v1/guest/reservations/{$r->id}/identity/selfie", ['selfie' => UploadedFile::fake()->image('selfie.jpg', 40, 40)]);
    }

    public function test_the_real_binding_serves_the_fixture_outside_production(): void
    {
        $provider = $this->app->make(IdentityDocumentProviderInterface::class);

        $this->assertInstanceOf(DummyIdentityDocumentProvider::class, $provider);
    }

    public function test_exact_match_verifies_the_document_and_the_selfie_auto_approves_the_reservation(): void
    {
        $r = $this->reservation();

        $this->upload($r)->assertCreated()
            ->assertJsonPath('data.document_check.status', 'verified')
            ->assertJsonPath('data.document_check.fields.number', 'match')
            ->assertJsonPath('data.document_check.fields.name', 'strong')
            ->assertJsonPath('data.document_check.can_continue', true);

        $this->selfie($r)->assertOk()
            ->assertJsonPath('data.status', IdentityVerificationSession::STATUS_AUTO_APPROVED);

        $this->assertSame(Reservation::STATUS_VERIFIED, $r->fresh()->status);
    }

    public function test_arabic_digits_and_the_triple_name_also_verify(): void
    {
        $r = $this->reservation();

        $this->upload($r, ['full_name' => 'سامي عادل فؤاد', 'document_number' => '٢٩٠٠١١٥٠١١٢٣٥٧'])
            ->assertCreated()
            ->assertJsonPath('data.document_check.status', 'verified');
    }

    public function test_wrong_national_id_number_is_a_mismatch_and_blocks_the_selfie(): void
    {
        $r = $this->reservation();

        // Structurally valid, a different person (another birth date).
        $this->upload($r, ['document_number' => '29102150112351'])->assertCreated()
            ->assertJsonPath('data.document_check.status', 'mismatch')
            ->assertJsonPath('data.document_check.fields.number', 'mismatch')
            ->assertJsonPath('data.document_check.requires_new_document', true);

        $this->selfie($r)->assertStatus(422); // document_check_mismatch
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $r->fresh()->status);
    }

    public function test_wrong_name_is_a_mismatch(): void
    {
        $r = $this->reservation();

        $this->upload($r, ['full_name' => 'خالد يوسف إبراهيم'])->assertCreated()
            ->assertJsonPath('data.document_check.status', 'mismatch')
            ->assertJsonPath('data.document_check.fields.name', 'mismatch');
    }

    public function test_a_partial_name_that_is_on_the_card_goes_to_review_per_the_existing_rules(): void
    {
        $r = $this->reservation();

        $this->upload($r, ['full_name' => 'سامي عادل'])->assertCreated()
            ->assertJsonPath('data.document_check.status', 'needs_review')
            ->assertJsonPath('data.document_check.fields.name', 'weak');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function otherTypes(): array
    {
        return [
            'passport' => [['document_type' => 'passport', 'back_image' => null, 'full_name' => 'Sami Mansour', 'document_number' => 'A1234567', 'date_of_birth' => '1990-01-15']],
            'saudi national id' => [['document_type' => 'saudi_national_id', 'back_image' => null, 'document_number' => '1098765432', 'date_of_birth' => '1990-01-15']],
        ];
    }

    #[DataProvider('otherTypes')]
    public function test_selecting_a_different_document_type_is_rejected_as_a_type_mismatch(array $over): void
    {
        $r = $this->reservation();
        $over = array_filter($over, fn ($v) => $v !== null);
        $payload = $over + ['front_image' => UploadedFile::fake()->image('front.jpg', 40, 40)];
        if (! array_key_exists('back_image', $over)) {
            $payload['back_image'] = null;
        }

        $res = $this->withHeaders(['Accept' => 'application/json'])
            ->post("/api/v1/guest/reservations/{$r->id}/identity/documents", array_filter($payload, fn ($v) => $v !== null))
            ->assertCreated();

        $this->assertSame('document_unsupported', $res->json('data.document_check.status'));
        $this->assertContains('document_type_mismatch', $res->json('data.document_check.reasons'));
        $this->assertFalse($res->json('data.document_check.can_continue'));
    }

    public function test_the_uploaded_image_never_changes_the_result(): void
    {
        $results = [];

        foreach ([
            [UploadedFile::fake()->image('a.jpg', 40, 40), UploadedFile::fake()->image('b.jpg', 41, 41)],
            [UploadedFile::fake()->image('big.png', 900, 600), UploadedFile::fake()->image('tall.png', 300, 1200)],
        ] as [$front, $back]) {
            $r = $this->reservation();
            $check = $this->upload($r, ['front_image' => $front, 'back_image' => $back])
                ->assertCreated()
                ->json('data.document_check');
            unset($check['checked_at']); // a timestamp, not part of the result
            $results[] = $check;
        }

        $this->assertSame('verified', $results[0]['status']);
        $this->assertSame($results[0], $results[1]);
    }

    public function test_with_no_auto_approve_threshold_the_dashboard_approval_verifies_the_reservation(): void
    {
        config(['verification.thresholds.auto_approve' => null]);
        $r = $this->reservation();

        $this->upload($r)->assertCreated()->assertJsonPath('data.document_check.status', 'verified');
        $this->selfie($r)->assertOk()
            ->assertJsonPath('data.status', IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW);
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $r->fresh()->status);

        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($r->hotel_id);
        $this->app['auth']->forgetGuards();

        $this->actingAs($reception, 'sanctum')
            ->postJson("/api/v1/identity-verification/{$r->id}/review", ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', IdentityVerificationSession::STATUS_STAFF_APPROVED);

        $this->assertSame(Reservation::STATUS_VERIFIED, $r->fresh()->status);
    }

    public function test_production_refuses_the_dummy_provider(): void
    {
        $this->app['env'] = 'production';
        $this->app->forgetInstance(IdentityDocumentProviderInterface::class);

        $this->expectException(UnsupportedIdentityVerificationProviderException::class);
        $this->app->make(IdentityDocumentProviderInterface::class);
    }

    public function test_even_if_injected_in_production_the_fixture_never_auto_verifies(): void
    {
        $this->app['env'] = 'production';
        $files = $this->app->make(IdentityFileStore::class);
        $path = $files->store(1, 1, 'document', UploadedFile::fake()->image('front.jpg', 40, 40));
        $service = new IdentityDocumentCheckService(
            new DummyIdentityDocumentProvider(DummyIdentityDocumentProvider::SCENARIO_EGYPTIAN_ID_FIXTURE),
            $files,
        );

        $outcome = $service->check(
            1,
            $path,
            'egyptian_national_id',
            new IdentityClaim(fullName: self::NAME, documentNumber: self::NUMBER, dateOfBirth: new DateTimeImmutable('1990-01-15')),
            new DateTimeImmutable('2026-09-29'),
            IdentityDocumentType::EgyptianNationalId,
        )['outcome'];

        $this->assertSame('needs_review', $outcome->status->value);
        $this->assertContains('manual_review_required_for_document_type', $outcome->reasons);
    }
}
