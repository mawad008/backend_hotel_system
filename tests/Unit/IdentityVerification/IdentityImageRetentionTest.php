<?php

namespace Tests\Unit\IdentityVerification;

use App\Domain\IdentityVerification\Models\IdentityVerificationAttempt;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\IdentityVerification\Services\IdentityImageRetentionService;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IdentityImageRetentionTest extends TestCase
{
    private function attemptFor(string $status, int $endedDaysAgo): IdentityVerificationAttempt
    {
        $reservation = Reservation::factory()->create(['status' => $status]);
        $reservation->forceFill(['updated_at' => now()->subDays($endedDaysAgo)])->saveQuietly();
        $session = IdentityVerificationSession::factory()->create(['reservation_id' => $reservation->id]);
        $attempt = IdentityVerificationAttempt::factory()->create([
            'session_id' => $session->id,
            'selfie_path' => "identity-verification/{$session->id}/selfie.jpg",
        ]);
        Storage::disk(config('verification.storage.disk', 'local'))->put($attempt->document_path, 'x');
        Storage::disk(config('verification.storage.disk', 'local'))->put($attempt->selfie_path, 'x');

        return $attempt;
    }

    public function test_it_is_a_no_op_while_the_retention_period_is_unset(): void
    {
        Storage::fake(config('verification.storage.disk', 'local'));
        config(['verification.retention_days' => null]);
        $attempt = $this->attemptFor(Reservation::STATUS_INVOICED, 30);

        $this->assertNull(app(IdentityImageRetentionService::class)->purgeExpired());
        $this->assertNotNull($attempt->fresh()->document_path);
    }

    public function test_it_deletes_images_of_stays_that_ended_beyond_the_window_only(): void
    {
        $disk = config('verification.storage.disk', 'local');
        Storage::fake($disk);
        config(['verification.retention_days' => 7]);
        $old = $this->attemptFor(Reservation::STATUS_INVOICED, 10);
        $recent = $this->attemptFor(Reservation::STATUS_CHECKED_OUT, 2);
        $live = $this->attemptFor(Reservation::STATUS_IN_STAY, 30);
        $oldPaths = [$old->document_path, $old->selfie_path];

        $this->assertSame(1, app(IdentityImageRetentionService::class)->purgeExpired());

        $this->assertNull($old->fresh()->document_path);
        $this->assertNull($old->fresh()->selfie_path);
        foreach ($oldPaths as $path) {
            Storage::disk($disk)->assertMissing($path);
        }
        $this->assertNotNull($recent->fresh()->document_path);
        $this->assertNotNull($live->fresh()->document_path);
        // The verification outcome itself is kept.
        $this->assertDatabaseHas('identity_verification_attempts', ['id' => $old->id]);
    }

    public function test_a_guest_who_keeps_their_id_keeps_only_the_latest_approved_images(): void
    {
        $disk = config('verification.storage.disk', 'local');
        Storage::fake($disk);
        config(['verification.retention_days' => 30]);
        $older = $this->attemptFor(Reservation::STATUS_INVOICED, 90);
        $latest = $this->attemptFor(Reservation::STATUS_INVOICED, 40);
        $guest = Guest::factory()->create(['identity_retention' => Guest::IDENTITY_KEEP_FOR_FUTURE]);
        foreach ([$older, $latest] as $a) {
            IdentityVerificationSession::query()->whereKey($a->session_id)->update(['guest_id' => $guest->id, 'status' => 'auto_approved']);
        }

        $this->assertSame(1, app(IdentityImageRetentionService::class)->purgeExpired());
        $this->assertNull($older->fresh()->document_path);
        $this->assertNotNull($latest->fresh()->document_path);
    }

    public function test_the_platform_default_is_thirty_days_and_a_guest_default_deletes(): void
    {
        $this->assertSame(30, (int) config('verification.retention_days'));
        $this->assertSame(Guest::IDENTITY_DELETE_AFTER_CHECKOUT, Guest::factory()->create()->fresh()->identity_retention);
    }

    public function test_a_retention_period_above_thirty_days_is_capped(): void
    {
        $disk = config('verification.storage.disk', 'local');
        Storage::fake($disk);
        config(['verification.retention_days' => 90]);
        $beyondCap = $this->attemptFor(Reservation::STATUS_CHECKED_OUT, 31);
        $withinCap = $this->attemptFor(Reservation::STATUS_CHECKED_OUT, 29);

        $this->assertSame(1, app(IdentityImageRetentionService::class)->purgeExpired());
        $this->assertNull($beyondCap->fresh()->document_path);
        $this->assertNotNull($withinCap->fresh()->document_path);
    }

    public function test_the_scheduled_command_deletes_the_stored_files(): void
    {
        $disk = config('verification.storage.disk', 'local');
        Storage::fake($disk);
        config(['verification.retention_days' => 30]);
        $expired = $this->attemptFor(Reservation::STATUS_INVOICED, 45);
        $paths = [$expired->document_path, $expired->selfie_path];
        foreach ($paths as $path) {
            Storage::disk($disk)->assertExists($path);
        }

        $this->artisan('identity:purge-expired-images')
            ->expectsOutputToContain('Purged images of 1 identity verification attempt(s).')
            ->assertSuccessful();

        foreach ($paths as $path) {
            Storage::disk($disk)->assertMissing($path);
        }
        $this->assertNull($expired->fresh()->selfie_path);
    }

    public function test_the_purge_command_is_scheduled_daily(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'identity:purge-expired-images'));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression);
    }
}
