<?php

use App\Domain\Checkout\Exceptions\CheckoutCurrencyMissingException;
use App\Domain\Checkout\Exceptions\CheckoutNotAllowedException;
use App\Domain\Checkout\Exceptions\InvalidCheckoutStatusTransitionException;
use App\Domain\Checkout\Exceptions\InvoiceGenerationException;
use App\Domain\DigitalAccess\Exceptions\CheckInEligibilityException;
use App\Domain\DigitalAccess\Exceptions\CheckInNotAllowedException;
use App\Domain\DigitalAccess\Exceptions\DigitalAccessActionNotAllowedException;
use App\Domain\DigitalAccess\Exceptions\DigitalAccessIdempotencyKeyConflictException;
use App\Domain\DigitalAccess\Exceptions\InvalidDigitalAccessStatusTransitionException;
use App\Domain\GuestAccess\Exceptions\OtpChallengeExpiredException;
use App\Domain\GuestAccess\Exceptions\OtpChallengeNotFoundException;
use App\Domain\GuestAccess\Exceptions\OtpResendCooldownException;
use App\Domain\HotelGroup\Exceptions\FacilityDeletionBlockedException;
use App\Domain\HotelGroup\Exceptions\HotelDeletionBlockedException;
use App\Domain\IdentityAccess\Exceptions\AccountInactiveException;
use App\Domain\IdentityAccess\Exceptions\InvalidCredentialsException;
use App\Domain\IdentityAccess\Exceptions\RoleDeletionBlockedException;
use App\Domain\IdentityVerification\Exceptions\IdentityVerificationActionNotAllowedException;
use App\Domain\IdentityVerification\Exceptions\IdentityVerificationConfigurationMissingException;
use App\Domain\IdentityVerification\Exceptions\IdentityVerificationIdempotencyKeyConflictException;
use App\Domain\IdentityVerification\Exceptions\IdentityVerificationNotAllowedException;
use App\Domain\IdentityVerification\Exceptions\IdentityVerificationRetryNotAllowedException;
use App\Domain\IdentityVerification\Exceptions\InvalidIdentityVerificationStatusTransitionException;
use App\Domain\Inventory\Exceptions\InvalidRoomStatusTransitionException;
use App\Domain\Inventory\Exceptions\RoomTypeHotelMismatchException;
use App\Domain\Location\Exceptions\CountryCityMismatchException;
use App\Domain\Location\Exceptions\LocationDeletionBlockedException;
use App\Domain\Loyalty\Exceptions\InvalidLoyaltyPointsException;
use App\Domain\Loyalty\Exceptions\LoyaltyNotAllowedException;
use App\Domain\Notification\Exceptions\InvalidNotificationStatusTransitionException;
use App\Domain\Notification\Exceptions\NotificationNotAllowedException;
use App\Domain\Payment\Exceptions\IdempotencyKeyConflictException;
use App\Domain\Payment\Exceptions\InvalidPaymentAmountException;
use App\Domain\Payment\Exceptions\InvalidPaymentCurrencyException;
use App\Domain\Payment\Exceptions\InvalidPaymentStatusTransitionException;
use App\Domain\Payment\Exceptions\PaymentAlreadyInitiatedException;
use App\Domain\Payment\Exceptions\PaymentHoldNotAllowedException;
use App\Domain\Payment\Exceptions\PaymentRefundFailedException;
use App\Domain\Payment\Exceptions\PaymentSettlementNotAllowedException;
use App\Domain\Reservation\Exceptions\InvalidReservationStatusTransitionException;
use App\Domain\Reservation\Exceptions\ReservationCancellationNotAllowedException;
use App\Domain\Reservation\Exceptions\ReservationExtensionIdempotencyKeyConflictException;
use App\Domain\Reservation\Exceptions\ReservationExtensionNotAllowedException;
use App\Domain\Reservation\Exceptions\ReservationNotAvailableException;
use App\Domain\Reservation\Exceptions\ReservationRoomAssignmentNotAllowedException;
use App\Domain\Reservation\Exceptions\RoomHotelMismatchException;
use App\Domain\Reservation\Exceptions\RoomTypeMismatchException;
use App\Domain\Review\Exceptions\ReviewNotAllowedException;
use App\Domain\StayServices\Exceptions\FolioChargeAmountException;
use App\Domain\StayServices\Exceptions\InvalidServiceOrderStatusTransitionException;
use App\Domain\StayServices\Exceptions\ServiceOrderNotAllowedException;
use App\Domain\StayServices\Exceptions\ServiceReviewNotAllowedException;
use App\Domain\Support\Exceptions\InvalidProblemReportStatusTransitionException;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            ForceJsonResponse::class,
            SetLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $envelope = function (string $message, int $status, mixed $errors = null): JsonResponse {
            $payload = ['success' => false, 'message' => $message];

            if ($errors !== null) {
                $payload['errors'] = $errors;
            }

            return response()->json($payload, $status);
        };

        $exceptions->renderable(function (ValidationException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope(__('api.validation_failed'), 422, $e->errors());
            }
        });

        $exceptions->renderable(function (AuthenticationException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope(__('api.unauthenticated'), 401);
            }
        });

        $exceptions->renderable(function (AuthorizationException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope(__('api.forbidden'), 403);
            }
        });

        $exceptions->renderable(function (ModelNotFoundException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope(__('api.not_found'), 404);
            }
        });

        $exceptions->renderable(function (InvalidCredentialsException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope(__('api.invalid_credentials'), 401);
            }
        });

        $exceptions->renderable(function (AccountInactiveException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope(__('api.account_inactive'), 403);
            }
        });

        // Dynamic role management (RBAC hardening) — a system role or a role
        // still assigned to users cannot be deleted. Fixed, safe strings,
        // 422 like every other domain exception in this handler.
        $exceptions->renderable(function (RoleDeletionBlockedException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        // Slice 0 — guest OTP business errors. Fixed, safe machine strings
        // (no code, no phone, no SQLSTATE). All 422, consistent with every
        // other domain exception. Wrong-code / lock-out never reach here —
        // they are 200 outcomes.
        $exceptions->renderable(function (OtpChallengeNotFoundException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (OtpChallengeExpiredException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (OtpResendCooldownException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (RoomTypeHotelMismatchException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        // Country + City master data business errors. Fixed, safe strings —
        // 422 like every other domain exception above.
        $exceptions->renderable(function (CountryCityMismatchException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (LocationDeletionBlockedException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (FacilityDeletionBlockedException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (HotelDeletionBlockedException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (InvalidRoomStatusTransitionException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (RoomHotelMismatchException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (RoomTypeMismatchException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (ReservationNotAvailableException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (InvalidReservationStatusTransitionException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        // Extend Stay — reservation-domain business errors, same 422
        // convention as every sibling above.
        $exceptions->renderable(function (ReservationCancellationNotAllowedException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope(__('api.reservation.cancellation_not_allowed'), 422, ['reason' => $e->reason]);
            }
        });
        $exceptions->renderable(function (PaymentRefundFailedException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope(__('api.reservation.refund_failed'), 422, ['reason' => 'refund_failed']);
            }
        });
        $exceptions->renderable(function (ReservationRoomAssignmentNotAllowedException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });
        $exceptions->renderable(function (ReservationExtensionNotAllowedException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (ReservationExtensionIdempotencyKeyConflictException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        // Phase 5D — payment workflow business errors. Every message below
        // is a fixed, safe business string (no secret, provider payload,
        // SQLSTATE, or stack trace). All map to 422: the project's API has
        // no 409/conflict convention, and these are business-rule failures
        // like every other domain exception above.
        $exceptions->renderable(function (PaymentHoldNotAllowedException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (PaymentAlreadyInitiatedException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (IdempotencyKeyConflictException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (InvalidPaymentAmountException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (InvalidPaymentCurrencyException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (InvalidPaymentStatusTransitionException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        // Phase 6 — identity verification workflow business errors. Every
        // message is a fixed, safe business string (no secret, no provider
        // payload, no document number, no SQLSTATE, no stack trace). All map
        // to 422: the project's API has no 409/conflict convention, and
        // these are business-rule failures like every other domain
        // exception above.
        $exceptions->renderable(function (IdentityVerificationNotAllowedException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (IdentityVerificationActionNotAllowedException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (IdentityVerificationRetryNotAllowedException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (IdentityVerificationConfigurationMissingException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (IdentityVerificationIdempotencyKeyConflictException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (InvalidIdentityVerificationStatusTransitionException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        // Phase 7 — check-in / digital access workflow business errors. Every
        // message is a fixed, safe business string (no secret, no credential,
        // no provider payload, no SQLSTATE, no stack trace). All map to 422:
        // the project's API has no 409/conflict convention, and these are
        // business-rule failures like every other domain exception above.
        $exceptions->renderable(function (CheckInNotAllowedException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (CheckInEligibilityException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (DigitalAccessActionNotAllowedException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (DigitalAccessIdempotencyKeyConflictException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (InvalidDigitalAccessStatusTransitionException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        // Phase 8 — stay services / folio business errors. Every message is a
        // fixed, safe business string (no secret, no payment data, no
        // SQLSTATE, no stack trace). All map to 422, like every other domain
        // exception above.
        $exceptions->renderable(function (ServiceOrderNotAllowedException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (InvalidServiceOrderStatusTransitionException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (FolioChargeAmountException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        // Phase 9 — checkout / final settlement / invoice business errors.
        // Every message is a fixed, safe business string (no secret, no
        // payment data, no SQLSTATE, no stack trace). All map to 422, like
        // every other domain exception above.
        $exceptions->renderable(function (CheckoutNotAllowedException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (CheckoutCurrencyMissingException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (InvalidCheckoutStatusTransitionException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (InvoiceGenerationException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (PaymentSettlementNotAllowedException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        // Phase 10 — loyalty business errors. Fixed, safe machine strings
        // (no secret, no payment data, no SQLSTATE, no stack trace). All 422,
        // consistent with every other domain exception above.
        $exceptions->renderable(function (LoyaltyNotAllowedException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422, ['reason' => $e->reason]);
            }
        });

        $exceptions->renderable(function (InvalidLoyaltyPointsException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        // Phase 11 — notification workflow business errors. Fixed, safe
        // machine strings (no secret, no recipient address, no provider
        // payload, no SQLSTATE). All 422, consistent with every other domain
        // exception above.
        $exceptions->renderable(function (NotificationNotAllowedException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (InvalidNotificationStatusTransitionException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        // Review workflow business errors. Fixed, safe machine strings
        // (no secret, no guest-identifying content beyond what the reason
        // already exposes). 422, consistent with every other domain
        // exception above.
        $exceptions->renderable(function (ReviewNotAllowedException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422, ['reason' => $e->reason]);
            }
        });

        // Service-review workflow business errors — same convention as the
        // hotel-level Review workflow above.
        $exceptions->renderable(function (ServiceReviewNotAllowedException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422, ['reason' => $e->reason]);
            }
        });

        $exceptions->renderable(function (InvalidProblemReportStatusTransitionException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422);
            }
        });

        $exceptions->renderable(function (HttpExceptionInterface $e, Request $request) use ($envelope) {
            if (! $request->is('api/*')) {
                return null;
            }

            // Laravel's Handler::prepareException() converts a
            // ModelNotFoundException (thrown by implicit route model
            // binding) into a NotFoundHttpException before any
            // renderable ever sees it, carrying the original raw
            // Eloquent message along as getMessage(). Detecting that via
            // getPrevious() here — rather than trusting getMessage() —
            // keeps that internal detail (model class, id) out of the
            // response, regardless of which resource it came from.
            if ($e->getPrevious() instanceof ModelNotFoundException) {
                return $envelope(__('api.not_found'), 404);
            }

            return $envelope($e->getMessage() ?: __('api.not_found'), $e->getStatusCode());
        });

        $exceptions->renderable(function (Throwable $e, Request $request) use ($envelope) {
            if (! $request->is('api/*')) {
                return null;
            }

            if (app()->hasDebugModeEnabled()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: __('api.server_error'),
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ], 500);
            }

            return $envelope(__('api.server_error'), 500);
        });
    })->create();
