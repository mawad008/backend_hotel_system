<?php

namespace Tests\Unit\IdentityVerification;

use App\Domain\IdentityVerification\DocumentCheck\DocumentCheckStatus;
use App\Domain\IdentityVerification\Models\IdentityVerificationAttempt;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\IdentityVerification\Provider\Contracts\IdentityDocumentProviderInterface;
use App\Domain\IdentityVerification\Provider\DummyIdentityDocumentProvider;
use App\Domain\IdentityVerification\Services\IdentityImageRetentionService;
use App\Domain\IdentityVerification\Support\IdentityFileStore;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Retention of OCR by-products and the encrypted file store.
 */
class IdentityOcrRetentionTest extends TestCase
{
    private DummyIdentityDocumentProvider $ocr;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->ocr = new DummyIdentityDocumentProvider;
        $this->app->instance(IdentityDocumentProviderInterface::class, $this->ocr);
    }

    public function test_pending_provider_artifacts_are_deleted_by_the_scheduled_command(): void
    {
        $session = IdentityVerificationSession::factory()->create();
        $attempt = IdentityVerificationAttempt::factory()->create(['session_id' => $session->id, 'provider_artifact_ref' => 'abcdef12-3456']);

        Artisan::call('identity:purge-ocr-artifacts');

        $this->assertSame(['abcdef12-3456'], $this->ocr->deleted);
        $this->assertNull($attempt->refresh()->provider_artifact_ref);
    }

    public function test_an_artifact_the_provider_still_refuses_to_delete_stays_pending(): void
    {
        $this->app->instance(IdentityDocumentProviderInterface::class, new class implements IdentityDocumentProviderInterface
        {
            public function name(): string
            {
                return 'failing';
            }

            public function extract(\App\Domain\IdentityVerification\Provider\Data\DocumentExtractionRequest $request): \App\Domain\IdentityVerification\Provider\Data\DocumentExtractionResult
            {
                throw new RuntimeException('unused');
            }

            public function deleteArtifact(string $artifactReference): bool
            {
                return false;
            }
        });

        $session = IdentityVerificationSession::factory()->create();
        $attempt = IdentityVerificationAttempt::factory()->create(['session_id' => $session->id, 'provider_artifact_ref' => 'abcdef12-3456']);

        $result = app(IdentityImageRetentionService::class)->purgeOcrArtifacts();

        $this->assertSame(1, $result['artifacts_pending']);
        $this->assertSame('abcdef12-3456', $attempt->refresh()->provider_artifact_ref);
    }

    public function test_front_and_back_artifacts_are_retried_individually(): void
    {
        $this->app->instance(IdentityDocumentProviderInterface::class, new class implements IdentityDocumentProviderInterface
        {
            public function name(): string
            {
                return 'partial';
            }

            public function extract(\App\Domain\IdentityVerification\Provider\Data\DocumentExtractionRequest $request): \App\Domain\IdentityVerification\Provider\Data\DocumentExtractionResult
            {
                throw new RuntimeException('unused');
            }

            public function deleteArtifact(string $artifactReference): bool
            {
                return ! str_ends_with($artifactReference, 'back');
            }
        });

        $session = IdentityVerificationSession::factory()->create();
        $attempt = IdentityVerificationAttempt::factory()->create([
            'session_id' => $session->id,
            'provider_artifact_ref' => 'egypt-id-v3/front,egypt-id-v3/back',
        ]);

        $result = app(IdentityImageRetentionService::class)->purgeOcrArtifacts();

        $this->assertSame(1, $result['artifacts_pending']);
        $this->assertSame('egypt-id-v3/back', $attempt->refresh()->provider_artifact_ref);
    }

    public function test_documents_of_an_interrupted_ocr_check_are_deleted(): void
    {
        $session = IdentityVerificationSession::factory()->create();
        $path = "identity-verification/{$session->id}/1-document-abcdefghijklmnopqrst.enc";
        Storage::disk('local')->put($path, 'ciphertext');

        $stale = IdentityVerificationAttempt::factory()->create([
            'session_id' => $session->id,
            'document_path' => $path,
            'document_check_status' => DocumentCheckStatus::Processing->value,
        ]);
        IdentityVerificationAttempt::query()->whereKey($stale->id)->update(['updated_at' => now()->subHours(2)]);

        $fresh = IdentityVerificationAttempt::factory()->create([
            'session_id' => $session->id,
            'attempt_number' => 2,
            'document_check_status' => DocumentCheckStatus::Processing->value,
        ]);

        $result = app(IdentityImageRetentionService::class)->purgeOcrArtifacts();

        $this->assertSame(1, $result['stale_documents_deleted']);
        Storage::disk('local')->assertMissing($path);
        $this->assertNull($stale->refresh()->document_path);
        $this->assertSame('ocr_failed', $stale->document_check_status);
        $this->assertSame('processing', $fresh->refresh()->document_check_status);
    }

    public function test_retention_can_never_exceed_thirty_days_even_when_configured_higher(): void
    {
        config(['verification.retention_days' => 365]);
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_CHECKED_OUT]);
        Reservation::query()->whereKey($reservation->id)->update(['updated_at' => now()->subDays(31)]);
        $session = IdentityVerificationSession::factory()->create(['reservation_id' => $reservation->id, 'guest_id' => $reservation->guest_id]);
        $path = "identity-verification/{$session->id}/1-document-abcdefghijklmnopqrst.enc";
        Storage::disk('local')->put($path, 'ciphertext');
        IdentityVerificationAttempt::factory()->create(['session_id' => $session->id, 'document_path' => $path]);

        $this->assertSame(1, app(IdentityImageRetentionService::class)->purgeExpired());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_file_store_encrypts_round_trips_and_refuses_foreign_paths(): void
    {
        $store = new IdentityFileStore;
        $file = UploadedFile::fake()->image('doc.jpg', 10, 10);
        $plain = file_get_contents($file->getRealPath());

        $path = $store->store(7, 9, IdentityFileStore::KIND_DOCUMENT, $file);

        $this->assertMatchesRegularExpression('#^identity-verification/7/9-document-[A-Za-z0-9]{20}\.enc$#', $path);
        $this->assertNotSame($plain, Storage::disk('local')->get($path));
        $this->assertSame($plain, $store->read($path));

        foreach (['identity-verification/7/../../.env', '../.env', 'identity-verification/7/sub/x.jpg', '/etc/passwd', 'other/1/x.jpg'] as $evil) {
            try {
                $store->read($evil);
                $this->fail("read() accepted {$evil}");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_public_disk_is_refused(): void
    {
        config(['verification.storage.disk' => 'public']);

        $this->expectException(RuntimeException::class);
        (new IdentityFileStore)->disk();
    }
}
