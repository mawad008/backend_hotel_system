<?php

namespace Database\Factories;

use App\Domain\IdentityVerification\Models\IdentityVerificationAttempt;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IdentityVerificationAttempt>
 */
class IdentityVerificationAttemptFactory extends Factory
{
    protected $model = IdentityVerificationAttempt::class;

    public function definition(): array
    {
        return [
            'session_id' => IdentityVerificationSession::factory(),
            'attempt_number' => 1,
            'status' => IdentityVerificationAttempt::STATUS_DOCUMENT_UPLOADED,
            'provider' => 'dummy',
            'provider_reference' => null,
            'idempotency_key' => (string) Str::uuid(),
            'document_type' => 'passport',
            // Private-disk relative path shape only — never a real file in
            // a factory.
            'document_path' => fn (array $attributes) => 'identity-verification/'.($attributes['session_id'] ?? 0).'/doc.jpg',
            'selfie_path' => null,
            'outcome' => null,
            'score' => null,
            'metadata' => null,
            'submitted_at' => null,
            'completed_at' => null,
        ];
    }

    public function completed(string $outcome = 'high_match', ?int $score = 90): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IdentityVerificationAttempt::STATUS_COMPLETED,
            'selfie_path' => 'identity-verification/'.($attributes['session_id'] ?? 0).'/selfie.jpg',
            'provider_reference' => 'dummy_idv_'.substr(md5((string) Str::uuid()), 0, 24),
            'outcome' => $outcome,
            'score' => $score,
            'submitted_at' => now(),
            'completed_at' => now(),
        ]);
    }
}
