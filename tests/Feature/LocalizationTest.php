<?php

namespace Tests\Feature;

use App\Domain\IdentityAccess\Models\User;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    public function test_default_locale_returns_english_messages(): void
    {
        $user = User::factory()->groupOwner()->create(['password' => 'password']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()->assertJsonPath('message', 'Logged in successfully.');
    }

    public function test_x_locale_header_switches_response_messages_to_arabic(): void
    {
        $user = User::factory()->groupOwner()->create(['password' => 'password']);

        $response = $this->withHeader('X-Locale', 'ar')->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()->assertJsonPath('message', 'تم تسجيل الدخول بنجاح.');
    }

    public function test_unsupported_locale_falls_back_to_default(): void
    {
        $user = User::factory()->groupOwner()->create(['password' => 'password']);

        $response = $this->withHeader('X-Locale', 'fr')->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()->assertJsonPath('message', 'Logged in successfully.');
    }
}
