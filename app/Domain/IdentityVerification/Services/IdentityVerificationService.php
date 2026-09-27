<?php

namespace App\Domain\IdentityVerification\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityVerification\DocumentCheck\DocumentCheckStatus;
use App\Domain\IdentityVerification\DocumentCheck\IdentityClaim;
use App\Domain\IdentityVerification\Exceptions\IdentityVerificationActionNotAllowedException;
use App\Domain\IdentityVerification\Exceptions\IdentityVerificationConfigurationMissingException;
use App\Domain\IdentityVerification\Exceptions\IdentityVerificationIdempotencyKeyConflictException;
use App\Domain\IdentityVerification\Exceptions\IdentityVerificationNotAllowedException;
use App\Domain\IdentityVerification\Exceptions\IdentityVerificationRetryNotAllowedException;
use App\Domain\IdentityVerification\Models\IdentityVerificationAttempt;
use App\Domain\IdentityVerification\Models\IdentityVerificationDecision;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\IdentityVerification\Provider\Contracts\IdentityVerificationProviderInterface;
use App\Domain\IdentityVerification\Provider\Data\VerificationRequest;
use App\Domain\IdentityVerification\Provider\Data\VerificationResult;
use App\Domain\IdentityVerification\Provider\SimulationDirective;
use App\Domain\IdentityVerification\Repositories\Contracts\IdentityVerificationAttemptRepositoryInterface;
use App\Domain\IdentityVerification\Repositories\Contracts\IdentityVerificationDecisionRepositoryInterface;
use App\Domain\IdentityVerification\Repositories\Contracts\IdentityVerificationSessionRepositoryInterface;
use App\Domain\IdentityVerification\StateMachine\IdentityVerificationStateMachine;
use App\Domain\IdentityVerification\Support\IdentityFileStore;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Repositories\Contracts\ReservationRepositoryInterface;
use App\Domain\Reservation\Services\ReservationService;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 6 — the Identity Verification workflow and its integration with the
 * Reservation workflow (Phase 0 §8/§10, R21-R25).
 *
 * ══ Absolute architectural rule ══
 * The provider (IdentityVerificationProviderInterface) is NEVER called while
 * a database transaction is open. The selfie/match flow is three explicit
 * stages, mirroring the Phase 5 payment workflow:
 *
 *   STEP A  (DB transaction)   lock Reservation -> Session -> Attempt,
 *                              validate, persist the selfie reference, move
 *                              the session to MATCHING_IN_PROGRESS, COMMIT.
 *   STEP B  (no transaction)   IdentityVerificationProviderInterface::verify.
 *   STEP C  (DB transaction)   re-lock, apply the normalized result, classify
 *                              it against the CONFIGURED confidence
 *                              thresholds, transition the session through
 *                              IdentityVerificationStateMachine, append a
 *                              decision row, drive the Reservation through
 *                              ReservationService when the identity passes,
 *                              audit, COMMIT.
 *
 * Dependency direction: IdentityVerificationService -> ReservationService.
 * ReservationService never depends on Identity Verification. Reservation
 * status changes always go through ReservationService::transitionTo(), the
 * only component allowed to drive ReservationStateMachine.
 *
 * The document upload follows the same rule (OCR document check):
 *
 *   STEP A  (DB transaction)   store the encrypted document on the attempt,
 *                              mark its document check `processing`, COMMIT.
 *   STEP B  (no transaction)   IdentityDocumentCheckService — OCR provider
 *                              extraction + comparison with the guest's claim.
 *   STEP C  (DB transaction)   re-lock, persist the outcome codes (never the
 *                              extracted values), delete a rejected document.
 *
 * The session stays DOCUMENT_UPLOADED throughout — the approved state
 * machine is unchanged. The selfie step only opens once the document check
 * is `verified` or `needs_review`, and a `needs_review` document can never
 * end in AUTO_APPROVED.
 *
 * No business number is ever invented here: confidence thresholds and the
 * retry limit are read from config('verification.*') and, when unset, the
 * workflow fails SAFE to PENDING_MANUAL_REVIEW (never auto-approve, never
 * auto-reject) — Phase 0 §10 "manual review is always reachable".
 */
class IdentityVerificationService
{
    /**
     * Session statuses from which submitting a document starts a fresh
     * attempt (as opposed to replacing the document on the current one).
     *
     * @var list<string>
     */
    private const NEW_ATTEMPT_STATUSES = [
        IdentityVerificationSession::STATUS_NOT_STARTED,
        IdentityVerificationSession::STATUS_RETRY_ALLOWED,
        IdentityVerificationSession::STATUS_STAFF_REJECTED,
    ];

