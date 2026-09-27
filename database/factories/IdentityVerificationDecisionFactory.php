<?php

namespace Database\Factories;

use App\Domain\IdentityVerification\Models\IdentityVerificationDecision;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdentityVerificationDecision>
 */
class IdentityVerificationDecisionFactory extends Factory
{
    protected $model = IdentityVerificationDecision::class;

    public function definition(): array
    {
        return [
            'session_id' => IdentityVerificationSession::factory(),
            'attempt_id' => null,
            'type' => IdentityVerificationDecision::TYPE_AUTOMATED,
            'result' => IdentityVerificationDecision::RESULT_MANUAL_REVIEW_REQUIRED,
            'decided_by_user_id' => null,
            'score' => 20,
            'band' => IdentityVerificationDecision::BAND_LOW,
            'reason' => null,
            'created_at' => now(),
        ];
    }

    public function manual(string $result = IdentityVerificationDecision::RESULT_STAFF_APPROVED): static
    {
        return $this->state(fn () => [
            'type' => IdentityVerificationDecision::TYPE_MANUAL,
            'result' => $result,
            'band' => null,
        ]);
    }
}
