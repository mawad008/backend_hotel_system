<?php

namespace App\Http\Controllers\Api\V1\Guest;

use App\Domain\GuestAccess\Services\GuestAuthService;
use App\Domain\GuestAccess\Services\OtpVerificationResult;
use App\Domain\Reservation\Models\Guest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Guest\RequestOtpRequest;
use App\Http\Requests\Api\V1\Guest\ResendOtpRequest;
use App\Http\Requests\Api\V1\Guest\UpdateGuestProfileRequest;
use App\Http\Requests\Api\V1\Guest\VerifyOtpRequest;
use App\Http\Resources\V1\GuestOtpChallengeResource;
use App\Http\Resources\V1\GuestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Slice 0 — the guest authentication HTTP surface (phone + OTP). Thin: it
 * validates, delegates to GuestAuthService, and shapes the standard envelope.
 * The authenticated guest identity is always derived from the `guest-api`
 * Sanctum token — never from a request field.
 */
class GuestAuthController extends Controller
{
    public function __construct(private readonly GuestAuthService $auth) {}

    public function requestOtp(RequestOtpRequest $request): JsonResponse
    {
        $challenge = $this->auth->requestOtp($request->phone());

        return $this->success(
            new GuestOtpChallengeResource($challenge),
            __('api.guest_auth.otp_sent'),
        );
    }

    public function resendOtp(ResendOtpRequest $request): JsonResponse
    {
        $challenge = $this->auth->resendOtp(
            $request->validated('challenge_id'),
            $request->validated('phone'),
        );

        return $this->success(
            new GuestOtpChallengeResource($challenge),
            __('api.guest_auth.otp_sent'),
        );
    }

    /**
     * Wrong code and lock-out are ordinary flow outcomes — every branch is
     * a 200 with an `outcome` discriminator, matching the Flutter
     * OtpVerifyResult contract. 422 only comes from the FormRequest or an
     * expired / unknown challenge (thrown in the service).
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $result = $this->auth->verifyOtp(
            $request->validated('challenge_id'),
            $request->validated('phone'),
            $request->validated('code'),
        );

        return match ($result->outcome) {
            OtpVerificationResult::OUTCOME_AUTHENTICATED => $this->success([
                'outcome' => $result->outcome,
                'token' => $result->token,
                'guest' => new GuestResource($result->guest),
                'profile_complete' => $result->guest->isProfileComplete(),
            ], __('api.guest_auth.verified')),

            OtpVerificationResult::OUTCOME_REJECTED => $this->success([
                'outcome' => $result->outcome,
                'attempts_remaining' => $result->attemptsRemaining,
            ], __('api.guest_auth.otp_incorrect')),

            default => $this->success([
                'outcome' => OtpVerificationResult::OUTCOME_LOCKED_OUT,
                'attempts_remaining' => 0,
            ], __('api.guest_auth.otp_locked_out')),
        };
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Guest $guest */
        $guest = $request->user();

        return $this->success([
            'guest' => new GuestResource($guest),
            'profile_complete' => $guest->isProfileComplete(),
        ]);
    }

    public function updateProfile(UpdateGuestProfileRequest $request): JsonResponse
    {
        /** @var Guest $guest */
        $guest = $request->user();

        $guest = $this->auth->completeProfile(
            $guest,
            $request->validated('name'),
            $request->validated('email'),
        );

        return $this->success([
            'guest' => new GuestResource($guest),
            'profile_complete' => $guest->isProfileComplete(),
        ], __('api.updated'));
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'high_floor' => ['sometimes', 'boolean'],
            'extra_pillows' => ['sometimes', 'boolean'],
            'notifications_enabled' => ['sometimes', 'boolean'],
            'identity_retention' => ['sometimes', 'string', Rule::in(Guest::IDENTITY_RETENTION_OPTIONS)],
        ]);

        $retention = $validated['identity_retention'] ?? null;
        unset($validated['identity_retention']);

        $guest = $this->auth->updatePreferences($request->user(), array_map('boolval', $validated), $retention);

        return $this->success(['guest' => new GuestResource($guest)], __('api.updated'));
    }

    public function requestDataDeletion(Request $request): JsonResponse
    {
        $guest = $this->auth->requestDataDeletion($request->user());

        return $this->success(['guest' => new GuestResource($guest)], __('api.guest_auth.data_deletion_requested'));
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var Guest $guest */
        $guest = $request->user();

        $this->auth->logout($guest);

        return $this->success(null, __('api.logout_success'));
    }
}
