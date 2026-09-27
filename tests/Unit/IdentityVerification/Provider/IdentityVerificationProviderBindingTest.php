<?php

namespace Tests\Unit\IdentityVerification\Provider;

use App\Domain\IdentityVerification\Provider\Contracts\IdentityVerificationProviderInterface;
use App\Domain\IdentityVerification\Provider\DummyIdentityVerificationProvider;
use App\Domain\IdentityVerification\Provider\Exceptions\UnsupportedIdentityVerificationProviderException;
use App\Domain\IdentityVerification\Provider\Exceptions\UnsupportedIdentityVerificationSimulationDirectiveException;
use Tests\TestCase;

/**
 * Phase 6 — container binding / provider resolution (mirrors the Phase 5
 * payment gateway binding test).
 */
class IdentityVerificationProviderBindingTest extends TestCase
{
    private function rebind(): void
    {
        $this->app->forgetInstance(IdentityVerificationProviderInterface::class);
    }

    public function test_the_interface_resolves_from_the_container(): void
    {
        $this->assertInstanceOf(
            IdentityVerificationProviderInterface::class,
            $this->app->make(IdentityVerificationProviderInterface::class),
        );
    }

    public function test_the_default_provider_resolves_to_the_dummy_provider(): void
    {
        config(['verification.provider' => 'dummy']);
        $this->rebind();

        $this->assertInstanceOf(
            DummyIdentityVerificationProvider::class,
            $this->app->make(IdentityVerificationProviderInterface::class),
        );
    }

    public function test_config_default_provider_is_dummy(): void
    {
        $this->assertSame('dummy', config('verification.provider'));
    }

    public function test_the_binding_is_shared(): void
    {
        $this->assertSame(
            $this->app->make(IdentityVerificationProviderInterface::class),
            $this->app->make(IdentityVerificationProviderInterface::class),
        );
    }

    public function test_an_unsupported_provider_fails_loudly_without_falling_back(): void
    {
        config(['verification.provider' => 'onfido']);
        $this->rebind();

        $this->expectException(UnsupportedIdentityVerificationProviderException::class);
        $this->expectExceptionMessage("Unsupported identity verification provider configured: 'onfido'.");

        $this->app->make(IdentityVerificationProviderInterface::class);
    }

    public function test_an_unsupported_default_directive_fails_loudly(): void
    {
        config(['verification.providers.dummy.default_directive' => 'sometimes']);
        $this->rebind();

        $this->expectException(UnsupportedIdentityVerificationSimulationDirectiveException::class);

        $this->app->make(IdentityVerificationProviderInterface::class);
    }
}
