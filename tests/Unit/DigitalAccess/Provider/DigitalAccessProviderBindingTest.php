<?php

namespace Tests\Unit\DigitalAccess\Provider;

use App\Domain\DigitalAccess\Provider\Contracts\DigitalAccessProviderInterface;
use App\Domain\DigitalAccess\Provider\DummyDigitalAccessProvider;
use App\Domain\DigitalAccess\Provider\Exceptions\UnsupportedDigitalAccessProviderException;
use App\Domain\DigitalAccess\Provider\Exceptions\UnsupportedDigitalAccessSimulationDirectiveException;
use Tests\TestCase;

class DigitalAccessProviderBindingTest extends TestCase
{
    private function rebind(): void
    {
        $this->app->forgetInstance(DigitalAccessProviderInterface::class);
    }

    public function test_the_interface_resolves_to_the_dummy_provider_by_default(): void
    {
        $this->assertSame('dummy', config('digital_access.provider'));
        $this->assertInstanceOf(
            DummyDigitalAccessProvider::class,
            $this->app->make(DigitalAccessProviderInterface::class),
        );
    }

    public function test_the_binding_is_shared(): void
    {
        $this->assertSame(
            $this->app->make(DigitalAccessProviderInterface::class),
            $this->app->make(DigitalAccessProviderInterface::class),
        );
    }

    public function test_an_unsupported_provider_fails_loudly_without_falling_back(): void
    {
        config(['digital_access.provider' => 'salto']);
        $this->rebind();

        $this->expectException(UnsupportedDigitalAccessProviderException::class);
        $this->expectExceptionMessage("Unsupported digital access provider configured: 'salto'.");

        $this->app->make(DigitalAccessProviderInterface::class);
    }

    public function test_an_unsupported_default_directive_fails_loudly(): void
    {
        config(['digital_access.providers.dummy.default_directive' => 'sometimes']);
        $this->rebind();

        $this->expectException(UnsupportedDigitalAccessSimulationDirectiveException::class);

        $this->app->make(DigitalAccessProviderInterface::class);
    }
}
