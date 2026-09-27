<?php

namespace Tests\Unit\DigitalAccess\Provider;

use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\DigitalAccess\Provider\AccessResultStatus;
use App\Domain\DigitalAccess\Provider\Data\AccessIssueRequest;
use App\Domain\DigitalAccess\Provider\Data\AccessOperationRequest;
use App\Domain\DigitalAccess\Provider\DummyDigitalAccessProvider;
use App\Domain\DigitalAccess\Provider\Exceptions\UnsupportedDigitalAccessSimulationDirectiveException;
use App\Domain\DigitalAccess\Provider\SimulationDirective;
use InvalidArgumentException;
use Tests\TestCase;

class DummyDigitalAccessProviderTest extends TestCase
{
    private function provider(SimulationDirective $default = SimulationDirective::Success): DummyDigitalAccessProvider
    {
        return new DummyDigitalAccessProvider($default);
    }

    public function test_issue_success_returns_an_active_credential(): void
    {
        $result = $this->provider()->issue(new AccessIssueRequest('grant-1', AccessGrant::MODE_PIN_CODE));

        $this->assertSame(AccessResultStatus::Active, $result->status);
        $this->assertNotNull($result->credential);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $result->credential);
        $this->assertStringStartsWith('dummy_access_issue_', $result->providerReference);
    }

    public function test_issue_failure_returns_no_credential(): void
    {
        $result = $this->provider()->issue(new AccessIssueRequest('grant-1', AccessGrant::MODE_PIN_CODE, directive: SimulationDirective::Failure));

        $this->assertSame(AccessResultStatus::Failed, $result->status);
        $this->assertNull($result->credential);
        $this->assertTrue($result->isFailure());
    }

    public function test_revoke_success_and_failure(): void
    {
        $ok = $this->provider()->revoke(new AccessOperationRequest('dummy_access_issue_x'));
        $this->assertSame(AccessResultStatus::Revoked, $ok->status);
        $this->assertNull($ok->credential);

        $bad = $this->provider()->revoke(new AccessOperationRequest('dummy_access_issue_x', directive: SimulationDirective::Failure));
        $this->assertSame(AccessResultStatus::Failed, $bad->status);
    }

    public function test_default_directive_is_used_when_none_supplied(): void
    {
        $result = $this->provider(SimulationDirective::Failure)->issue(new AccessIssueRequest('g', AccessGrant::MODE_PIN_CODE));

        $this->assertSame(AccessResultStatus::Failed, $result->status);
    }

    public function test_pin_and_reference_are_deterministic_for_the_same_seed(): void
    {
        $a = $this->provider()->issue(new AccessIssueRequest('same-seed', AccessGrant::MODE_PIN_CODE));
        $b = $this->provider()->issue(new AccessIssueRequest('same-seed', AccessGrant::MODE_PIN_CODE));
        $c = $this->provider()->issue(new AccessIssueRequest('other-seed', AccessGrant::MODE_PIN_CODE));

        $this->assertSame($a->credential, $b->credential);
        $this->assertSame($a->providerReference, $b->providerReference);
        $this->assertNotSame($a->credential, $c->credential);
    }

    public function test_result_context_and_message_never_contain_the_credential(): void
    {
        $result = $this->provider()->issue(new AccessIssueRequest('grant-1', AccessGrant::MODE_PIN_CODE, metadata: ['room_id' => 42]));

        $this->assertStringNotContainsString((string) $result->credential, $result->message);
        $this->assertStringNotContainsString((string) $result->credential, json_encode($result->context));
        $this->assertStringNotContainsString((string) $result->credential, json_encode($result->toSafeArray()));
        $this->assertArrayNotHasKey('credential', $result->toSafeArray());
    }

    public function test_request_rejects_secret_metadata_keys(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AccessIssueRequest('g', AccessGrant::MODE_PIN_CODE, metadata: ['pin_code' => '111111']);
    }

    public function test_from_config_rejects_an_unknown_directive(): void
    {
        $this->expectException(UnsupportedDigitalAccessSimulationDirectiveException::class);

        SimulationDirective::fromConfig('maybe');
    }
}
