<?php

namespace Tests\Unit\Payment\Gateway;

use App\Domain\Payment\Gateway\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Gateway\DummyPaymentGateway;
use App\Domain\Payment\Gateway\Exceptions\UnsupportedPaymentProviderException;
use App\Domain\Payment\Gateway\Exceptions\UnsupportedSimulationDirectiveException;
use Tests\TestCase;

/**
 * Phase 5B test matrix A — container binding / provider resolution
 * (Phase 5B instructions §16, §20A).
 */
class PaymentGatewayBindingTest extends TestCase
{
    private function rebind(): void
    {
        $this->app->forgetInstance(PaymentGatewayInterface::class);
    }

    public function test_the_interface_resolves_from_the_container(): void
    {
        $this->assertInstanceOf(
            PaymentGatewayInterface::class,
            $this->app->make(PaymentGatewayInterface::class),
        );
    }

    public function test_the_default_provider_resolves_to_the_dummy_gateway(): void
    {
        config(['payment.provider' => 'dummy']);
        $this->rebind();

        $this->assertInstanceOf(
            DummyPaymentGateway::class,
            $this->app->make(PaymentGatewayInterface::class),
        );
    }

    public function test_config_default_provider_is_dummy(): void
    {
        // Independent of any env override in the test environment.
        $this->assertSame('dummy', config('payment.provider'));
    }

    public function test_the_binding_is_shared(): void
    {
        $this->assertSame(
            $this->app->make(PaymentGatewayInterface::class),
            $this->app->make(PaymentGatewayInterface::class),
        );
    }

    public function test_an_unsupported_provider_fails_loudly_without_falling_back(): void
    {
        config(['payment.provider' => 'stripe']);
        $this->rebind();

        $this->expectException(UnsupportedPaymentProviderException::class);
        $this->expectExceptionMessage("Unsupported payment provider configured: 'stripe'.");

        $this->app->make(PaymentGatewayInterface::class);
    }

    public function test_an_unsupported_default_directive_fails_loudly(): void
    {
        config(['payment.providers.dummy.default_directive' => 'sometimes']);
        $this->rebind();

        $this->expectException(UnsupportedSimulationDirectiveException::class);

        $this->app->make(PaymentGatewayInterface::class);
    }

    public function test_the_exception_message_never_leaks_a_secret(): void
    {
        config([
            'payment.provider' => 'mystery',
            'payment.providers.dummy.webhook_secret' => 'super-secret-value',
        ]);
        $this->rebind();

        try {
            $this->app->make(PaymentGatewayInterface::class);
            $this->fail('Expected an UnsupportedPaymentProviderException.');
        } catch (UnsupportedPaymentProviderException $e) {
            $this->assertStringNotContainsString('super-secret-value', $e->getMessage());
        }
    }
}
