<?php

namespace Tests\Unit\StayServices;

use App\Domain\StayServices\Exceptions\InvalidServiceOrderStatusTransitionException;
use App\Domain\StayServices\Models\ServiceOrder;
use App\Domain\StayServices\StateMachine\ServiceOrderStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ServiceOrderStateMachineTest extends TestCase
{
    public function test_initial_status_is_requested(): void
    {
        $this->assertSame(ServiceOrder::STATUS_REQUESTED, ServiceOrderStateMachine::INITIAL_STATUS);
    }

    public function test_the_map_covers_exactly_the_model_status_vocabulary(): void
    {
        $this->assertEqualsCanonicalizing(
            ServiceOrder::STATUSES,
            ServiceOrderStateMachine::statuses(),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function allowed(): array
    {
        return [
            'requested -> confirmed' => [ServiceOrder::STATUS_REQUESTED, ServiceOrder::STATUS_CONFIRMED],
            'requested -> cancelled' => [ServiceOrder::STATUS_REQUESTED, ServiceOrder::STATUS_CANCELLED],
            'confirmed -> fulfilled' => [ServiceOrder::STATUS_CONFIRMED, ServiceOrder::STATUS_FULFILLED],
            'confirmed -> cancelled' => [ServiceOrder::STATUS_CONFIRMED, ServiceOrder::STATUS_CANCELLED],
        ];
    }

    #[DataProvider('allowed')]
    public function test_allowed_transitions(string $from, string $to): void
    {
        $this->assertTrue(ServiceOrderStateMachine::canTransition($from, $to));
        ServiceOrderStateMachine::assertCanTransition($from, $to);
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function forbidden(): array
    {
        return [
            'requested -> fulfilled' => [ServiceOrder::STATUS_REQUESTED, ServiceOrder::STATUS_FULFILLED],
            'confirmed -> requested' => [ServiceOrder::STATUS_CONFIRMED, ServiceOrder::STATUS_REQUESTED],
            'fulfilled -> cancelled' => [ServiceOrder::STATUS_FULFILLED, ServiceOrder::STATUS_CANCELLED],
            'fulfilled -> confirmed' => [ServiceOrder::STATUS_FULFILLED, ServiceOrder::STATUS_CONFIRMED],
            'cancelled -> requested' => [ServiceOrder::STATUS_CANCELLED, ServiceOrder::STATUS_REQUESTED],
            'cancelled -> confirmed' => [ServiceOrder::STATUS_CANCELLED, ServiceOrder::STATUS_CONFIRMED],
            'requested -> requested (self)' => [ServiceOrder::STATUS_REQUESTED, ServiceOrder::STATUS_REQUESTED],
            'unknown from' => ['banana', ServiceOrder::STATUS_CONFIRMED],
        ];
    }

    #[DataProvider('forbidden')]
    public function test_forbidden_transitions(string $from, string $to): void
    {
        $this->assertFalse(ServiceOrderStateMachine::canTransition($from, $to));

        $this->expectException(InvalidServiceOrderStatusTransitionException::class);
        ServiceOrderStateMachine::assertCanTransition($from, $to);
    }

    public function test_terminal_states(): void
    {
        $this->assertTrue(ServiceOrderStateMachine::isTerminal(ServiceOrder::STATUS_FULFILLED));
        $this->assertTrue(ServiceOrderStateMachine::isTerminal(ServiceOrder::STATUS_CANCELLED));
        $this->assertFalse(ServiceOrderStateMachine::isTerminal(ServiceOrder::STATUS_REQUESTED));
        $this->assertFalse(ServiceOrderStateMachine::isTerminal(ServiceOrder::STATUS_CONFIRMED));
        $this->assertFalse(ServiceOrderStateMachine::isTerminal('unknown'));
    }

    public function test_confirmed_is_never_reachable_without_passing_requested(): void
    {
        foreach (ServiceOrderStateMachine::statuses() as $from) {
            if ($from === ServiceOrder::STATUS_REQUESTED) {
                continue;
            }

            $this->assertNotContains(
                ServiceOrder::STATUS_CONFIRMED,
                ServiceOrderStateMachine::allowedTransitions($from),
                "{$from} must not jump straight to confirmed",
            );
        }
    }
}
