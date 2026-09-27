<?php

namespace Tests\Unit\Models;

use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccessGrantModelTest extends TestCase
{
    public function test_status_constants_match_the_migration_enum(): void
    {
        $this->assertSame([
            'not_issued',
            'issue_requested',
            'active',
            'failed',
            'revoke_requested',
            'revoked',
            'expired',
        ], AccessGrant::STATUSES);
    }

    public function test_it_belongs_to_reservation_guest_and_hotel_derived_from_the_reservation(): void
    {
        $grant = AccessGrant::factory()->create();

        $this->assertInstanceOf(Reservation::class, $grant->reservation);
        $this->assertSame($grant->reservation->hotel_id, $grant->hotel_id);
        $this->assertSame($grant->reservation->guest_id, $grant->guest_id);
    }

    public function test_credential_is_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $grant = AccessGrant::factory()->create(['credential' => '135790']);

        // Round-trips through the encrypted cast.
        $this->assertSame('135790', $grant->fresh()->credential);

        // Raw column value is ciphertext, not the PIN.
        $raw = DB::table('access_grants')->where('id', $grant->id)->value('credential');
        $this->assertNotSame('135790', $raw);
        $this->assertStringNotContainsString('135790', (string) $raw);

        // Never serialized.
        $this->assertArrayNotHasKey('credential', $grant->toArray());
        $this->assertArrayNotHasKey('idempotency_key', $grant->toArray());
        $this->assertArrayNotHasKey('provider_reference', $grant->toArray());
    }

    public function test_metadata_casts_to_array(): void
    {
        $grant = AccessGrant::factory()->create(['metadata' => ['provider_code' => 'dummy_access_success']]);

        $this->assertSame(['provider_code' => 'dummy_access_success'], $grant->fresh()->metadata);
    }
}
