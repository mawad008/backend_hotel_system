<?php

namespace Tests\Feature\Auth;

use App\Domain\IdentityAccess\Models\User;
use Tests\TestCase;

class LoginTest extends TestCase
{
    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->groupOwner()->create(['password' => 'correct-password']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonStructure(['success', 'message', 'data' => ['user', 'token']]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->groupOwner()->create(['password' => 'correct-password']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    public function test_login_fails_for_unknown_email(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        $response->assertStatus(401)->assertJson(['success' => false]);
    }

    public function test_login_is_denied_for_inactive_account(): void
    {
        $user = User::factory()->groupOwner()->inactive()->create(['password' => 'correct-password']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertStatus(403)->assertJson(['success' => false]);
    }

    public function test_login_requires_email_and_password(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_authenticated_user_can_fetch_their_profile(): void
    {
        $user = User::factory()->hotelManager()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.role.slug', 'hotel_manager');
    }

    public function test_guest_request_to_me_is_unauthenticated(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401)->assertJson(['success' => false, 'message' => 'Unauthenticated.']);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->groupOwner()->create(['password' => 'correct-password']);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $token = $login->json('data.token');
        $tokenId = explode('|', $token, 2)[0];

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // The Sanctum auth guard caches the resolved user for the lifetime
        // of a single test's Application instance, so a second simulated
        // request re-using the same token would misleadingly still pass
        // here even though the token is gone — assert the deletion at the
        // data layer instead, which is what a fresh process would see.
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_repeated_failed_logins_are_rate_limited(): void
    {
        config(['auth.login_rate_limit.per_minute' => 3]);
        $user = User::factory()->create(['password' => 'password']);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])
                ->assertStatus(401);
        }

        // Even the right password is refused until the window passes.
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(429);
    }
}
