<?php

namespace Tests\Feature\Guest;

use App\Domain\GuestAccess\Models\GuestOtpChallenge;
use App\Domain\GuestAccess\Otp\Contracts\OtpSenderInterface;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Reservation\Models\Guest;
use Tests\TestCase;

class GuestAuthTest extends TestCase
{
    private const PHONE = '+966500000000';

    /** Deterministic dev code (phpunit.xml sets OTP_FIXED_CODE=123456). */
    private const CODE = '123456';

    protected function setUp(): void
    {
        parent::setUp();

        // No real SMS in tests — the sender is a no-op spy.
        $this->mock(OtpSenderInterface::class)->shouldReceive('send')->andReturnNull();
    }

    public function test_request_otp_issues_a_challenge(): void
    {
        $res = $this->postJson('/api/v1/guest/auth/otp/request', ['phone' => self::PHONE]);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['challenge_id', 'phone', 'code_length', 'attempts_remaining', 'expires_in', 'resend_available_in']]);

        $this->assertDatabaseHas('guest_otp_challenges', ['phone' => self::PHONE, 'consumed_at' => null]);
        // The plaintext code is never stored.
        $this->assertNull(GuestOtpChallenge::first()->code_hash === self::CODE ? true : null);
    }

    public function test_phone_is_normalized(): void
    {
        $this->postJson('/api/v1/guest/auth/otp/request', ['phone' => '00966 500-000-000'])
            ->assertOk()
            ->assertJsonPath('data.phone', self::PHONE);
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $this->postJson('/api/v1/guest/auth/otp/request', ['phone' => '12345'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_verify_with_correct_code_creates_guest_and_issues_token(): void
    {
        $challenge = $this->requestChallenge();

        $res = $this->postJson('/api/v1/guest/auth/otp/verify', [
            'challenge_id' => $challenge['challenge_id'],
            'phone' => self::PHONE,
            'code' => self::CODE,
        ]);

        $res->assertOk()
            ->assertJsonPath('data.outcome', 'authenticated')
            ->assertJsonPath('data.profile_complete', false)
            ->assertJsonPath('data.guest.phone', self::PHONE)
            ->assertJsonStructure(['data' => ['token', 'guest' => ['id', 'name', 'email', 'phone', 'profile_complete']]]);

        $guest = Guest::where('phone', self::PHONE)->first();
        $this->assertNotNull($guest);
        $this->assertNotNull($guest->phone_verified_at);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => $guest->getMorphClass(),
            'tokenable_id' => $guest->id,
            'name' => 'guest-api',
        ]);
        $this->assertDatabaseHas('guest_otp_challenges', ['id' => $challenge['id'] ?? GuestOtpChallenge::first()->id]);
        $this->assertNotNull(GuestOtpChallenge::first()->consumed_at);
    }

    public function test_verify_with_wrong_code_is_a_200_rejected_outcome_and_counts_down(): void
    {
        $challenge = $this->requestChallenge();

        $res = $this->postJson('/api/v1/guest/auth/otp/verify', [
            'challenge_id' => $challenge['challenge_id'],
            'phone' => self::PHONE,
            'code' => '000000',
        ]);

        $res->assertOk()
            ->assertJsonPath('data.outcome', 'rejected')
            ->assertJsonPath('data.attempts_remaining', 4);

        $this->assertSame(1, GuestOtpChallenge::first()->attempts);
    }

    public function test_verify_locks_out_after_max_attempts(): void
    {
        $challenge = $this->requestChallenge();

        for ($i = 0; $i < 5; $i++) {
            $res = $this->postJson('/api/v1/guest/auth/otp/verify', [
                'challenge_id' => $challenge['challenge_id'],
                'phone' => self::PHONE,
                'code' => '000000',
            ]);
        }

        $res->assertOk()->assertJsonPath('data.outcome', 'locked_out');

        // A correct code no longer works — the challenge is consumed.
        $this->postJson('/api/v1/guest/auth/otp/verify', [
            'challenge_id' => $challenge['challenge_id'],
            'phone' => self::PHONE,
            'code' => self::CODE,
        ])->assertStatus(422);
    }

    public function test_expired_challenge_returns_422(): void
    {
        $challenge = $this->requestChallenge();
        GuestOtpChallenge::query()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/guest/auth/otp/verify', [
            'challenge_id' => $challenge['challenge_id'],
            'phone' => self::PHONE,
            'code' => self::CODE,
        ])->assertStatus(422);
    }

    public function test_me_returns_the_authenticated_guest(): void
    {
        $guest = Guest::factory()->create();
        $token = $guest->createToken('guest-api')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/guest/auth/me')
            ->assertOk()
            ->assertJsonPath('data.guest.id', $guest->id)
            ->assertJsonPath('data.profile_complete', true);
    }

    public function test_guest_token_is_rejected_on_a_staff_route(): void
    {
        $guest = Guest::factory()->create();
        $token = $guest->createToken('guest-api')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->withToken($token)->getJson('/api/v1/hotels')->assertStatus(401);
    }

    public function test_staff_token_is_rejected_on_a_guest_route(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/guest/auth/me')->assertStatus(401);
    }

    public function test_complete_profile_then_me_reports_complete(): void
    {
        $guest = Guest::factory()->unregistered()->create();
        $token = $guest->createToken('guest-api')->plainTextToken;

        $this->withToken($token)->patchJson('/api/v1/guest/profile', [
            'name' => 'Sara Al Amri',
            'email' => 'sara@example.com',
        ])->assertOk()->assertJsonPath('data.profile_complete', true);

        $this->assertDatabaseHas('guests', [
            'id' => $guest->id,
            'name' => 'Sara Al Amri',
            'email' => 'sara@example.com',
        ]);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $guest = Guest::factory()->create();
        $token = $guest->createToken('guest-api')->plainTextToken;
        $tokenId = explode('|', $token, 2)[0];

        $this->withToken($token)->postJson('/api/v1/guest/auth/logout')->assertOk();

        // Sanctum caches the resolved user for one test's Application instance,
        // so a second simulated request would misleadingly pass — assert the
        // deletion at the data layer, which is what a fresh process sees.
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_returning_guest_keeps_the_same_account(): void
    {
        $existing = Guest::factory()->create(['phone' => self::PHONE]);

        $challenge = $this->requestChallenge();
        $this->postJson('/api/v1/guest/auth/otp/verify', [
            'challenge_id' => $challenge['challenge_id'],
            'phone' => self::PHONE,
            'code' => self::CODE,
        ])->assertOk()
            ->assertJsonPath('data.outcome', 'authenticated')
            ->assertJsonPath('data.guest.id', $existing->id)
            ->assertJsonPath('data.profile_complete', true);

        $this->assertSame(1, Guest::where('phone', self::PHONE)->count());
    }

    /**
     * @return array{challenge_id: string, id: int}
     */
    private function requestChallenge(): array
    {
        $res = $this->postJson('/api/v1/guest/auth/otp/request', ['phone' => self::PHONE]);
        $res->assertOk();

        return [
            'challenge_id' => $res->json('data.challenge_id'),
            'id' => GuestOtpChallenge::where('public_id', $res->json('data.challenge_id'))->value('id'),
        ];
    }

    public function test_otp_requests_from_one_client_are_capped_across_phone_numbers(): void
    {
        config(['otp.rate_limits.request.per_ip_per_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/guest/auth/otp/request', ['phone' => "+96650000070{$i}"])
                ->assertOk();
        }

        $this->postJson('/api/v1/guest/auth/otp/request', ['phone' => '+966500000709'])
            ->assertStatus(429);
    }
}
