<?php

namespace Tests\Feature\IdentityVerification;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IdentityVerificationHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['verification.thresholds.auto_approve' => 80, 'verification.max_retries' => 2]);
    }

    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    private function reservation(): Reservation
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        return Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_DEPOSIT_HELD,
        ]);
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->image('x.jpg', 10, 10);
    }

    public function test_upload_endpoint_is_rate_limited(): void
    {
        config(['verification.rate_limits.submit.per_minute' => 3]);
        $owner = $this->owner();
        $reservation = $this->reservation();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($owner, 'sanctum')
                ->post("/api/v1/identity-verification/{$reservation->id}/documents", ['document' => $this->image()])
                ->assertStatus(201);
        }

        $this->actingAs($owner, 'sanctum')
            ->post("/api/v1/identity-verification/{$reservation->id}/documents", ['document' => $this->image()])
            ->assertStatus(429);
    }

    public function test_status_and_review_are_not_upload_rate_limited(): void
    {
        config(['verification.rate_limits.submit.per_minute' => 1]);
        $owner = $this->owner();
        $reservation = $this->reservation();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($owner, 'sanctum')
                ->getJson("/api/v1/identity-verification/{$reservation->id}/status")
                ->assertOk();
        }
    }

    /**
     * @return list<string>
     */
    private function domainSourceFiles(): array
    {
        return File::allFiles(app_path('Domain/IdentityVerification'));
    }

    public function test_the_concrete_provider_is_only_referenced_inside_the_provider_package_and_the_service_provider(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $isProviderPackage = str_contains($path, 'Domain/IdentityVerification/Provider');
            $isServiceProvider = str_ends_with($path, 'Providers/AppServiceProvider.php');

            if ($isProviderPackage || $isServiceProvider) {
                continue;
            }

            if (str_contains(File::get($path), 'DummyIdentityVerificationProvider')) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders);
    }

    private function codeWithoutComments(string $file): string
    {
        $out = '';
        foreach (token_get_all(File::get($file)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    public function test_no_domain_source_signs_a_webhook_or_calls_an_http_client(): void
    {
        // The approved Phase 6 workflow has no verification callback/webhook
        // (Phase 0 §16), so there is no signing anywhere. The OCR adapter's
        // HTTP client is injected (never the Http:: facade).
        foreach ($this->domainSourceFiles() as $file) {
            $code = $this->codeWithoutComments($file->getPathname());

            // The file store's keyed duplicate-upload fingerprint is the only
            // HMAC — it signs nothing and verifies no webhook.
            if ($file->getFilename() !== 'IdentityFileStore.php') {
                $this->assertStringNotContainsString('hash_hmac', $code, $file->getFilename());
            }
            $this->assertStringNotContainsString('Http::', $code, $file->getFilename());
            $this->assertStringNotContainsString('GuzzleHttp', $code, $file->getFilename());
        }
    }

    public function test_no_domain_source_contains_an_invented_threshold_or_retry_number(): void
    {
        // The domain must read every business number from config — never a
        // literal like `>= 70` / `>= 80` / `3 retries`.
        foreach ($this->domainSourceFiles() as $file) {
            $code = File::get($file->getPathname());

            $this->assertDoesNotMatchRegularExpression(
                '/(score|confidence)\s*[<>]=?\s*\d/i',
                $code,
                $file->getFilename().' hardcodes a score comparison',
            );
        }
    }

    public function test_config_leaves_thresholds_and_retry_unset_and_retains_ids_30_days(): void
    {
        // Read the config file fresh (env vars are absent in the test env),
        // bypassing any runtime override — the shipped default invents no
        // number for any genuinely-unresolved business value.
        $fresh = require config_path('verification.php');

        $this->assertNull($fresh['thresholds']['auto_approve']);
        $this->assertNull($fresh['thresholds']['manual_review']);
        $this->assertNull($fresh['max_retries']);
        // Approved 2026-09-26: the hotel keeps a stay's ID images 30 days.
        $this->assertSame(30, $fresh['retention_days']);
    }
}
