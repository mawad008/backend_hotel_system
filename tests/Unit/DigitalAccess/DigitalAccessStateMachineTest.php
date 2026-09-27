<?php

namespace Tests\Unit\DigitalAccess;

use App\Domain\DigitalAccess\Exceptions\InvalidDigitalAccessStatusTransitionException;
use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\DigitalAccess\StateMachine\DigitalAccessStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 7 — the approved Digital Access state machine (Phase 0 §11). These
 * tests are the executable copy of the transition table; nothing else in the
 * codebase is allowed to define transition rules.
 */
class DigitalAccessStateMachineTest extends TestCase
{
    /**
     * @return array<string, list<string>>
     */
    private function approvedMap(): array
    {
        return [
            'not_issued' => ['issue_requested'],
            'issue_requested' => ['active', 'failed'],
            'failed' => ['issue_requested'],
            'active' => ['revoke_requested', 'expired'],
            'revoke_requested' => ['revoked'],
            'revoked' => [],
            'expired' => [],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function approvedTransitions(): array
    {
        return [
            'NOT_ISSUED -> ISSUE_REQUESTED' => ['not_issued', 'issue_requested'],
            'ISSUE_REQUESTED -> ACTIVE' => ['issue_requested', 'active'],
            'ISSUE_REQUESTED -> FAILED' => ['issue_requested', 'failed'],
            'FAILED -> ISSUE_REQUESTED (retry)' => ['failed', 'issue_requested'],
            'ACTIVE -> REVOKE_REQUESTED' => ['active', 'revoke_requested'],
            'ACTIVE -> EXPIRED' => ['active', 'expired'],
            'REVOKE_REQUESTED -> REVOKED' => ['revoke_requested', 'revoked'],
        ];
    }

    #[DataProvider('approvedTransitions')]
    public function test_approved_transition_is_allowed(string $from, string $to): void
    {
        $this->assertTrue(DigitalAccessStateMachine::canTransition($from, $to));
        DigitalAccessStateMachine::assertCanTransition($from, $to);
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function forbiddenTransitions(): array
    {
        return [
            'NOT_ISSUED -> ACTIVE (skips issue_requested)' => ['not_issued', 'active'],
            'NOT_ISSUED -> NOT_ISSUED (no self-loop)' => ['not_issued', 'not_issued'],
            'ISSUE_REQUESTED -> REVOKED' => ['issue_requested', 'revoked'],
            'ISSUE_REQUESTED -> EXPIRED' => ['issue_requested', 'expired'],
            'ACTIVE -> ISSUE_REQUESTED (no re-issue of an active grant)' => ['active', 'issue_requested'],
            'ACTIVE -> REVOKED (must go through revoke_requested)' => ['active', 'revoked'],
            'ACTIVE -> ACTIVE (no self-loop)' => ['active', 'active'],
            'FAILED -> ACTIVE (must re-request first)' => ['failed', 'active'],
            'REVOKE_REQUESTED -> ACTIVE (cannot un-revoke)' => ['revoke_requested', 'active'],
            'terminal REVOKED -> anything' => ['revoked', 'issue_requested'],
            'terminal EXPIRED -> anything' => ['expired', 'active'],
            'EXPIRED -> REVOKED' => ['expired', 'revoked'],
            'unknown source status' => ['not_a_status', 'issue_requested'],
            'unknown target status' => ['active', 'not_a_status'],
        ];
    }

    #[DataProvider('forbiddenTransitions')]
    public function test_forbidden_transition_is_rejected(string $from, string $to): void
    {
        $this->assertFalse(DigitalAccessStateMachine::canTransition($from, $to));
    }

    #[DataProvider('forbiddenTransitions')]
    public function test_forbidden_transition_throws_the_dedicated_exception(string $from, string $to): void
    {
        try {
            DigitalAccessStateMachine::assertCanTransition($from, $to);
            $this->fail("Expected {$from} -> {$to} to be rejected.");
        } catch (InvalidDigitalAccessStatusTransitionException $e) {
            $this->assertSame($from, $e->from);
            $this->assertSame($to, $e->to);
            $this->assertStringNotContainsString('array', $e->getMessage());
            $this->assertStringNotContainsString('\\', $e->getMessage());
        }
    }

    public function test_terminal_statuses_have_no_outgoing_transitions(): void
    {
        foreach ([AccessGrant::STATUS_REVOKED, AccessGrant::STATUS_EXPIRED] as $status) {
            $this->assertTrue(DigitalAccessStateMachine::isTerminal($status), "{$status} must be terminal");
            $this->assertSame([], DigitalAccessStateMachine::allowedTransitions($status));
        }
    }

    public function test_non_terminal_statuses_report_not_terminal(): void
    {
        foreach ([
            AccessGrant::STATUS_NOT_ISSUED,
            AccessGrant::STATUS_ISSUE_REQUESTED,
            AccessGrant::STATUS_ACTIVE,
            AccessGrant::STATUS_FAILED,
            AccessGrant::STATUS_REVOKE_REQUESTED,
        ] as $status) {
            $this->assertFalse(DigitalAccessStateMachine::isTerminal($status), "{$status} must not be terminal");
        }
    }

    public function test_unknown_status_is_not_terminal(): void
    {
        $this->assertFalse(DigitalAccessStateMachine::isTerminal('not_a_status'));
    }

    public function test_transition_map_matches_the_approved_table_exactly(): void
    {
        $this->assertSame($this->approvedMap(), DigitalAccessStateMachine::TRANSITIONS);
    }

    public function test_statuses_are_exactly_the_model_status_constants(): void
    {
        $constants = AccessGrant::STATUSES;
        sort($constants);
        $nodes = DigitalAccessStateMachine::statuses();
        sort($nodes);

        $this->assertSame($constants, $nodes);
    }

    public function test_no_transition_targets_an_unknown_status_or_itself(): void
    {
        $known = DigitalAccessStateMachine::statuses();

        foreach (DigitalAccessStateMachine::TRANSITIONS as $from => $targets) {
            foreach ($targets as $target) {
                $this->assertContains($target, $known, "{$from} -> {$target} targets an unknown status");
                $this->assertNotSame($from, $target, "{$from} must not transition to itself");
            }
        }
    }

    public function test_exhaustive_can_transition_matches_the_approved_map(): void
    {
        $map = $this->approvedMap();

        foreach (DigitalAccessStateMachine::statuses() as $from) {
            foreach (DigitalAccessStateMachine::statuses() as $to) {
                $expected = in_array($to, $map[$from], true);
                $this->assertSame(
                    $expected,
                    DigitalAccessStateMachine::canTransition($from, $to),
                    "canTransition({$from}, {$to}) should be ".($expected ? 'true' : 'false'),
                );
            }
        }
    }

    public function test_initial_status_is_not_issued(): void
    {
        $this->assertSame(AccessGrant::STATUS_NOT_ISSUED, DigitalAccessStateMachine::INITIAL_STATUS);
    }

    public function test_a_grant_can_never_reach_active_without_passing_through_issue_requested(): void
    {
        // §11: eligibility is checked before issuance; the staged flow always
        // records ISSUE_REQUESTED first.
        foreach (DigitalAccessStateMachine::statuses() as $from) {
            if ($from === AccessGrant::STATUS_ISSUE_REQUESTED) {
                continue;
            }
            $this->assertNotContains(
                AccessGrant::STATUS_ACTIVE,
                DigitalAccessStateMachine::allowedTransitions($from),
                "{$from} must not jump straight to active",
            );
        }
    }
}
