<?php

namespace App\Domain\IdentityVerification\Provider\Data;

/**
 * The result of one document extraction.
 *
 *  - document !== null            the provider read an identity document
 *  - document === null + failure  nothing usable: `no_document`, `timeout`,
 *                                 `rate_limited`, `provider_error`,
 *                                 `auth_error`, `invalid_input`
 *
 * `artifactReferences` identify provider-side data that still has to be
 * deleted — one per analysis (front and back are analysed separately);
 * empty when the provider kept nothing.
 * `providerStatus` is a short safe code for observability only.
 * `testFixture` is true only for the dummy provider's end-to-end test
 * fixture ({@see \App\Domain\IdentityVerification\Provider\DummyIdentityDocumentProvider::SCENARIO_EGYPTIAN_ID_FIXTURE});
 * a real provider never sets it.
 */
final class DocumentExtractionResult
{
    public const FAILURE_NO_DOCUMENT = 'no_document';

    public const FAILURE_TIMEOUT = 'timeout';

    public const FAILURE_RATE_LIMITED = 'rate_limited';

    public const FAILURE_PROVIDER_ERROR = 'provider_error';

    public const FAILURE_AUTH_ERROR = 'auth_error';

    public const FAILURE_INVALID_INPUT = 'invalid_input';

    /**
     * The route for this document type has no model configured. Never a
     * dummy fallback — the check becomes NEEDS_REVIEW and an error is logged.
     */
    public const FAILURE_NOT_CONFIGURED = 'not_configured';

    /** @param list<string> $artifactReferences */
    private function __construct(
        public readonly ?ExtractedIdentityDocument $document,
        public readonly ?string $failure,
        public readonly string $providerStatus,
        public readonly array $artifactReferences,
        public readonly bool $testFixture = false,
    ) {}

    /** @param string|list<string>|null $artifacts */
    public static function extracted(ExtractedIdentityDocument $document, string $providerStatus = 'succeeded', string|array|null $artifacts = null): self
    {
        return new self($document, null, $providerStatus, self::list($artifacts));
    }

    /**
     * A fixed test fixture (dummy provider only) — its document type may be
     * verified automatically outside production, see IdentityDocumentCheckService.
     */
    public static function testFixture(ExtractedIdentityDocument $document, string $artifact): self
    {
        return new self($document, null, 'test_fixture', self::list($artifact), testFixture: true);
    }

    /** @param string|list<string>|null $artifacts */
    public static function failed(string $failure, string $providerStatus, string|array|null $artifacts = null): self
    {
        return new self(null, $failure, $providerStatus, self::list($artifacts));
    }

    /** @param string|list<string>|null $artifacts */
    private static function list(string|array|null $artifacts): array
    {
        return array_values(array_filter((array) $artifacts, fn ($a) => is_string($a) && $a !== ''));
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['failure' => (string) $this->failure, 'providerStatus' => $this->providerStatus];
    }
}