    public function __construct(
        private readonly IdentityVerificationProviderInterface $provider,
        private readonly IdentityVerificationSessionRepositoryInterface $sessions,
        private readonly IdentityVerificationAttemptRepositoryInterface $attempts,
        private readonly IdentityVerificationDecisionRepositoryInterface $decisions,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly ReservationService $reservationService,
        private readonly IdentityFileStore $files,
        private readonly AuditLogger $auditLogger,
        private readonly IdentityDocumentCheckService $documentChecks,
    ) {}

    /** Latest attempt of a session (for the resource's document-check block). */
    public function latestAttempt(IdentityVerificationSession $session): ?IdentityVerificationAttempt
    {
        return $session->exists ? $this->attempts->latestForSession($session->id) : null;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Read
    // ═════════════════════════════════════════════════════════════════════

    /**
     * The verification session for a Reservation. When none exists yet a
     * transient NOT_STARTED session is returned (never persisted) so the
     * status endpoint can answer without a 404.
     */
    public function statusFor(Reservation $reservation): IdentityVerificationSession
    {
        return $this->sessions->findByReservation($reservation->id)
            ?? new IdentityVerificationSession([
                'reservation_id' => $reservation->id,
                'guest_id' => $reservation->guest_id,
                'hotel_id' => $reservation->hotel_id,
                'status' => IdentityVerificationSession::STATUS_NOT_STARTED,
                'provider' => $this->providerName(),
                'attempts' => 0,
            ]);
    }

    public function latestDecision(IdentityVerificationSession $session): ?IdentityVerificationDecision
    {
        if (! $session->exists) {
            return null;
        }

        return $this->decisions->latestForSession($session->id);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Document submission (no provider call)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Submit (or re-submit) the ID document for a Reservation's verification.
     *
     * Creates the session on first use, starts a new attempt from
     * NOT_STARTED / RETRY_ALLOWED / STAFF_REJECTED (subject to the
     * configured retry limit), or replaces the document on the current
     * DOCUMENT_UPLOADED attempt.
     *
     * MUST NOT be called from inside an open DB transaction.
     *
     * @throws ModelNotFoundException if the Reservation no longer exists
     * @throws IdentityVerificationNotAllowedException if the Reservation is not DEPOSIT_HELD
     * @throws IdentityVerificationRetryNotAllowedException if the retry limit is reached
     * @throws IdentityVerificationActionNotAllowedException if the session status forbids it
     */
    public function submitDocument(
        Reservation $reservation,
        UploadedFile $document,
        ?string $documentType = null,
        ?User $actor = null,
        ?IdentityClaim $claim = null,
        ?UploadedFile $backDocument = null,
    ): IdentityVerificationSession {
        $claim ??= new IdentityClaim(fullName: $reservation->guest?->name);
        $fingerprint = $this->files->fingerprint($document, $claim->canonical(), $backDocument, (string) $documentType);

        // ── STEP A ──
        $stepA = DB::transaction(function () use ($reservation, $document, $backDocument, $documentType, $actor, $fingerprint) {
            $locked = $this->reservations->findForUpdate($reservation->id);

            if (! $locked) {
                throw (new ModelNotFoundException)->setModel(Reservation::class, [$reservation->id]);
            }

            $session = $this->sessions->findByReservationForUpdate($locked->id);

            if ($session === null) {
                $this->assertReservationReady($locked);
                $session = $this->createSession($locked, $actor);

                return $this->startAttempt($session, $document, $backDocument, $documentType, $fingerprint, $actor) + ['checkIn' => $locked->check_in];
            }

            if (in_array($session->status, self::NEW_ATTEMPT_STATUSES, true)) {
                $this->assertReservationReady($locked);

                if (in_array($session->status, [
                    IdentityVerificationSession::STATUS_RETRY_ALLOWED,
                    IdentityVerificationSession::STATUS_STAFF_REJECTED,
                ], true)) {
                    $budget = $this->retryBudget($session);

                    if ($budget !== 'allowed') {
                        return ['session' => $this->routeRetryExhausted($session, $budget, $actor), 'check' => null];
                    }
                }

                return $this->startAttempt($session, $document, $backDocument, $documentType, $fingerprint, $actor) + ['checkIn' => $locked->check_in];
            }

            if ($session->status === IdentityVerificationSession::STATUS_DOCUMENT_UPLOADED) {
                return $this->replaceDocument($session, $document, $backDocument, $documentType, $fingerprint, $actor) + ['checkIn' => $locked->check_in];
            }

            throw new IdentityVerificationActionNotAllowedException('submit_document', $session->status);
        });

        if ($stepA['check'] === null) {
            return $stepA['session'];
        }

        ['attemptId' => $attemptId, 'path' => $path, 'type' => $type] = $stepA['check'];

        // ── STEP B ── OCR, OUTSIDE any DB transaction.
        $today = new DateTimeImmutable('today');
        $checkIn = $stepA['checkIn'] ?? null;
        $validOn = $checkIn !== null && $checkIn->toDateTimeImmutable() > $today ? $checkIn->toDateTimeImmutable() : $today;

        $result = $this->documentChecks->check(
            $attemptId,
            $path,
            $type,
            $claim,
            $validOn,
            backPath: $stepA['check']['backPath'] ?? null,
        );
        unset($claim);

        // ── STEP C ──
        return $this->applyDocumentCheck($stepA['session']->id, $attemptId, $path, $result, $actor);
    }

    /**
     * STEP C of the document upload — persist the check outcome codes.
     * A result for a document that has since been replaced is discarded.
     *
     * @param  array{outcome: \App\Domain\IdentityVerification\DocumentCheck\DocumentCheckOutcome, pending_artifact: string|null, duration_ms: int}  $result
     */
    private function applyDocumentCheck(int $sessionId, int $attemptId, string $path, array $result, ?User $actor): IdentityVerificationSession
    {
        return DB::transaction(function () use ($sessionId, $attemptId, $path, $result, $actor) {
            $session = $this->sessions->findForUpdate($sessionId);
            $attempt = $this->attempts->findForUpdate($attemptId);

            if ($session === null || $attempt === null) {
                throw (new ModelNotFoundException)->setModel(IdentityVerificationSession::class, [$sessionId]);
            }

            if ($result['pending_artifact'] !== null && $attempt->provider_artifact_ref === null) {
                $attempt = $this->attempts->update($attempt, ['provider_artifact_ref' => $result['pending_artifact']]);
            }

            if ($attempt->document_path !== $path
                || $attempt->document_check_status !== DocumentCheckStatus::Processing->value) {
                return $session; // superseded by a newer upload
            }

            $outcome = $result['outcome'];
            $cap = (int) config('verification.document_check.max_uploads_per_attempt', 5);

            // Manual review is always reachable: after the per-attempt upload
            // cap, a failing document goes to a human instead of another OCR run.
            if ($outcome->status->requiresNewDocument() && $cap > 0 && $attempt->document_uploads >= $cap) {
                $outcome = $outcome->withStatus(DocumentCheckStatus::NeedsReview, 'upload_limit_reached');
            }

            $rejected = $outcome->status->requiresNewDocument();
            $backPath = $attempt->document_back_path;

            $attempt = $this->attempts->update($attempt, [
                'document_check_status' => $outcome->status->value,
                'document_check' => $outcome->toArray() + ['duration_ms' => $result['duration_ms']],
                'document_checked_at' => now(),
                // A rejected document serves no further purpose — delete it now.
                'document_path' => $rejected ? null : $attempt->document_path,
                'document_back_path' => $rejected ? null : $backPath,
            ]);

            if ($rejected) {
                $this->files->delete($path);
                $this->files->delete($backPath);
            }

            $this->auditLogger->record(
                $actor,
                'identity_verification.document_checked',
                $session,
                after: $this->auditSnapshot($session, $attempt) + [
                    'document_check_status' => $outcome->status->value,
                    'document_check_reasons' => implode(',', $outcome->reasons),
                ],
                hotelId: $session->hotel_id,
            );

            return $session;
        });
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Selfie submission + match (Step A -> B -> C)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Submit the live selfie for the current attempt and run the provider
     * match. Returns the session in whatever post-match state the result
     * produced — a low/medium match or a provider error is a returned state,
     * not an exception.
     *
     * MUST NOT be called from inside an open DB transaction.
     *
     * @throws IdentityVerificationActionNotAllowedException if the session is not DOCUMENT_UPLOADED
     * @throws IdentityVerificationIdempotencyKeyConflictException if the key was used for another session
     */
    public function submitSelfie(
        Reservation $reservation,
        UploadedFile $selfie,
        ?string $idempotencyKey = null,
        ?SimulationDirective $directive = null,
        ?User $actor = null,
    ): IdentityVerificationSession {
        $key = $idempotencyKey ?? (string) Str::uuid();

        $stepA = $this->openMatchAttempt($reservation, $selfie, $key, $actor);

        if ($stepA['replay']) {
            $session = $this->sessions->find($stepA['sessionId']);

            if ($session === null) {
                throw (new ModelNotFoundException)->setModel(IdentityVerificationSession::class, [$stepA['sessionId']]);
            }

            return $session;
        }

        // ── STEP B ── provider call, OUTSIDE any DB transaction.
        $result = $this->provider->verify(new VerificationRequest(
            attemptReference: $key,
            documentType: $stepA['documentType'],
            directive: $directive,
        ));

        // ── STEP C ──
        return $this->applyMatchResult($stepA['sessionId'], $stepA['attemptId'], $result, $actor);
    }

    /**
     * STEP A — no provider call. One transaction that fully commits or fully
     * rolls back.
     *
     * @return array{replay: bool, sessionId: int, attemptId: int, documentType: string|null}
     */
    private function openMatchAttempt(
        Reservation $reservation,
        UploadedFile $selfie,
        string $key,
        ?User $actor,
    ): array {
        return DB::transaction(function () use ($reservation, $selfie, $key, $actor) {
            $locked = $this->reservations->findForUpdate($reservation->id);

            if (! $locked) {
                throw (new ModelNotFoundException)->setModel(Reservation::class, [$reservation->id]);
            }

            $session = $this->sessions->findByReservationForUpdate($locked->id);

            if ($session === null) {
                throw new IdentityVerificationActionNotAllowedException(
                    'submit_selfie',
                    IdentityVerificationSession::STATUS_NOT_STARTED,
                );
            }

            $attempt = $this->attempts->latestForSessionForUpdate($session->id);

            // Idempotent replay: the key already carries a match attempt.
            $existing = $this->attempts->findByIdempotencyKey($key);

            if ($existing !== null) {
                if ($existing->session_id !== $session->id) {
                    throw new IdentityVerificationIdempotencyKeyConflictException('different verification');
                }

                return $this->replayTuple($session, $existing);
            }

            if ($session->status !== IdentityVerificationSession::STATUS_DOCUMENT_UPLOADED) {
                if ($attempt !== null && $attempt->idempotency_key === $key) {
                    return $this->replayTuple($session, $attempt);
                }

                throw new IdentityVerificationActionNotAllowedException('submit_selfie', $session->status);
            }

            $documentCheck = $attempt?->documentCheckStatus();

            if ($documentCheck !== null && ! $documentCheck->allowsSelfie()) {
                throw new IdentityVerificationActionNotAllowedException('submit_selfie', 'document_check_'.$documentCheck->value);
            }

            if ($attempt === null || $attempt->document_path === null) {
                throw new IdentityVerificationActionNotAllowedException('submit_selfie', $session->status);
            }

            $selfiePath = $this->files->store($session->id, $attempt->id, IdentityFileStore::KIND_SELFIE, $selfie);

            try {
                $this->attempts->update($attempt, [
                    'selfie_path' => $selfiePath,
                    'idempotency_key' => $key,
                    'status' => IdentityVerificationAttempt::STATUS_MATCHING_IN_PROGRESS,
                    'submitted_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent selfie submission claimed the key first.
                $winner = $this->attempts->findByIdempotencyKey($key);

                if ($winner !== null && $winner->session_id === $session->id) {
                    return $this->replayTuple($session->refresh(), $winner);
                }

                throw new IdentityVerificationIdempotencyKeyConflictException('different verification');
            }

            IdentityVerificationStateMachine::assertCanTransition(
                $session->status,
                IdentityVerificationSession::STATUS_SELFIE_CAPTURED,
            );
            $session = $this->sessions->update($session, [
                'status' => IdentityVerificationSession::STATUS_SELFIE_CAPTURED,
            ]);

            IdentityVerificationStateMachine::assertCanTransition(
                $session->status,
                IdentityVerificationSession::STATUS_MATCHING_IN_PROGRESS,
            );
            $session = $this->sessions->update($session, [
                'status' => IdentityVerificationSession::STATUS_MATCHING_IN_PROGRESS,
            ]);

            $this->auditLogger->record(
                $actor,
                'identity_verification.selfie_submitted',
                $session,
                after: $this->auditSnapshot($session, $attempt->refresh()),
                hotelId: $session->hotel_id,
            );

            return [
                'replay' => false,
                'sessionId' => $session->id,
                'attemptId' => $attempt->id,
                'documentType' => $attempt->document_type,
            ];
        });
    }

    /**
     * STEP C — the reusable "apply a provider match result" capability.
     *
     * Locks are acquired in the approved order Reservation -> Session ->
     * Attempt; every record is re-read. Fully idempotent: a result for an
     * attempt that is already `completed` is a no-op that returns the
     * current session.
     *
     * No provider call happens here.
     */
    public function applyMatchResult(
        int $sessionId,
        int $attemptId,
        VerificationResult $result,
        ?User $actor = null,
    ): IdentityVerificationSession {
        return DB::transaction(function () use ($sessionId, $attemptId, $result, $actor) {
            $session = $this->sessions->findForUpdate($sessionId);

            if ($session === null) {
                throw (new ModelNotFoundException)->setModel(IdentityVerificationSession::class, [$sessionId]);
            }

            $reservation = $this->reservations->findForUpdate($session->reservation_id);
            $attempt = $this->attempts->findForUpdate($attemptId);

            if ($attempt === null) {
                throw (new ModelNotFoundException)->setModel(IdentityVerificationAttempt::class, [$attemptId]);
            }

            // Idempotent Step C: a duplicate result finds the attempt done.
            if ($attempt->status === IdentityVerificationAttempt::STATUS_COMPLETED) {
                return $session;
            }

            $attemptsUsed = $session->attempts + 1;
            [$targetStatus, $decisionResult, $band, $reason] = $this->classify(
                $result,
                $attemptsUsed,
                documentVerified: $attempt->documentCheckStatus() === DocumentCheckStatus::Verified,
            );

            $attempt = $this->attempts->update($attempt, [
                'status' => IdentityVerificationAttempt::STATUS_COMPLETED,
                'provider_reference' => $result->providerReference,
                'outcome' => $result->outcome->value,
                'score' => $result->score,
                'metadata' => $this->resultMetadata($result),
                'completed_at' => now(),
            ]);

            IdentityVerificationStateMachine::assertCanTransition($session->status, $targetStatus);

            $session = $this->sessions->update($session, [
                'status' => $targetStatus,
                'attempts' => $attemptsUsed,
                'latest_outcome' => $result->outcome->value,
                'latest_score' => $result->score,
                'decided_at' => $targetStatus === IdentityVerificationSession::STATUS_AUTO_APPROVED
                    ? now()
                    : $session->decided_at,
            ]);

            $this->decisions->create([
                'session_id' => $session->id,
                'attempt_id' => $attempt->id,
                'type' => IdentityVerificationDecision::TYPE_AUTOMATED,
                'result' => $decisionResult,
                'decided_by_user_id' => null,
                'score' => $result->score,
                'band' => $band,
                'reason' => null,
            ]);

            $this->auditLogger->record(
                $actor,
                $this->automatedAuditAction($targetStatus),
                $session,
                after: $this->auditSnapshot($session, $attempt) + array_filter([
                    'decision_result' => $decisionResult,
                    'band' => $band,
                    'config_note' => $reason,
                ]),
                hotelId: $session->hotel_id,
            );

            if ($targetStatus === IdentityVerificationSession::STATUS_AUTO_APPROVED) {
                $this->applyReservationVerified($reservation, $session, $actor);
            }

            return $session;
        });
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Manual review (no provider call)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Record a staff manual-review decision on a PENDING_MANUAL_REVIEW
     * session. `$decision` is 'approve' or 'reject'.
     *
     * @throws IdentityVerificationActionNotAllowedException if the session is not PENDING_MANUAL_REVIEW
     */
    public function review(
        Reservation $reservation,
        string $decision,
        ?string $reason,
        User $actor,
    ): IdentityVerificationSession {
        return DB::transaction(function () use ($reservation, $decision, $reason, $actor) {
            $locked = $this->reservations->findForUpdate($reservation->id);

            if (! $locked) {
                throw (new ModelNotFoundException)->setModel(Reservation::class, [$reservation->id]);
            }

            $session = $this->sessions->findByReservationForUpdate($locked->id);

            if ($session === null) {
                throw new IdentityVerificationActionNotAllowedException(
                    'review',
                    IdentityVerificationSession::STATUS_NOT_STARTED,
                );
            }

            if ($session->status !== IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW) {
                throw new IdentityVerificationActionNotAllowedException('review', $session->status);
            }

            $approve = $decision === 'approve';
            $targetStatus = $approve
                ? IdentityVerificationSession::STATUS_STAFF_APPROVED
                : IdentityVerificationSession::STATUS_STAFF_REJECTED;
            $decisionResult = $approve
                ? IdentityVerificationDecision::RESULT_STAFF_APPROVED
                : IdentityVerificationDecision::RESULT_STAFF_REJECTED;

            IdentityVerificationStateMachine::assertCanTransition($session->status, $targetStatus);

            $attempt = $this->attempts->latestForSession($session->id);

            $session = $this->sessions->update($session, [
                'status' => $targetStatus,
                'decided_at' => now(),
            ]);

            $this->decisions->create([
                'session_id' => $session->id,
                'attempt_id' => $attempt?->id,
                'type' => IdentityVerificationDecision::TYPE_MANUAL,
                'result' => $decisionResult,
                'decided_by_user_id' => $actor->id,
                'score' => $session->latest_score,
                'band' => null,
                'reason' => $reason,
            ]);

            $this->auditLogger->record(
                $actor,
                $approve ? 'identity_verification.staff_approved' : 'identity_verification.staff_rejected',
                $session,
                after: $this->auditSnapshot($session, $attempt) + ['decision_result' => $decisionResult],
                hotelId: $session->hotel_id,
            );

            if ($approve) {
                $this->applyReservationVerified($locked, $session, $actor);
            }

            return $session;
        });
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Internals
    // ═════════════════════════════════════════════════════════════════════

    private function assertReservationReady(Reservation $reservation): void
    {
        if ($reservation->status !== Reservation::STATUS_DEPOSIT_HELD) {
            throw new IdentityVerificationNotAllowedException($reservation->status);
        }
    }

    private function createSession(Reservation $reservation, ?User $actor): IdentityVerificationSession
    {
        $session = $this->sessions->create([
            'reservation_id' => $reservation->id,
            // Guest is referenced from the Reservation, never duplicated.
            'guest_id' => $reservation->guest_id,
            // Hotel is ALWAYS derived from the Reservation, never a client value.
            'hotel_id' => $reservation->hotel_id,
            'status' => IdentityVerificationStateMachine::INITIAL_STATUS,
            'provider' => $this->providerName(),
            'attempts' => 0,
        ]);

        $this->auditLogger->record(
            $actor,
            'identity_verification.session_started',
            $session,
            after: $this->auditSnapshot($session),
            hotelId: $session->hotel_id,
        );

        return $session;
    }

    /**
     * @return array{session: IdentityVerificationSession, check: array{attemptId: int, path: string, backPath: string|null, type: string|null}}
     */
    private function startAttempt(
        IdentityVerificationSession $session,
        UploadedFile $document,
        ?UploadedFile $backDocument,
        ?string $documentType,
        string $fingerprint,
        ?User $actor,
    ): array {
        $attempt = $this->attempts->create([
            'session_id' => $session->id,
            'attempt_number' => $session->attempts + 1,
            'status' => IdentityVerificationAttempt::STATUS_DOCUMENT_UPLOADED,
            'provider' => $this->providerName(),
            'idempotency_key' => (string) Str::uuid(),
            'document_type' => $documentType,
        ]);

        $documentPath = $this->files->store(
            $session->id,
            $attempt->id,
            IdentityFileStore::KIND_DOCUMENT,
            $document,
        );

        $backPath = $backDocument !== null
            ? $this->files->store($session->id, $attempt->id, IdentityFileStore::KIND_DOCUMENT_BACK, $backDocument)
            : null;

        $attempt = $this->attempts->update($attempt, [
            'document_path' => $documentPath,
            'document_back_path' => $backPath,
            'document_check_status' => DocumentCheckStatus::Processing->value,
            'document_check_provider' => $this->documentChecks->providerName(),
            'document_fingerprint' => $fingerprint,
            'document_uploads' => 1,
        ]);

        IdentityVerificationStateMachine::assertCanTransition(
            $session->status,
            IdentityVerificationSession::STATUS_DOCUMENT_UPLOADED,
        );
        $session = $this->sessions->update($session, [
            'status' => IdentityVerificationSession::STATUS_DOCUMENT_UPLOADED,
        ]);

        $this->auditLogger->record(
            $actor,
            'identity_verification.document_submitted',
            $session,
            after: $this->auditSnapshot($session, $attempt),
            hotelId: $session->hotel_id,
        );

        return ['session' => $session, 'check' => ['attemptId' => $attempt->id, 'path' => $documentPath, 'backPath' => $backPath, 'type' => $documentType]];
    }

    /**
     * @return array{session: IdentityVerificationSession, check: array{attemptId: int, path: string, backPath: string|null, type: string|null}|null}
     */
    private function replaceDocument(
        IdentityVerificationSession $session,
        UploadedFile $document,
        ?UploadedFile $backDocument,
        ?string $documentType,
        string $fingerprint,
        ?User $actor,
    ): array {
        $attempt = $this->attempts->latestForSessionForUpdate($session->id);

        if ($attempt === null) {
            throw new IdentityVerificationActionNotAllowedException('submit_document', $session->status);
        }

        $check = $attempt->documentCheckStatus();

        // A check still running for this attempt: refuse a parallel upload
        // (unless it is stale — the worker died mid-OCR).
        if ($check === DocumentCheckStatus::Processing
            && $attempt->updated_at !== null
            && $attempt->updated_at->gt(now()->subSeconds((int) config('verification.document_check.processing_stale_seconds', 120)))) {
            throw new IdentityVerificationActionNotAllowedException('submit_document', 'document_check_processing');
        }

        // Identical re-upload (same bytes + same claim) of an already-checked
        // document: return the stored result — no second OCR call / charge.
        if ($check !== null && $check !== DocumentCheckStatus::Processing
            && $attempt->document_fingerprint !== null
            && hash_equals($attempt->document_fingerprint, $fingerprint)) {
            return ['session' => $session, 'check' => null];
        }

        $previousPath = $attempt->document_path;
        $previousBackPath = $attempt->document_back_path;

        $documentPath = $this->files->store(
            $session->id,
            $attempt->id,
            IdentityFileStore::KIND_DOCUMENT,
            $document,
        );

        $backPath = $backDocument !== null
            ? $this->files->store($session->id, $attempt->id, IdentityFileStore::KIND_DOCUMENT_BACK, $backDocument)
            : null;

        $attempt = $this->attempts->update($attempt, [
            'document_path' => $documentPath,
            'document_back_path' => $backPath,
            'document_type' => $documentType ?? $attempt->document_type,
            'document_check_status' => DocumentCheckStatus::Processing->value,
            'document_check' => null,
            'document_check_provider' => $this->documentChecks->providerName(),
            'document_fingerprint' => $fingerprint,
            'document_uploads' => min(255, $attempt->document_uploads + 1),
        ]);

        if ($previousPath !== null && $previousPath !== $documentPath) {
            $this->files->delete($previousPath);
        }

        if ($previousBackPath !== null && $previousBackPath !== $backPath) {
            $this->files->delete($previousBackPath);
        }

        $this->auditLogger->record(
            $actor,
            'identity_verification.document_submitted',
            $session,
            after: $this->auditSnapshot($session, $attempt),
            hotelId: $session->hotel_id,
        );

        return ['session' => $session, 'check' => ['attemptId' => $attempt->id, 'path' => $documentPath, 'backPath' => $backPath, 'type' => $attempt->document_type]];
    }

    /**
     * Route a RETRY_ALLOWED session that has no retry budget left (or a
     * missing retry-limit config) to PENDING_MANUAL_REVIEW — Phase 0 §10
     * "routed to PENDING_MANUAL_REVIEW ... rather than allowing further
     * auto-retry". STAFF_REJECTED with no budget is a legitimate resting
     * state (a human already decided) — surfaced as an exception instead.
     */
    private function routeRetryExhausted(
        IdentityVerificationSession $session,
        string $budget,
        ?User $actor,
    ): IdentityVerificationSession {
        if ($session->status === IdentityVerificationSession::STATUS_STAFF_REJECTED) {
            if ($budget === 'unconfigured') {
                throw new IdentityVerificationConfigurationMissingException('verification.max_retries');
            }

            throw new IdentityVerificationRetryNotAllowedException($session->status);
        }

        IdentityVerificationStateMachine::assertCanTransition(
            $session->status,
            IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW,
        );

        $session = $this->sessions->update($session, [
            'status' => IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW,
        ]);

        $this->decisions->create([
            'session_id' => $session->id,
            'attempt_id' => $this->attempts->latestForSession($session->id)?->id,
            'type' => IdentityVerificationDecision::TYPE_AUTOMATED,
            'result' => IdentityVerificationDecision::RESULT_RETRY_EXHAUSTED,
            'decided_by_user_id' => null,
            'score' => $session->latest_score,
            'band' => null,
            'reason' => null,
        ]);

        $this->auditLogger->record(
            $actor,
            'identity_verification.retry_exhausted',
            $session,
            after: $this->auditSnapshot($session) + [
                'config_note' => $budget === 'unconfigured' ? 'verification.max_retries not set' : 'retry limit reached',
            ],
            hotelId: $session->hotel_id,
        );

        return $session;
    }

    /**
     * @return 'allowed'|'exhausted'|'unconfigured'
     */
    private function retryBudget(IdentityVerificationSession $session): string
    {
        $max = config('verification.max_retries');

        if ($max === null || $max === '') {
            return 'unconfigured';
        }

        // $session->attempts = completed match attempts so far. The attempt
        // about to start is retry number $session->attempts (1-based).
        return $session->attempts <= (int) $max ? 'allowed' : 'exhausted';
    }

    /**
     * Classify a provider result against the CONFIGURED thresholds / retry
     * limit. Returns [sessionTargetStatus, decisionResult, band, configNote].
     *
     * @return array{0: string, 1: string, 2: string|null, 3: string|null}
     */
    private function classify(VerificationResult $result, int $attemptsUsed, bool $documentVerified = false): array
    {
        if ($result->isError()) {
            $max = config('verification.max_retries');

            if ($max === null || $max === '') {
                return [
                    IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW,
                    IdentityVerificationDecision::RESULT_RETRY_EXHAUSTED,
                    null,
                    'verification.max_retries not set',
                ];
            }

            $retriesUsed = $attemptsUsed - 1;

            if ($retriesUsed < (int) $max) {
                return [
                    IdentityVerificationSession::STATUS_RETRY_ALLOWED,
                    IdentityVerificationDecision::RESULT_RETRY_ALLOWED,
                    null,
                    null,
                ];
            }

            return [
                IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW,
                IdentityVerificationDecision::RESULT_RETRY_EXHAUSTED,
                null,
                'retry limit reached',
            ];
        }

        $autoThreshold = config('verification.thresholds.auto_approve');

        if ($autoThreshold === null || $autoThreshold === '') {
            // No number invented — fail safe to manual review.
            return [
                IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW,
                IdentityVerificationDecision::RESULT_MANUAL_REVIEW_REQUIRED,
                null,
                'verification.thresholds.auto_approve not set',
            ];
        }

        if ($result->score !== null && $result->score >= (int) $autoThreshold) {
            if (! $documentVerified) {
                // The OCR document check did not fully verify the document
                // (needs_review, or an attempt from before OCR existed): a
                // human decides, however good the face match.
                return [
                    IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW,
                    IdentityVerificationDecision::RESULT_MANUAL_REVIEW_REQUIRED,
                    IdentityVerificationDecision::BAND_HIGH,
                    'document check requires review',
                ];
            }

            return [
                IdentityVerificationSession::STATUS_AUTO_APPROVED,
                IdentityVerificationDecision::RESULT_AUTO_APPROVED,
                IdentityVerificationDecision::BAND_HIGH,
                null,
            ];
        }

        $manualThreshold = config('verification.thresholds.manual_review');
        $band = ($manualThreshold !== null && $manualThreshold !== ''
            && $result->score !== null && $result->score >= (int) $manualThreshold)
            ? IdentityVerificationDecision::BAND_MEDIUM
            : IdentityVerificationDecision::BAND_LOW;

        return [
            IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW,
            IdentityVerificationDecision::RESULT_MANUAL_REVIEW_REQUIRED,
            $band,
            null,
        ];
    }

    /**
     * Drive the Reservation DEPOSIT_HELD -> VERIFIED transition when the
     * identity passes (Phase 0 §8). Never bypasses ReservationService. A
     * Reservation that has already left DEPOSIT_HELD is left exactly as it
     * is and the divergence is recorded for reconciliation — mirroring the
     * Phase 5 late-success handling.
     */
    private function applyReservationVerified(
        ?Reservation $reservation,
        IdentityVerificationSession $session,
        ?User $actor,
    ): void {
        if ($reservation !== null && $reservation->status === Reservation::STATUS_DEPOSIT_HELD) {
            $this->reservationService->transitionTo($reservation, Reservation::STATUS_VERIFIED, $actor);

            $this->auditLogger->record(
                $actor,
                'identity_verification.reservation_verified',
                $session,
                after: $this->auditSnapshot($session) + ['reservation_status' => Reservation::STATUS_VERIFIED],
                hotelId: $session->hotel_id,
            );

            return;
        }

        $this->auditLogger->record(
            $actor,
            'identity_verification.approved_reservation_not_ready',
            $session,
            after: $this->auditSnapshot($session) + [
                'reservation_status' => $reservation?->status,
                'requires_reconciliation' => true,
            ],
            hotelId: $session->hotel_id,
        );
    }

    /**
     * @return array{replay: true, sessionId: int, attemptId: int, documentType: string|null}
     */
    private function replayTuple(IdentityVerificationSession $session, IdentityVerificationAttempt $attempt): array
    {
        return [
            'replay' => true,
            'sessionId' => $session->id,
            'attemptId' => $attempt->id,
            'documentType' => $attempt->document_type,
        ];
    }

    private function automatedAuditAction(string $targetStatus): string
    {
        return match ($targetStatus) {
            IdentityVerificationSession::STATUS_AUTO_APPROVED => 'identity_verification.auto_approved',
            IdentityVerificationSession::STATUS_RETRY_ALLOWED => 'identity_verification.retry_allowed',
            default => 'identity_verification.manual_review_required',
        };
    }

    private function providerName(): string
    {
        return (string) config('verification.provider');
    }

    /**
     * Fold only the safe, non-PII parts of a provider result into the
     * attempt metadata. The provider contract already guarantees no secret /
     * document number / raw payload reaches here.
     *
     * @return array<string, mixed>
     */
    private function resultMetadata(VerificationResult $result): array
    {
        return [
            'provider_code' => $result->providerCode,
            'provider_message' => $result->message,
        ];
    }

    /**
     * A safe, flat snapshot for the audit trail — business status fields
     * only. Never a document number, a file path, a selfie, raw provider
     * payload, or any PII (Phase 0 §17, Phase 6 audit rule).
     *
     * @return array<string, mixed>
     */
    private function auditSnapshot(
        IdentityVerificationSession $session,
        ?IdentityVerificationAttempt $attempt = null,
    ): array {
        return array_filter([
            'session_status' => $session->status,
            'attempts' => $session->attempts,
            'latest_outcome' => $session->latest_outcome,
            'latest_score' => $session->latest_score,
            'provider' => $session->provider,
            'attempt_number' => $attempt?->attempt_number,
            'attempt_status' => $attempt?->status,
            'provider_reference' => $attempt?->provider_reference,
        ], fn ($value) => $value !== null);
    }
}
