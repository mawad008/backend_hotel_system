<?php

namespace Tests\Unit\DigitalAccess\Provider;

use App\Domain\DigitalAccess\Provider\Support\AccessSensitiveDataGuard;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccessSensitiveDataGuardTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function secretKeys(): array
    {
        return [
            'pin_code' => ['pin_code'],
            'passcode' => ['passcode'],
            'access code (normalized)' => ['Access Code'],
            'door-code (normalized)' => ['door-code'],
            'credential' => ['credential_value'],
            'secret' => ['client_secret'],
            'token' => ['unlock_token'],
            'otp' => ['otp'],
        ];
    }

    #[DataProvider('secretKeys')]
    public function test_it_rejects_secret_metadata_keys(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);

        AccessSensitiveDataGuard::assertClean([$key => 'x'], 'test');
    }

    public function test_it_allows_safe_scalar_metadata_including_provider_code(): void
    {
        AccessSensitiveDataGuard::assertClean([
            'provider_code' => 'dummy_access_issue_active',
            'provider_message' => 'issued',
            'room_id' => 12,
        ], 'test');

        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_non_scalar_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AccessSensitiveDataGuard::assertClean(['x' => ['nested' => true]], 'test');
    }
}
