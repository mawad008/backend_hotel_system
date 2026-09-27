<?php

namespace Tests\Unit\Notification;

use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Exceptions\InvalidNotificationStatusTransitionException;
use App\Domain\Notification\StateMachine\NotificationDeliveryStateMachine;
use PHPUnit\Framework\TestCase;

class NotificationDeliveryStateMachineTest extends TestCase
{
    public function test_the_approved_transition_table_is_exactly_the_documented_lifecycle(): void
    {
        $map = [];
        foreach (NotificationStatus::cases() as $from) {
            $map[$from->value] = array_map(
                fn (NotificationStatus $s) => $s->value,
                NotificationDeliveryStateMachine::allowedTransitions($from),
            );
        }

        $this->assertSame([
            'pending' => ['sending'],
            'sending' => ['sent', 'failed'],
            'sent' => [],
            'failed' => ['sending'],
        ], $map);
    }

    public function test_sent_is_terminal(): void
    {
        $this->assertTrue(NotificationDeliveryStateMachine::isTerminal(NotificationStatus::Sent));
        $this->assertFalse(NotificationDeliveryStateMachine::isTerminal(NotificationStatus::Failed));
        $this->assertFalse(NotificationDeliveryStateMachine::isTerminal(NotificationStatus::Pending));
    }

    public function test_failed_can_be_retried_but_sent_cannot_move(): void
    {
        $this->assertTrue(NotificationDeliveryStateMachine::canTransition(NotificationStatus::Failed, NotificationStatus::Sending));
        $this->assertFalse(NotificationDeliveryStateMachine::canTransition(NotificationStatus::Sent, NotificationStatus::Sending));
        $this->assertFalse(NotificationDeliveryStateMachine::canTransition(NotificationStatus::Sent, NotificationStatus::Failed));
    }

    public function test_a_status_never_transitions_to_itself(): void
    {
        foreach (NotificationStatus::cases() as $status) {
            $this->assertFalse(NotificationDeliveryStateMachine::canTransition($status, $status));
        }
    }

    public function test_assert_can_transition_throws_the_dedicated_exception(): void
    {
        $this->expectException(InvalidNotificationStatusTransitionException::class);

        NotificationDeliveryStateMachine::assertCanTransition(NotificationStatus::Pending, NotificationStatus::Sent);
    }

    public function test_assert_can_transition_is_silent_for_an_approved_move(): void
    {
        NotificationDeliveryStateMachine::assertCanTransition(NotificationStatus::Pending, NotificationStatus::Sending);
        NotificationDeliveryStateMachine::assertCanTransition(NotificationStatus::Sending, NotificationStatus::Sent);

        $this->expectNotToPerformAssertions();
    }
}
