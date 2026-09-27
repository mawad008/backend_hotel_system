<?php

namespace Tests\Unit\Checkout;

use App\Domain\Checkout\Exceptions\InvalidCheckoutStatusTransitionException;
use App\Domain\Checkout\Models\Checkout;
use App\Domain\Checkout\StateMachine\CheckoutStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CheckoutStateMachineTest extends TestCase
{
    public function test_initial_status(): void
    {
        $this->assertSame(Checkout::STATUS_IN_PROGRESS, CheckoutStateMachine::INITIAL_STATUS);
    }

    public function test_map_covers_exactly_the_model_vocabulary(): void
    {
        $this->assertEqualsCanonicalizing(Checkout::STATUSES, CheckoutStateMachine::statuses());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function allowed(): array
    {
        return [
            'in_progress -> completed' => [Checkout::STATUS_IN_PROGRESS, Checkout::STATUS_COMPLETED],
            'in_progress -> awaiting' => [Checkout::STATUS_IN_PROGRESS, Checkout::STATUS_AWAITING_SETTLEMENT],
            'in_progress -> failed' => [Checkout::STATUS_IN_PROGRESS, Checkout::STATUS_SETTLEMENT_FAILED],
            'awaiting -> in_progress (retry)' => [Checkout::STATUS_AWAITING_SETTLEMENT, Checkout::STATUS_IN_PROGRESS],
            'awaiting -> completed' => [Checkout::STATUS_AWAITING_SETTLEMENT, Checkout::STATUS_COMPLETED],
            'failed -> in_progress (retry)' => [Checkout::STATUS_SETTLEMENT_FAILED, Checkout::STATUS_IN_PROGRESS],
            'failed -> completed' => [Checkout::STATUS_SETTLEMENT_FAILED, Checkout::STATUS_COMPLETED],
        ];
    }

    #[DataProvider('allowed')]
    public function test_allowed_transitions(string $from, string $to): void
    {
        $this->assertTrue(CheckoutStateMachine::canTransition($from, $to));
        CheckoutStateMachine::assertCanTransition($from, $to);
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function forbidden(): array
    {
        return [
            'completed -> anything' => [Checkout::STATUS_COMPLETED, Checkout::STATUS_IN_PROGRESS],
            'completed -> awaiting' => [Checkout::STATUS_COMPLETED, Checkout::STATUS_AWAITING_SETTLEMENT],
            'self transition' => [Checkout::STATUS_IN_PROGRESS, Checkout::STATUS_IN_PROGRESS],
            'unknown from' => ['banana', Checkout::STATUS_COMPLETED],
        ];
    }

    #[DataProvider('forbidden')]
    public function test_forbidden_transitions(string $from, string $to): void
    {
        $this->assertFalse(CheckoutStateMachine::canTransition($from, $to));

        $this->expectException(InvalidCheckoutStatusTransitionException::class);
        CheckoutStateMachine::assertCanTransition($from, $to);
    }

    public function test_completed_is_the_only_terminal_status(): void
    {
        $this->assertTrue(CheckoutStateMachine::isTerminal(Checkout::STATUS_COMPLETED));

        foreach ([Checkout::STATUS_IN_PROGRESS, Checkout::STATUS_AWAITING_SETTLEMENT, Checkout::STATUS_SETTLEMENT_FAILED] as $s) {
            $this->assertFalse(CheckoutStateMachine::isTerminal($s));
        }
    }
}
