<?php

namespace App\Domain\DigitalAccess\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\DigitalAccess\Exceptions\CheckInEligibilityException;
use App\Domain\DigitalAccess\Exceptions\CheckInNotAllowedException;
use App\Domain\DigitalAccess\Exceptions\DigitalAccessActionNotAllowedException;
use App\Domain\DigitalAccess\Exceptions\DigitalAccessIdempotencyKeyConflictException;
use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\DigitalAccess\Provider\AccessResultStatus;
use App\Domain\DigitalAccess\Provider\Contracts\DigitalAccessProviderInterface;
use App\Domain\DigitalAccess\Provider\Data\AccessIssueRequest;
use App\Domain\DigitalAccess\Provider\Data\AccessOperationRequest;
use App\Domain\DigitalAccess\Provider\Data\AccessResult;
use App\Domain\DigitalAccess\Provider\SimulationDirective;
use App\Domain\DigitalAccess\Repositories\Contracts\AccessGrantRepositoryInterface;
use App\Domain\DigitalAccess\StateMachine\DigitalAccessStateMachine;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\IdentityVerification\Repositories\Contracts\IdentityVerificationSessionRepositoryInterface;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Repositories\Contracts\PaymentRepositoryInterface;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Repositories\Contracts\ReservationRepositoryInterface;
use App\Domain\Reservation\Services\ReservationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 7 — the Check-in + Digital Access workflow and its integration with
 * the Reservation workflow (Phase 0 §8/§11, R14-R19).
 *
 * ══ Absolute architectural rule ══
 * The provider (DigitalAccessProviderInterface) is NEVER called while a
 * database transaction is open. Both provider operations are three explicit
 * stages, mirroring the Phase 5 payment and Phase 6 identity workflows:
 *
 *   STEP A  (DB transaction)   lock Reservation -> AccessGrant, validate the
 *                              §8/§11 eligibility rules, create/advance the
 *                              grant to a *_REQUESTED pending state, audit,
 *                              COMMIT.
 *   STEP B  (no transaction)   DigitalAccessProviderInterface::issue / revoke.
 *   STEP C  (DB transaction)   re-lock in the SAME order, apply the normalized
 *                              result, transition the grant through
 *                              DigitalAccessStateMachine, drive the
 *                              Reservation through ReservationService when the
 *                              credential is active, audit, COMMIT.
 *
 * ══ Lock order (documented) ══
 *   Reservation  ->  AccessGrant
 * always, in every staged method. `expire()` and the status read take only
 * the AccessGrant lock (they never transition the Reservation), so they
 * cannot form a cycle with the two-lock methods.
 *
 * Dependency direction: DigitalAccessService -> ReservationService (and
 * read-only Payment / Identity repositories for the eligibility check).
 * ReservationService never depends on Digital Access. Reservation status
 * changes always go through ReservationService::transitionTo().
 *
 * §11: "Eligibility rules (payment + verification + time window) live
 * entirely in the application/domain layer, never inside the provider
 * adapter." — enforced by assertCheckInEligible() here.
 */
class DigitalAccessService
{
    public function __construct(
        private readonly DigitalAccessProviderInterface $provider,
        private readonly AccessGrantRepositoryInterface $grants,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly PaymentRepositoryInterface $payments,
        private readonly IdentityVerificationSessionRepositoryInterface $identitySessions,
        private readonly ReservationService $reservationService,
        private readonly AuditLogger $auditLogger,
    ) {}

    // ═════════════════════════════════════════════════════════════════════
    //  Read
    // ═════════════════════════════════════════════════════════════════════

