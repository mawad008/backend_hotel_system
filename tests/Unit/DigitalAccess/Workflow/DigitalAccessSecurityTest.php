<?php

namespace Tests\Unit\DigitalAccess\Workflow;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\DigitalAccess\Provider\SimulationDirective;
use App\Http\Resources\V1\AccessGrantResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DigitalAccessSecurityTest extends DigitalAccessWorkflowTestCase
{
    public function test_no_audit_row_ever_contains_the_credential_or_the_idempotency_key(): void
    {
        $reservation = $this->verifiedReservation();
        $service = $this->makeService();
        $grant = $service->checkIn($reservation, SimulationDirective::Success, idempotencyKey: 'secret-looking-key-123');
        $service->revoke($reservation->fresh(), 'reason', SimulationDirective::Success);

        $pin = $grant->credential;
        $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);

        foreach (AuditLog::all() as $log) {
            $blob = json_encode([$log->before, $log->after]);
            $this->assertStringNotContainsString($pin, $blob, "audit {$log->action} leaked the PIN");
            $this->assertStringNotContainsString('secret-looking-key-123', $blob, "audit {$log->action} leaked the idempotency key");
            $this->assertStringNotContainsStringIgnoringCase('credential', $blob);
        }
    }

    public function test_credential_column_is_ciphertext_at_rest(): void
    {
        $reservation = $this->verifiedReservation();
        $grant = $this->makeService()->checkIn($reservation, SimulationDirective::Success);

        $raw = (string) DB::table('access_grants')->where('id', $grant->id)->value('credential');

        $this->assertNotSame($grant->credential, $raw);
        $this->assertStringNotContainsString($grant->credential, $raw);
    }

    public function test_credential_is_nulled_on_revoke_and_on_expiry(): void
    {
        $reservation = $this->verifiedReservation();
        $service = $this->makeService();

        $revoked = $service->checkIn($reservation, SimulationDirective::Success);
        $this->assertNotNull($revoked->credential);
        $revoked = $service->revoke($reservation->fresh(), null, SimulationDirective::Success);
        $this->assertNull($revoked->fresh()->credential);
        $this->assertNull(DB::table('access_grants')->where('id', $revoked->id)->value('credential'));

        $other = $this->verifiedReservation();
        $service->checkIn($other, SimulationDirective::Success);
        AccessGrant::query()->where('reservation_id', $other->id)->update(['expires_at' => now()->subMinute()]);
        $expired = $service->currentStatusFor($other->fresh());
        $this->assertNull($expired->credential);
    }

    public function test_resource_exposes_the_pin_only_while_active(): void
    {
        $request = Request::create('/');

        $active = AccessGrant::factory()->active()->create();
        $this->assertArrayHasKey('credential', (new AccessGrantResource($active))->resolve($request));

        foreach ([
            AccessGrant::STATUS_NOT_ISSUED,
            AccessGrant::STATUS_ISSUE_REQUESTED,
            AccessGrant::STATUS_FAILED,
            AccessGrant::STATUS_REVOKED,
            AccessGrant::STATUS_EXPIRED,
        ] as $status) {
            $grant = AccessGrant::factory()->create(['status' => $status, 'credential' => '111111']);
            $this->assertArrayNotHasKey(
                'credential',
                (new AccessGrantResource($grant))->resolve($request),
                "resource exposed the PIN while {$status}",
            );
        }
    }

    public function test_grant_metadata_only_holds_safe_provider_context(): void
    {
        $reservation = $this->verifiedReservation();
        $grant = $this->makeService()->checkIn($reservation, SimulationDirective::Success);

        $this->assertSame(['provider_code', 'provider_message'], array_keys($grant->metadata ?? []));
        $this->assertStringNotContainsString($grant->credential, json_encode($grant->metadata));
    }
}
