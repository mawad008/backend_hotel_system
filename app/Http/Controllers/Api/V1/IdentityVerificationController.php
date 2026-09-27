<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityVerification\DocumentCheck\DocumentTypeSettings;
use App\Domain\IdentityVerification\DocumentCheck\IdentityDocumentCatalog;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\IdentityVerification\Services\IdentityVerificationService;
use App\Domain\Reservation\Services\ReservationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IdentityVerification\ReviewIdentityVerificationRequest;
use App\Http\Requests\Api\V1\IdentityVerification\SubmitIdentityDocumentRequest;
use App\Http\Requests\Api\V1\IdentityVerification\SubmitIdentitySelfieRequest;
use App\Http\Resources\V1\IdentityVerificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 6 — the identity verification HTTP surface (Phase 0 §16:
 * /api/v1/identity-verification/{reservation}/{documents|selfie|status|review}).
 *
 * Thin: it resolves and authorizes the Reservation (through
 * ReservationService, not implicit route-model binding, so a cross-hotel or
 * missing id is an identical plain 404), hands validated input to
 * IdentityVerificationService, and maps the resulting session to an HTTP
 * response. It never calls the provider, opens a transaction, touches a
 * model for a workflow decision, transitions a Reservation, stores a file,
 * or reimplements the state machine.
 */
class IdentityVerificationController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly IdentityVerificationService $verification,
    ) {}

    /**
     * GET /api/v1/identity-verification/document-types — per-type route
     * configuration for staff/admins: provider, model id, whether it is
     * configured, back-image policy, manual-review requirement. Never a key
     * or any other secret.
     */
    public function documentTypes(Request $request, IdentityDocumentCatalog $catalog): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('identity-verification.view'), 403);

        $provider = (string) config('verification.document_provider');

        return $this->success(array_map(fn (DocumentTypeSettings $s) => [
            'type' => $s->type->value,
            'country' => $s->type->issuingCountry(),
            'enabled' => $s->enabled,
            'provider' => $provider,
            'model' => $s->model,
            'model_configured' => $s->modelConfigured(),
            'custom_model' => ! $s->type->usesPrebuiltModel(),
            'back_image' => $s->back,
            'auto_verify' => $s->autoVerify,
            'manual_review_required' => ! ($s->modelConfigured() && $s->autoVerify),
        ], $catalog->all()), __('api.identity_verification.status'));
    }

    /**
     * POST /api/v1/identity-verification/{reservation}/documents
     */
    public function documents(SubmitIdentityDocumentRequest $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findAccessibleBy($request->user(), $reservation);

        if (! $found) {
            abort(404);
        }

        $this->authorize('submit', [IdentityVerificationSession::class, $found]);

        $session = $this->verification->submitDocument(
            reservation: $found,
            document: $request->frontImage(),
            documentType: $request->documentType(),
            actor: $request->user(),
            claim: $request->claim($found->guest?->name),
            backDocument: $request->backImage(),
        );

        return $this->success(
            $this->resource($session),
            __('api.identity_verification.document_submitted'),
            201,
        );
    }

    /**
     * POST /api/v1/identity-verification/{reservation}/selfie
     */
    public function selfie(SubmitIdentitySelfieRequest $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findAccessibleBy($request->user(), $reservation);

        if (! $found) {
            abort(404);
        }

        $this->authorize('submit', [IdentityVerificationSession::class, $found]);

        $session = $this->verification->submitSelfie(
            reservation: $found,
            selfie: $request->file('selfie'),
            idempotencyKey: $request->idempotencyKey(),
            directive: $request->simulationDirective(),
            actor: $request->user(),
        );

        return $this->success(
            $this->resource($session),
            __('api.identity_verification.'.$session->status),
        );
    }

    /**
     * GET /api/v1/identity-verification/{reservation}/status
     */
    public function status(Request $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findAccessibleBy($request->user(), $reservation);

        if (! $found) {
            abort(404);
        }

        $this->authorize('view', [IdentityVerificationSession::class, $found]);

        $session = $this->verification->statusFor($found);

        return $this->success($this->resource($session), __('api.identity_verification.status'));
    }

    /**
     * POST /api/v1/identity-verification/{reservation}/review
     */
    public function review(ReviewIdentityVerificationRequest $request, int $reservation): JsonResponse
    {
        $found = $this->reservations->findAccessibleBy($request->user(), $reservation);

        if (! $found) {
            abort(404);
        }

        $this->authorize('review', [IdentityVerificationSession::class, $found]);

        $session = $this->verification->review(
            reservation: $found,
            decision: $request->validated('decision'),
            reason: $request->reason(),
            actor: $request->user(),
        );

        return $this->success(
            $this->resource($session),
            __('api.identity_verification.'.$session->status),
        );
    }

    private function resource(IdentityVerificationSession $session): IdentityVerificationResource
    {
        return (new IdentityVerificationResource($session))
            ->withLatestDecision($this->verification->latestDecision($session))
            ->withLatestAttempt($this->verification->latestAttempt($session))
            ->withStaffDetail();
    }
}
