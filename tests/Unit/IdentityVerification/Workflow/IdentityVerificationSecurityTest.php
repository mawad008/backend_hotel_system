<?php

namespace Tests\Unit\IdentityVerification\Workflow;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\IdentityVerification\Models\IdentityVerificationAttempt;
use App\Domain\IdentityVerification\Provider\SimulationDirective;
use App\Domain\IdentityVerification\Support\IdentityFileStore;
use Illuminate\Support\Facades\Storage;

class IdentityVerificationSecurityTest extends IdentityVerificationWorkflowTestCase
{
    public function test_files_are_written_to_a_private_disk_never_public(): void
    {
        Storage::fake('public');
        config(['verification.thresholds.auto_approve' => 80]);

        $reservation = $this->reservation();
        $service = $this->makeService();
        $service->submitDocument($reservation, $this->image('doc.jpg'), 'passport', claim: $this->claim());
        $service->submitSelfie($reservation, $this->image('selfie.jpg'), directive: SimulationDirective::HighMatch);

        $attempt = IdentityVerificationAttempt::sole();

        Storage::disk('local')->assertExists($attempt->document_path);
        Storage::disk('local')->assertExists($attempt->selfie_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_file_store_refuses_the_public_disk(): void
    {
        config(['verification.storage.disk' => 'public']);

        $this->expectException(\RuntimeException::class);

        (new IdentityFileStore)->disk();
    }

    public function test_no_audit_log_contains_a_file_path_or_document_type(): void
    {
        config(['verification.thresholds.auto_approve' => 80]);
        $reservation = $this->reservation();
        $service = $this->makeService();
        $service->submitDocument($reservation, $this->image('doc.jpg'), 'passport', claim: $this->claim());
        $service->submitSelfie($reservation, $this->image('selfie.jpg'), directive: SimulationDirective::HighMatch);

        $paths = IdentityVerificationAttempt::sole()->only(['document_path', 'selfie_path']);

        foreach (AuditLog::all() as $log) {
            $blob = json_encode([$log->before, $log->after]);
            $this->assertStringNotContainsString($paths['document_path'], $blob);
            $this->assertStringNotContainsString($paths['selfie_path'], $blob);
            $this->assertStringNotContainsStringIgnoringCase('identity-verification/', $blob);
            $this->assertStringNotContainsStringIgnoringCase('passport', $blob);
        }
    }

    public function test_attempt_metadata_never_stores_a_raw_provider_payload_or_sensitive_key(): void
    {
        config(['verification.thresholds.auto_approve' => 80]);
        $reservation = $this->reservation();
        $service = $this->makeService();
        $service->submitDocument($reservation, $this->image('doc.jpg'), 'passport', claim: $this->claim());
        $service->submitSelfie($reservation, $this->image('selfie.jpg'), directive: SimulationDirective::HighMatch);

        $metadata = IdentityVerificationAttempt::sole()->metadata ?? [];

        $this->assertSame(['provider_code', 'provider_message'], array_keys($metadata));
        foreach (array_keys($metadata) as $key) {
            $this->assertStringNotContainsStringIgnoringCase('document_number', $key);
            $this->assertStringNotContainsStringIgnoringCase('secret', $key);
        }
    }

    public function test_attempt_model_hides_the_storage_paths_from_array_serialization(): void
    {
        $attempt = IdentityVerificationAttempt::factory()->create();

        $this->assertArrayNotHasKey('document_path', $attempt->toArray());
        $this->assertArrayNotHasKey('selfie_path', $attempt->toArray());
    }
}
