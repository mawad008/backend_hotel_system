<?php

namespace Tests\Unit\IdentityVerification\Provider;

use App\Domain\IdentityVerification\Provider\AzureDocumentIntelligenceProvider;
use App\Domain\IdentityVerification\Provider\Contracts\IdentityDocumentProviderInterface;
use App\Domain\IdentityVerification\Provider\DocumentProviderRouter;
use App\Domain\IdentityVerification\Provider\DummyIdentityDocumentProvider;
use App\Domain\IdentityVerification\Provider\Exceptions\UnsupportedIdentityVerificationProviderException;
use Tests\TestCase;

class IdentityDocumentProviderBindingTest extends TestCase
{
    private function resolve(): IdentityDocumentProviderInterface
    {
        $this->app->forgetInstance(IdentityDocumentProviderInterface::class);

        return $this->app->make(IdentityDocumentProviderInterface::class);
    }

    public function test_dummy_is_the_test_default(): void
    {
        $this->assertInstanceOf(DummyIdentityDocumentProvider::class, $this->resolve());
    }

    public function test_azure_is_resolved_from_config(): void
    {
        config([
            'verification.document_provider' => 'azure_document_intelligence',
            'verification.document_providers.azure_document_intelligence.endpoint' => 'https://x.cognitiveservices.azure.com',
            'verification.document_providers.azure_document_intelligence.key' => 'k',
        ]);

        $this->assertInstanceOf(DocumentProviderRouter::class, $this->resolve());
    }

    public function test_production_refuses_the_dummy_provider(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(UnsupportedIdentityVerificationProviderException::class);
        $this->resolve();
    }

    public function test_an_unknown_provider_fails_loudly(): void
    {
        config(['verification.document_provider' => 'textract']);

        $this->expectException(UnsupportedIdentityVerificationProviderException::class);
        $this->resolve();
    }

    public function test_azure_without_credentials_fails_loudly_instead_of_silently_degrading(): void
    {
        config([
            'verification.document_provider' => 'azure_document_intelligence',
            'verification.document_providers.azure_document_intelligence.endpoint' => 'https://x.cognitiveservices.azure.com',
            'verification.document_providers.azure_document_intelligence.key' => '',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->resolve();
    }
}
