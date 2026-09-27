<?php

namespace Tests\Feature\DigitalAccess;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Models\Payment;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DigitalAccessHardeningTest extends TestCase
{
    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    private function verifiedReservation(?Hotel $hotel = null): Reservation
    {
        $hotel ??= Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->withRoom()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_VERIFIED,
            'check_out' => now()->addDays(3)->format('Y-m-d'),
        ]);
        Payment::factory()->holdActive()->create(['reservation_id' => $reservation->id, 'hotel_id' => $hotel->id]);
        IdentityVerificationSession::factory()->autoApproved()->create(['reservation_id' => $reservation->id]);

        return $reservation;
    }

    public function test_check_in_endpoint_is_rate_limited(): void
    {
        config(['digital_access.rate_limits.check_in.per_minute' => 3]);
        $owner = $this->owner();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($owner, 'sanctum')
                ->postJson("/api/v1/check-in/{$this->verifiedReservation()->id}")
                ->assertStatus(201);
        }

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/check-in/{$this->verifiedReservation()->id}")
            ->assertStatus(429);
    }

    public function test_revoke_endpoint_is_rate_limited(): void
    {
        config(['digital_access.rate_limits.revoke.per_minute' => 2]);
        $owner = $this->owner();

        $reservations = [];
        for ($i = 0; $i < 3; $i++) {
            $r = $this->verifiedReservation();
            $this->actingAs($owner, 'sanctum')->postJson("/api/v1/check-in/{$r->id}")->assertStatus(201);
            $reservations[] = $r;
        }

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/access/{$reservations[0]->id}/revoke")->assertOk();
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/access/{$reservations[1]->id}/revoke")->assertOk();
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/access/{$reservations[2]->id}/revoke")->assertStatus(429);
    }

    public function test_status_endpoint_is_not_rate_limited_like_the_mutations(): void
    {
        config(['digital_access.rate_limits.check_in.per_minute' => 1]);
        $owner = $this->owner();
        $reservation = $this->verifiedReservation();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($owner, 'sanctum')->getJson("/api/v1/access/{$reservation->id}")->assertOk();
        }
    }

    /**
     * @return list<\SplFileInfo>
     */
    private function domainSourceFiles(): array
    {
        return File::allFiles(app_path('Domain/DigitalAccess'));
    }

    public function test_the_concrete_provider_is_only_referenced_inside_the_provider_package_and_the_service_provider(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, 'Domain/DigitalAccess/Provider') || str_ends_with($path, 'Providers/AppServiceProvider.php')) {
                continue;
            }
            if (str_contains(File::get($path), 'DummyDigitalAccessProvider')) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_no_domain_source_hardcodes_an_access_expiry_duration(): void
    {
        // §11: expiry is "stay end reached" — derived from the reservation,
        // never a baked-in number of hours/days.
        foreach ($this->domainSourceFiles() as $file) {
            $code = File::get($file->getPathname());
            $this->assertDoesNotMatchRegularExpression(
                '/addHours\(\s*\d|addDays\(\s*\d|expiry.*\d+\s*(hours|days)/i',
                $code,
                $file->getFilename().' appears to hardcode an expiry duration',
            );
        }
    }

    public function test_no_domain_or_config_source_contains_a_secret_or_webhook_signing(): void
    {
        $files = array_merge(
            iterator_to_array($this->domainSourceFiles()),
            [new \SplFileInfo(config_path('digital_access.php'))],
        );

        foreach ($files as $file) {
            $code = File::get($file->getPathname());
            $this->assertStringNotContainsString('hash_hmac', $code, $file->getFilename());
            $this->assertStringNotContainsString('sk_live', $code, $file->getFilename());
            $this->assertStringNotContainsStringIgnoringCase('api_key', $code, $file->getFilename());
        }
    }
}
