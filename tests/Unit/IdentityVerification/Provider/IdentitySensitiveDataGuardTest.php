<?php

namespace Tests\Unit\IdentityVerification\Provider;

use App\Domain\IdentityVerification\Provider\Support\IdentitySensitiveDataGuard;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IdentitySensitiveDataGuardTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sensitiveKeys(): array
    {
        return [
            'document number' => ['document_number'],
            'passport number' => ['passport_no'],
            'national id' => ['national_id'],
            'date of birth' => ['date_of_birth'],
            'dob short' => ['dob'],
            'selfie' => ['selfie_base64'],
            'raw image' => ['image'],
            'secret' => ['api_secret'],
            'hyphen/space normalized' => ['ID Number'],
        ];
    }

    #[DataProvider('sensitiveKeys')]
    public function test_it_rejects_sensitive_metadata_keys(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);

        IdentitySensitiveDataGuard::assertClean([$key => 'value'], 'test');
    }

    public function test_it_allows_safe_scalar_metadata(): void
    {
        IdentitySensitiveDataGuard::assertClean([
            'document_type' => 'passport',
            'attempt_number' => 2,
        ], 'test');

        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_non_scalar_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IdentitySensitiveDataGuard::assertClean(['x' => ['nested' => 'array']], 'test');
    }

    public function test_it_rejects_nested_sensitive_keys_in_a_payload(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IdentitySensitiveDataGuard::assertNoSensitiveKeys([
            'outer' => ['passport_number' => 'X1'],
        ], 'test');
    }
}
