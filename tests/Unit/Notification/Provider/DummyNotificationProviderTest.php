<?php

namespace Tests\Unit\Notification\Provider;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Provider\Data\NotificationDispatchRequest;
use App\Domain\Notification\Provider\DummyNotificationProvider;
use App\Domain\Notification\Provider\NotificationDeliveryOutcome;
use App\Domain\Notification\Provider\SimulationDirective;
use PHPUnit\Framework\TestCase;

class DummyNotificationProviderTest extends TestCase
{
    private function request(NotificationChannel $channel, ?SimulationDirective $directive = null): NotificationDispatchRequest
    {
        return new NotificationDispatchRequest(
            channel: $channel,
            destinationReference: 'rcpt_abc',
            type: 'reservation_deposit_held',
            locale: 'en',
            subject: 'Deposit confirmed',
            body: 'The deposit hold for reservation #1 has been confirmed.',
            directive: $directive,
        );
    }

    public function test_deliver_directive_yields_a_sent_outcome_on_every_channel(): void
    {
        $provider = new DummyNotificationProvider;

        foreach (NotificationChannel::cases() as $channel) {
            $result = $provider->send($this->request($channel, SimulationDirective::Deliver));

            $this->assertSame(NotificationDeliveryOutcome::Sent, $result->outcome);
            $this->assertFalse($result->isFailure());
            $this->assertStringContainsString($channel->value, $result->providerReference);
            $this->assertStringContainsString('No external message was sent', $result->message);
        }
    }

    public function test_fail_directive_yields_a_failed_outcome(): void
    {
        $result = (new DummyNotificationProvider)->send($this->request(NotificationChannel::Email, SimulationDirective::Fail));

        $this->assertSame(NotificationDeliveryOutcome::Failed, $result->outcome);
        $this->assertTrue($result->isFailure());
    }

    public function test_the_configured_default_directive_is_used_when_none_is_supplied(): void
    {
        $failing = new DummyNotificationProvider(SimulationDirective::Fail);

        $this->assertTrue($failing->send($this->request(NotificationChannel::Sms))->isFailure());

        $delivering = new DummyNotificationProvider(SimulationDirective::Deliver);

        $this->assertFalse($delivering->send($this->request(NotificationChannel::Sms))->isFailure());
    }

    public function test_output_is_deterministic_for_the_same_input(): void
    {
        $provider = new DummyNotificationProvider;

        $a = $provider->send($this->request(NotificationChannel::InApp, SimulationDirective::Deliver));
        $b = $provider->send($this->request(NotificationChannel::InApp, SimulationDirective::Deliver));

        $this->assertSame($a->providerReference, $b->providerReference);
        $this->assertSame($a->providerCode, $b->providerCode);
    }

    public function test_reference_differs_per_channel_and_per_destination(): void
    {
        $provider = new DummyNotificationProvider;

        $email = $provider->send($this->request(NotificationChannel::Email, SimulationDirective::Deliver));
        $sms = $provider->send($this->request(NotificationChannel::Sms, SimulationDirective::Deliver));

        $this->assertNotSame($email->providerReference, $sms->providerReference);
    }

    public function test_context_is_rejected_when_it_carries_a_secret_looking_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NotificationDispatchRequest(
            channel: NotificationChannel::Email,
            destinationReference: 'rcpt_abc',
            type: 'reservation_deposit_held',
            locale: 'en',
            subject: 's',
            body: 'b',
            context: ['otp' => '123456'],
        );
    }
}
