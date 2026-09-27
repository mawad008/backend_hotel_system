<?php

namespace Tests\Unit\IdentityVerification;

use App\Domain\IdentityVerification\Exceptions\InvalidIdentityVerificationStatusTransitionException;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\IdentityVerification\StateMachine\IdentityVerificationStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 6 — the approved Identity Verification state machine (Phase 0 §10).
 * These tests are the executable copy of the transition table; nothing else
 * in the codebase is allowed to define transition rules.
 */
class IdentityVerificationStateMachineTest extends TestCase
{
    /**
     * The complete approved transition table, restated independently of the
     * production constant so drift in either direction fails.
     *
     * @return array<string, list<string>>
     */
    private function approvedMap(): array
    {
        return [
            'not_started' => ['document_uploaded'],
            'document_uploaded' => ['selfie_captured'],
            'selfie_captured' => ['matching_in_progress'],
            'matching_in_progress' => ['auto_approved', 'pending_manual_review', 'retry_allowed'],
            'retry_allowed' => ['document_uploaded', 'pending_manual_review'],
            'pending_manual_review' => ['staff_approved', 'staff_rejected'],
            'staff_rejected' => ['document_uploaded'],
            'auto_approved' => [],
            'staff_approved' => [],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function approvedTransitions(): array
    {
        return [
            'NOT_STARTED -> DOCUMENT_UPLOADED' => ['not_started', 'document_uploaded'],
            'DOCUMENT_UPLOADED -> SELFIE_CAPTURED' => ['document_uploaded', 'selfie_captured'],
            'SELFIE_CAPTURED -> MATCHING_IN_PROGRESS' => ['selfie_captured', 'matching_in_progress'],
            'MATCHING_IN_PROGRESS -> AUTO_APPROVED' => ['matching_in_progress', 'auto_approved'],
            'MATCHING_IN_PROGRESS -> PENDING_MANUAL_REVIEW' => ['matching_in_progress', 'pending_manual_review'],
            'MATCHING_IN_PROGRESS -> RETRY_ALLOWED' => ['matching_in_progress', 'retry_allowed'],
            'RETRY_ALLOWED -> DOCUMENT_UPLOADED' => ['retry_allowed', 'document_uploaded'],
            'RETRY_ALLOWED -> PENDING_MANUAL_REVIEW' => ['retry_allowed', 'pending_manual_review'],
            'PENDING_MANUAL_REVIEW -> STAFF_APPROVED' => ['pending_manual_review', 'staff_approved'],
            'PENDING_MANUAL_REVIEW -> STAFF_REJECTED' => ['pending_manual_review', 'staff_rejected'],
            'STAFF_REJECTED -> DOCUMENT_UPLOADED' => ['staff_rejected', 'document_uploaded'],
        ];
    }

    #[DataProvider('approvedTransitions')]
    public function test_approved_transition_is_allowed(string $from, string $to): void
    {
        $this->assertTrue(IdentityVerificationStateMachine::canTransition($from, $to));

        IdentityVerificationStateMachine::assertCanTransition($from, $to);
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function forbiddenTransitions(): array
    {
        return [
            'NOT_STARTED -> SELFIE_CAPTURED (skips document)' => ['not_started', 'selfie_captured'],
            'NOT_STARTED -> AUTO_APPROVED' => ['not_started', 'auto_approved'],
            'NOT_STARTED -> NOT_STARTED (no self-loop)' => ['not_started', 'not_started'],
            'DOCUMENT_UPLOADED -> MATCHING_IN_PROGRESS (skips selfie)' => ['document_uploaded', 'matching_in_progress'],
            'MATCHING_IN_PROGRESS -> STAFF_APPROVED (no auto-staff)' => ['matching_in_progress', 'staff_approved'],
            'MATCHING_IN_PROGRESS -> MATCHING_IN_PROGRESS (no self-loop)' => ['matching_in_progress', 'matching_in_progress'],
            'PENDING_MANUAL_REVIEW -> AUTO_APPROVED (never auto after review)' => ['pending_manual_review', 'auto_approved'],
            'PENDING_MANUAL_REVIEW -> DOCUMENT_UPLOADED (must decide first)' => ['pending_manual_review', 'document_uploaded'],
            'terminal AUTO_APPROVED -> anything' => ['auto_approved', 'document_uploaded'],
            'terminal STAFF_APPROVED -> anything' => ['staff_approved', 'staff_rejected'],
            'STAFF_REJECTED -> STAFF_APPROVED (cannot flip a decision)' => ['staff_rejected', 'staff_approved'],
            'STAFF_REJECTED -> PENDING_MANUAL_REVIEW' => ['staff_rejected', 'pending_manual_review'],
            'backwards: SELFIE_CAPTURED -> DOCUMENT_UPLOADED' => ['selfie_captured', 'document_uploaded'],
            'backwards: RETRY_ALLOWED -> MATCHING_IN_PROGRESS' => ['retry_allowed', 'matching_in_progress'],
            'unknown source status' => ['not_a_status', 'document_uploaded'],
            'unknown target status' => ['document_uploaded', 'not_a_status'],
        ];
    }

    #[DataProvider('forbiddenTransitions')]
    public function test_forbidden_transition_is_rejected(string $from, string $to): void
    {
        $this->assertFalse(IdentityVerificationStateMachine::canTransition($from, $to));
    }

    #[DataProvider('forbiddenTransitions')]
    public function test_forbidden_transition_throws_the_dedicated_exception(string $from, string $to): void
    {
        try {
            IdentityVerificationStateMachine::assertCanTransition($from, $to);
            $this->fail("Expected {$from} -> {$to} to be rejected.");
        } catch (InvalidIdentityVerificationStatusTransitionException $e) {
            $this->assertSame($from, $e->from);
            $this->assertSame($to, $e->to);
            $this->assertStringNotContainsString('array', $e->getMessage());
            $this->assertStringNotContainsString('\\', $e->getMessage());
        }
    }

    public function test_terminal_statuses_have_no_outgoing_transitions(): void
    {
        foreach ([
            IdentityVerificationSession::STATUS_AUTO_APPROVED,
            IdentityVerificationSession::STATUS_STAFF_APPROVED,
        ] as $status) {
            $this->assertTrue(IdentityVerificationStateMachine::isTerminal($status), "{$status} must be terminal");
            $this->assertSame([], IdentityVerificationStateMachine::allowedTransitions($status));
        }
    }

    public function test_non_terminal_statuses_report_not_terminal(): void
    {
        foreach ([
            IdentityVerificationSession::STATUS_NOT_STARTED,
            IdentityVerificationSession::STATUS_DOCUMENT_UPLOADED,
            IdentityVerificationSession::STATUS_SELFIE_CAPTURED,
            IdentityVerificationSession::STATUS_MATCHING_IN_PROGRESS,
            IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW,
            IdentityVerificationSession::STATUS_RETRY_ALLOWED,
            IdentityVerificationSession::STATUS_STAFF_REJECTED,
        ] as $status) {
            $this->assertFalse(IdentityVerificationStateMachine::isTerminal($status), "{$status} must not be terminal");
        }
    }

    public function test_unknown_status_is_not_terminal(): void
    {
        $this->assertFalse(IdentityVerificationStateMachine::isTerminal('not_a_status'));
    }

    public function test_transition_map_matches_the_approved_table_exactly(): void
    {
        $this->assertSame($this->approvedMap(), IdentityVerificationStateMachine::TRANSITIONS);
    }

    public function test_statuses_are_exactly_the_model_status_constants(): void
    {
        $constants = IdentityVerificationSession::STATUSES;
        sort($constants);

        $nodes = IdentityVerificationStateMachine::statuses();
        sort($nodes);

        $this->assertSame($constants, $nodes);
    }

    public function test_no_transition_targets_an_unknown_status_or_itself(): void
    {
        $known = IdentityVerificationStateMachine::statuses();

        foreach (IdentityVerificationStateMachine::TRANSITIONS as $from => $targets) {
            foreach ($targets as $target) {
                $this->assertContains($target, $known, "{$from} -> {$target} targets an unknown status");
                $this->assertNotSame($from, $target, "{$from} must not transition to itself");
            }
        }
    }

    public function test_exhaustive_can_transition_matches_the_approved_map(): void
    {
        $map = $this->approvedMap();

        foreach (IdentityVerificationStateMachine::statuses() as $from) {
            foreach (IdentityVerificationStateMachine::statuses() as $to) {
                $expected = in_array($to, $map[$from], true);

                $this->assertSame(
                    $expected,
                    IdentityVerificationStateMachine::canTransition($from, $to),
                    "canTransition({$from}, {$to}) should be ".($expected ? 'true' : 'false'),
                );
            }
        }
    }

    public function test_initial_status_is_not_started(): void
    {
        $this->assertSame(
            IdentityVerificationSession::STATUS_NOT_STARTED,
            IdentityVerificationStateMachine::INITIAL_STATUS,
        );
    }

    public function test_manual_review_is_always_reachable_from_every_non_terminal_status(): void
    {
        // Phase 0 §10: "Manual review is always reachable — no code path
        // allows a hard auto-reject that bypasses staff review."
        foreach (IdentityVerificationStateMachine::statuses() as $status) {
            if (IdentityVerificationStateMachine::isTerminal($status)) {
                continue;
            }

            $this->assertTrue(
                $this->canEventuallyReach($status, IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW),
                "{$status} must be able to reach pending_manual_review",
            );
        }
    }

    private function canEventuallyReach(string $from, string $target): bool
    {
        $seen = [];
        $queue = [$from];

        while ($queue !== []) {
            $current = array_shift($queue);

            if ($current === $target) {
                return true;
            }

            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;

            foreach (IdentityVerificationStateMachine::allowedTransitions($current) as $next) {
                $queue[] = $next;
            }
        }

        return false;
    }
}