    /**
     * The digital access grant for a Reservation. When none exists yet a
     * transient NOT_ISSUED grant is returned (never persisted). An ACTIVE
     * grant past its expiry is lazily transitioned to EXPIRED first
     * (§11: "Access validity is re-checked server-side at every access
     * attempt").
     */
    public function currentStatusFor(Reservation $reservation): AccessGrant
    {
        $grant = $this->grants->findByReservation($reservation->id);

        if ($grant === null) {
            return new AccessGrant([
                'reservation_id' => $reservation->id,
                'hotel_id' => $reservation->hotel_id,
                'guest_id' => $reservation->guest_id,
                'status' => AccessGrant::STATUS_NOT_ISSUED,
                'access_mode' => $this->accessMode(),
                'provider' => $this->providerName(),
            ]);
        }

        if ($this->isExpirable($grant)) {
            $grant = $this->expire($grant);
        }

        return $grant;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Check-in (= digital access issuance; §8 "(access issued)" -> CHECKED_IN)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Perform check-in for a VERIFIED Reservation: validate the §8/§11
     * eligibility rules, issue the digital access credential through the
     * provider (staged), and — only when the provider returns an ACTIVE
     * credential — transition the Reservation VERIFIED -> CHECKED_IN.
     *
     * A provider failure leaves the grant FAILED and the Reservation
     * untouched (recoverable: retry check-in). It never triggers a refund, a
     * cancellation, or any other payment/reservation side effect.
     *
     * MUST NOT be called from inside an open DB transaction.
     *
     * @throws ModelNotFoundException if the Reservation no longer exists
     * @throws CheckInNotAllowedException if the Reservation is not VERIFIED
     * @throws CheckInEligibilityException if a hard §8/§11 precondition is unmet
     * @throws DigitalAccessActionNotAllowedException if the grant status forbids it
     * @throws DigitalAccessIdempotencyKeyConflictException if the key was used for another reservation
     */
    public function checkIn(
        Reservation $reservation,
        ?SimulationDirective $directive = null,
        ?string $idempotencyKey = null,
        ?User $actor = null,
    ): AccessGrant {
        $key = $idempotencyKey ?? (string) Str::uuid();

        $stepA = $this->openIssueAttempt($reservation, $key, $directive, $actor);

        if ($stepA['replay']) {
            $grant = $this->grants->find($stepA['grantId']);

            if ($grant === null) {
                throw (new ModelNotFoundException)->setModel(AccessGrant::class, [$stepA['grantId']]);
            }

            return $grant;
        }

        // ── STEP B ── provider call, OUTSIDE any DB transaction.
        $result = $this->provider->issue(new AccessIssueRequest(
            grantReference: $key,
            accessMode: $stepA['accessMode'],
            directive: $directive,
            metadata: array_filter(['room_id' => $stepA['roomId']], fn ($v) => $v !== null),
        ));

        // ── STEP C ──
        return $this->applyIssueResult($stepA['reservationId'], $stepA['grantId'], $result, $actor);
    }

    /**
     * STEP A — no provider call. One transaction that fully commits or fully
     * rolls back.
     *
     * @return array{replay: bool, reservationId: int, grantId: int, accessMode: string, roomId: int|null}
     */
    private function openIssueAttempt(
        Reservation $reservation,
        string $key,
        ?SimulationDirective $directive,
        ?User $actor,
    ): array {
        return DB::transaction(function () use ($reservation, $key, $actor) {
            $locked = $this->reservations->findForUpdate($reservation->id);

            if (! $locked) {
                throw (new ModelNotFoundException)->setModel(Reservation::class, [$reservation->id]);
            }

            $existingByKey = $this->grants->findByIdempotencyKey($key);

            if ($existingByKey !== null && $existingByKey->reservation_id !== $locked->id) {
                throw new DigitalAccessIdempotencyKeyConflictException('different reservation');
            }

            $grant = $this->grants->findByReservationForUpdate($locked->id);

            if ($grant !== null && ($decision = $this->startStateDecision($grant, $key, $locked)) !== null) {
                return $decision;
            }

            // ── Eligibility (§8: "CHECKED_IN requires both payment-confirmed
            //    and VERIFIED"; §11: "payment confirmed + verified + correct
            //    time window"). ──
            $this->assertCheckInEligible($locked, $actor === null ? self::CHANNEL_SELF : self::CHANNEL_RECEPTION);

            if ($grant === null) {
                $grant = $this->createGrant($locked, $key);

                // createGrant() may have lost the reservation_id UNIQUE race
                // and returned the winning grant already past NOT_ISSUED —
                // re-apply the start-state rules against it.
                if (($decision = $this->startStateDecision($grant, $key, $locked)) !== null) {
                    return $decision;
                }
            }

            // grant is now NOT_ISSUED or FAILED (retry) — advance to pending.
            DigitalAccessStateMachine::assertCanTransition($grant->status, AccessGrant::STATUS_ISSUE_REQUESTED);
            $grant = $this->grants->update($grant, [
                'status' => AccessGrant::STATUS_ISSUE_REQUESTED,
                'idempotency_key' => $key,
                'provider' => $this->providerName(),
                'issued_at' => now(),
                'failure_reason' => null,
            ]);

            $this->auditLogger->record(
                $actor,
                'digital_access.issue_requested',
                $grant,
                after: $this->auditSnapshot($grant),
                hotelId: $grant->hotel_id,
            );

            return [
                'replay' => false,
                'reservationId' => $locked->id,
                'grantId' => $grant->id,
                'accessMode' => $grant->access_mode,
                'roomId' => $locked->room_id,
            ];
        });
    }

    /**
     * STEP C — the reusable "apply a provider issue result" capability.
     *
     * Locks are acquired Reservation -> AccessGrant; every record is
     * re-read. Fully idempotent: a result for a grant that is no longer
     * ISSUE_REQUESTED is a no-op that returns the current grant.
     *
     * No provider call happens here.
     */
    public function applyIssueResult(
        int $reservationId,
        int $grantId,
        AccessResult $result,
        ?User $actor = null,
    ): AccessGrant {
        return DB::transaction(function () use ($reservationId, $grantId, $result, $actor) {
            $reservation = $this->reservations->findForUpdate($reservationId);
            $grant = $this->grants->findForUpdate($grantId);

            if ($grant === null) {
                throw (new ModelNotFoundException)->setModel(AccessGrant::class, [$grantId]);
            }

            // Idempotent Step C.
            if ($grant->status !== AccessGrant::STATUS_ISSUE_REQUESTED) {
                return $grant;
            }

            if ($result->status === AccessResultStatus::Active) {
                return $this->applyIssueActive($grant, $reservation, $result, $actor);
            }

            // FAILED — recoverable, reservation untouched.
            DigitalAccessStateMachine::assertCanTransition($grant->status, AccessGrant::STATUS_FAILED);
            $grant = $this->grants->update($grant, [
                'status' => AccessGrant::STATUS_FAILED,
                'provider_reference' => $result->providerReference,
                'failure_reason' => 'provider_declined',
                'metadata' => $this->safeMetadata($grant, $result),
            ]);

            $this->auditLogger->record(
                $actor,
                'digital_access.issue_failed',
                $grant,
                after: $this->auditSnapshot($grant) + ['reservation_status' => $reservation?->status],
                hotelId: $grant->hotel_id,
            );

            return $grant;
        });
    }

    private function applyIssueActive(
        AccessGrant $grant,
        ?Reservation $reservation,
        AccessResult $result,
        ?User $actor,
    ): AccessGrant {
        DigitalAccessStateMachine::assertCanTransition($grant->status, AccessGrant::STATUS_ACTIVE);

        $expiresAt = $reservation?->check_out?->copy()->endOfDay();

        $grant = $this->grants->update($grant, [
            'status' => AccessGrant::STATUS_ACTIVE,
            'provider_reference' => $result->providerReference,
            // Encrypted at rest via the model's `encrypted` cast.
            'credential' => $result->credential,
            'activated_at' => now(),
            'expires_at' => $expiresAt,
            'metadata' => $this->safeMetadata($grant, $result),
        ]);

        $reservationOutcome = $reservation?->status;

        if ($reservation !== null && $reservation->status === Reservation::STATUS_VERIFIED) {
            // §8: "(access issued)" is the trigger for VERIFIED -> CHECKED_IN.
            // Approved 2026-09-26: the stay starts at check-in, so the
            // reservation continues straight to IN_STAY (payment only
            // confirms it; it never starts the stay).
            $checkedIn = $this->reservationService->transitionTo($reservation, Reservation::STATUS_CHECKED_IN, $actor);
            $this->reservationService->transitionTo($checkedIn, Reservation::STATUS_IN_STAY, $actor);
            $reservationOutcome = Reservation::STATUS_IN_STAY;

            $this->auditLogger->record(
                $actor,
                'digital_access.issued',
                $grant,
                after: $this->auditSnapshot($grant) + ['reservation_status' => $reservationOutcome],
                hotelId: $grant->hotel_id,
            );

            return $grant;
        }

        // Late success after the Reservation already moved on — the
        // credential is active but the reservation transition is not applied;
        // flag for reconciliation, never force a state change.
        $this->auditLogger->record(
            $actor,
            'digital_access.issued_reservation_not_ready',
            $grant,
            after: $this->auditSnapshot($grant) + [
                'reservation_status' => $reservationOutcome,
                'requires_reconciliation' => true,
            ],
            hotelId: $grant->hotel_id,
        );

        return $grant;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Revocation (staged; local revocation is authoritative)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Revoke a Reservation's digital access. Repeated revoke calls are safe
     * (an already-revoked / expired grant is an idempotent no-op). A provider
     * failure never leaves the credential usable — the grant is REVOKED
     * locally and the divergence is recorded for reconciliation.
     *
     * MUST NOT be called from inside an open DB transaction.
     *
     * @throws DigitalAccessActionNotAllowedException if there is nothing to revoke
     */
    public function revoke(
        Reservation $reservation,
        ?string $reason = null,
        ?SimulationDirective $directive = null,
        ?User $actor = null,
    ): AccessGrant {
        $stepA = $this->openRevokeAttempt($reservation, $reason, $actor);

        if ($stepA['skip']) {
            $grant = $this->grants->find($stepA['grantId']);

            if ($grant === null) {
                throw (new ModelNotFoundException)->setModel(AccessGrant::class, [$stepA['grantId']]);
            }

            return $grant;
        }

        // ── STEP B ── provider call, OUTSIDE any DB transaction.
        $result = $this->provider->revoke(new AccessOperationRequest(
            providerReference: $stepA['providerReference'] ?? '',
            directive: $directive,
        ));

        // ── STEP C ──
        return $this->applyRevokeResult($stepA['grantId'], $result, $reason, $actor);
    }

    /**
     * @return array{skip: bool, grantId: int, providerReference: string|null}
     */
    private function openRevokeAttempt(Reservation $reservation, ?string $reason, ?User $actor): array
    {
        return DB::transaction(function () use ($reservation, $reason, $actor) {
            $locked = $this->reservations->findForUpdate($reservation->id);

            if (! $locked) {
                throw (new ModelNotFoundException)->setModel(Reservation::class, [$reservation->id]);
            }

            $grant = $this->grants->findByReservationForUpdate($locked->id);

            if ($grant === null) {
                throw new DigitalAccessActionNotAllowedException('revoke', AccessGrant::STATUS_NOT_ISSUED);
            }

            // Idempotent: already revoked / revoke in progress / expired.
            if (in_array($grant->status, [
                AccessGrant::STATUS_REVOKED,
                AccessGrant::STATUS_REVOKE_REQUESTED,
                AccessGrant::STATUS_EXPIRED,
            ], true)) {
                return ['skip' => true, 'grantId' => $grant->id, 'providerReference' => $grant->provider_reference];
            }

            if ($grant->status !== AccessGrant::STATUS_ACTIVE) {
                throw new DigitalAccessActionNotAllowedException('revoke', $grant->status);
            }

            DigitalAccessStateMachine::assertCanTransition($grant->status, AccessGrant::STATUS_REVOKE_REQUESTED);
            $grant = $this->grants->update($grant, [
                'status' => AccessGrant::STATUS_REVOKE_REQUESTED,
                'revocation_reason' => $reason,
            ]);

            $this->auditLogger->record(
                $actor,
                'digital_access.revoke_requested',
                $grant,
                after: $this->auditSnapshot($grant),
                hotelId: $grant->hotel_id,
            );

            return ['skip' => false, 'grantId' => $grant->id, 'providerReference' => $grant->provider_reference];
        });
    }

    /**
     * Keeps an ACTIVE credential valid until the end of the stay's current
     * check-out day — called after Extend Stay moves `check_out` (the grant's
     * `expires_at` is otherwise fixed at issue time). No-op for any other
     * grant state; idempotent.
     */
    public function syncExpiryToStay(Reservation $reservation, ?User $actor = null): void
    {
        DB::transaction(function () use ($reservation, $actor) {
            $grant = $this->grants->findByReservationForUpdate($reservation->id);
            $expiresAt = $reservation->check_out?->copy()->endOfDay();

            if ($grant === null || $grant->status !== AccessGrant::STATUS_ACTIVE || $expiresAt === null) {
                return;
            }

            if ($grant->expires_at !== null && $grant->expires_at->equalTo($expiresAt)) {
                return;
            }

            $before = ['expires_at' => $grant->expires_at?->toIso8601String()];
            $grant = $this->grants->update($grant, ['expires_at' => $expiresAt]);

            $this->auditLogger->record(
                $actor,
                'digital_access.expiry_extended',
                $grant,
                before: $before,
                after: ['expires_at' => $grant->expires_at?->toIso8601String()],
                hotelId: $grant->hotel_id,
            );
        });
    }

    /**
     * STEP C for revocation. Local revocation is authoritative — a provider
     * failure still results in REVOKED, with the credential nulled and the
     * divergence audited.
     *
     * No provider call happens here.
     */
    public function applyRevokeResult(
        int $grantId,
        AccessResult $result,
        ?string $reason = null,
        ?User $actor = null,
    ): AccessGrant {
        return DB::transaction(function () use ($grantId, $result, $reason, $actor) {
            $grant = $this->grants->findForUpdate($grantId);

            if ($grant === null) {
                throw (new ModelNotFoundException)->setModel(AccessGrant::class, [$grantId]);
            }

            // Idempotent Step C.
            if ($grant->status !== AccessGrant::STATUS_REVOKE_REQUESTED) {
                return $grant;
            }

            DigitalAccessStateMachine::assertCanTransition($grant->status, AccessGrant::STATUS_REVOKED);
            $grant = $this->grants->update($grant, [
                'status' => AccessGrant::STATUS_REVOKED,
                // The PIN is dead the moment it is revoked.
                'credential' => null,
                'revoked_at' => now(),
                'revocation_reason' => $reason ?? $grant->revocation_reason,
                'metadata' => $this->safeMetadata($grant, $result),
            ]);

            $providerFailed = $result->isFailure();

            $this->auditLogger->record(
                $actor,
                'digital_access.revoked',
                $grant,
                after: $this->auditSnapshot($grant) + array_filter([
                    'provider_revoke_failed' => $providerFailed ?: null,
                    'requires_reconciliation' => $providerFailed ?: null,
                ]),
                hotelId: $grant->hotel_id,
            );

            return $grant;
        });
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Lazy expiry (§11 "stay end reached, auto")
    // ═════════════════════════════════════════════════════════════════════

    private function isExpirable(AccessGrant $grant): bool
    {
        return $grant->status === AccessGrant::STATUS_ACTIVE
            && $grant->expires_at !== null
            && now()->greaterThanOrEqualTo($grant->expires_at);
    }

    private function expire(AccessGrant $grant): AccessGrant
    {
        return DB::transaction(function () use ($grant) {
            $locked = $this->grants->findForUpdate($grant->id);

            if ($locked === null || ! $this->isExpirable($locked)) {
                return $locked ?? $grant;
            }

            DigitalAccessStateMachine::assertCanTransition($locked->status, AccessGrant::STATUS_EXPIRED);
            $locked = $this->grants->update($locked, [
                'status' => AccessGrant::STATUS_EXPIRED,
                'credential' => null,
            ]);

            $this->auditLogger->record(
                null,
                'digital_access.expired',
                $locked,
                after: $this->auditSnapshot($locked),
                hotelId: $locked->hotel_id,
            );

            return $locked;
        });
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Internals
    // ═════════════════════════════════════════════════════════════════════

    /**
     * §8 + §11 hard preconditions. No number is invented: the "time window"
     * upper bound is the reservation's own check_out date (end of day). The
     * lower bound (early-check-in policy) and exact hotel checkout time are
     * OPEN items — see the Phase 7 report.
     */
    /** A guest checking themselves in from the app (no staff actor). */
    public const CHANNEL_SELF = 'self';

    /** Reception checking the guest in from the dashboard. */
    public const CHANNEL_RECEPTION = 'reception';

    /**
     * Non-throwing check-in availability for a channel — what the guest app
     * and dashboard show (`allowed` + the machine `reason`).
     *
     * @return array{allowed: bool, reason: ?string, mode: string}
     */
    public function checkInAvailability(Reservation $reservation, string $channel): array
    {
        $mode = (string) ($reservation->hotel?->check_in_mode ?? Hotel::CHECK_IN_BOTH);

        try {
            $this->assertCheckInEligible($reservation, $channel);
        } catch (CheckInNotAllowedException) {
            return ['allowed' => false, 'reason' => 'status_'.$reservation->status, 'mode' => $mode];
        } catch (CheckInEligibilityException $e) {
            return ['allowed' => false, 'reason' => $e->reason, 'mode' => $mode];
        }

        return ['allowed' => true, 'reason' => null, 'mode' => $mode];
    }

    private function assertCheckInEligible(Reservation $reservation, string $channel): void
    {
        if ($reservation->status !== Reservation::STATUS_VERIFIED) {
            throw new CheckInNotAllowedException($reservation->status);
        }

        // Approved 2026-09-26: the hotel decides who checks guests in.
        $hotel = $reservation->hotel;
        $channelAllowed = $channel === self::CHANNEL_SELF
            ? ($hotel?->allowsSelfCheckIn() ?? true)
            : ($hotel?->allowsReceptionCheckIn() ?? true);

        if (! $channelAllowed) {
            throw CheckInEligibilityException::channelNotAllowed();
        }

        // Payment confirms the reservation; a hotel with a 0% deposit takes
        // no hold, so there is no payment to require.
        $payment = $this->payments->findByReservation($reservation->id);
        $depositRequired = (float) ($hotel?->deposit_percentage ?? 0) > 0;

        if ($depositRequired && ($payment === null || $payment->status !== Payment::STATUS_HOLD_ACTIVE)) {
            throw CheckInEligibilityException::paymentNotConfirmed();
        }

        $identity = $this->identitySessions->findByReservation($reservation->id);

        if ($identity === null || ! in_array($identity->status, IdentityVerificationSession::APPROVED_STATUSES, true)) {
            throw CheckInEligibilityException::identityNotVerified();
        }

        // The key opens a specific room — reception assigns it first.
        if ($reservation->room_id === null) {
            throw CheckInEligibilityException::roomNotAssigned();
        }

        $stayEnd = $reservation->check_out?->copy()->endOfDay();

        if ($stayEnd !== null && now()->greaterThanOrEqualTo($stayEnd)) {
            throw CheckInEligibilityException::outsideStayWindow();
        }
    }

    private function createGrant(Reservation $reservation, string $key): AccessGrant
    {
        try {
            return $this->grants->create([
                'reservation_id' => $reservation->id,
                // Hotel and guest are ALWAYS derived from the Reservation.
                'hotel_id' => $reservation->hotel_id,
                'guest_id' => $reservation->guest_id,
                'status' => DigitalAccessStateMachine::INITIAL_STATUS,
                'access_mode' => $this->accessMode(),
                'provider' => $this->providerName(),
                'idempotency_key' => $key,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent check-in created the grant between our read and
            // our insert. UNIQUE(reservation_id) is authoritative — re-read.
            $winner = $this->grants->findByReservationForUpdate($reservation->id);

            if ($winner === null) {
                throw new DigitalAccessActionNotAllowedException('check_in', AccessGrant::STATUS_NOT_ISSUED);
            }

            return $winner;
        }
    }

    /**
     * Decide what a check-in should do when a grant already exists for the
     * reservation:
     *   - NOT_ISSUED / FAILED  -> null (proceed to (re)request issuance)
     *   - ACTIVE               -> replay (idempotent success, regardless of key)
     *   - ISSUE_REQUESTED      -> replay if same key, else "in progress" 422
     *   - REVOKE_REQUESTED / REVOKED / EXPIRED -> "cannot re-issue" 422
     *
     * @return array{replay: true, reservationId: int, grantId: int, accessMode: string, roomId: int|null}|null
     */
    private function startStateDecision(AccessGrant $grant, string $key, Reservation $reservation): ?array
    {
        return match ($grant->status) {
            AccessGrant::STATUS_NOT_ISSUED,
            AccessGrant::STATUS_FAILED => null,
            AccessGrant::STATUS_ACTIVE => $this->replayTuple($reservation, $grant),
            AccessGrant::STATUS_ISSUE_REQUESTED => $grant->idempotency_key === $key
                ? $this->replayTuple($reservation, $grant)
                : throw new DigitalAccessActionNotAllowedException('check_in', $grant->status),
            default => throw new DigitalAccessActionNotAllowedException('check_in', $grant->status),
        };
    }

    /**
     * @return array{replay: true, reservationId: int, grantId: int, accessMode: string, roomId: int|null}
     */
    private function replayTuple(Reservation $reservation, AccessGrant $grant): array
    {
        return [
            'replay' => true,
            'reservationId' => $reservation->id,
            'grantId' => $grant->id,
            'accessMode' => $grant->access_mode,
            'roomId' => $reservation->room_id,
        ];
    }

    private function providerName(): string
    {
        return (string) config('digital_access.provider');
    }

    private function accessMode(): string
    {
        return (string) config('digital_access.mode', AccessGrant::MODE_PIN_CODE);
    }

    /**
     * Fold only the safe, non-secret parts of a provider result into the
     * grant metadata. The provider contract already guarantees no credential
     * / secret / raw payload reaches here.
     *
     * @return array<string, mixed>
     */
    private function safeMetadata(AccessGrant $grant, AccessResult $result): array
    {
        return array_merge($grant->metadata ?? [], [
            'provider_code' => $result->providerCode,
            'provider_message' => $result->message,
        ]);
    }

    /**
     * A safe, flat snapshot for the audit trail — business status fields
     * only. NEVER the credential, the idempotency key, a raw provider
     * payload, an identity document path, or any secret (Phase 0 §17).
     *
     * @return array<string, mixed>
     */
    private function auditSnapshot(AccessGrant $grant): array
    {
        return array_filter([
            'grant_status' => $grant->status,
            'access_mode' => $grant->access_mode,
            'provider' => $grant->provider,
            'provider_reference' => $grant->provider_reference,
            'failure_reason' => $grant->failure_reason,
            'issued_at' => $grant->issued_at?->toIso8601String(),
            'activated_at' => $grant->activated_at?->toIso8601String(),
            'expires_at' => $grant->expires_at?->toIso8601String(),
            'revoked_at' => $grant->revoked_at?->toIso8601String(),
        ], fn ($value) => $value !== null);
    }
}
