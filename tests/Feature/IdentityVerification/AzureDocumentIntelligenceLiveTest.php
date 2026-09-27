<?php

namespace Tests\Feature\IdentityVerification;

use App\Domain\IdentityVerification\DocumentCheck\Mrz\MrzParser;
use App\Domain\IdentityVerification\Provider\AzureDocumentIntelligenceProvider;
use App\Domain\IdentityVerification\Provider\Data\DocumentExtractionRequest;
use App\Domain\IdentityVerification\Provider\Data\ExtractedIdentityDocument;
use Illuminate\Http\Client\Factory;
use Tests\TestCase;

/**
 * LIVE integration test against a real Azure Document Intelligence resource.
 * Skipped unless explicitly enabled — it costs one ID-model page per run.
 *
 *   AZURE_DI_LIVE_TEST=1
 *   AZURE_DOCUMENT_INTELLIGENCE_ENDPOINT=https://<resource>.cognitiveservices.azure.com
 *   AZURE_DOCUMENT_INTELLIGENCE_KEY=<key>
 *   AZURE_DI_SAMPLE_PASSPORT=/abs/path/to/SYNTHETIC-specimen-passport.jpg
 *
 * The sample MUST be a synthetic / specimen document (e.g. Microsoft's
 * published sample ID images or an ICAO specimen) — never a real person's
 * document, and never committed to the repository.
 */
class AzureDocumentIntelligenceLiveTest extends TestCase
{
    public function test_real_provider_extracts_a_specimen_passport_and_deletes_its_copy(): void
    {
        $endpoint = (string) getenv('AZURE_DOCUMENT_INTELLIGENCE_ENDPOINT');
        $key = (string) getenv('AZURE_DOCUMENT_INTELLIGENCE_KEY');
        $sample = (string) getenv('AZURE_DI_SAMPLE_PASSPORT');

        if (getenv('AZURE_DI_LIVE_TEST') !== '1' || $endpoint === '' || $key === '' || ! is_file($sample)) {
            $this->markTestSkipped('Live Azure test disabled (set AZURE_DI_LIVE_TEST=1 + credentials + AZURE_DI_SAMPLE_PASSPORT).');
        }

        $provider = new AzureDocumentIntelligenceProvider(app(Factory::class), $endpoint, $key);
        $bytes = (string) file_get_contents($sample);

        $result = $provider->extract(new DocumentExtractionRequest('live-test', $bytes, 'image/jpeg', 'passport'));

        $this->assertNull($result->failure, 'provider status: '.$result->providerStatus);
        $this->assertSame(ExtractedIdentityDocument::KIND_PASSPORT, $result->document->kind);
        $this->assertNotEmpty($result->document->documentNumber);
        $this->assertNotEmpty($result->document->dateOfExpiry);
        $this->assertNotNull(MrzParser::parse($result->document->mrzText), 'the MRZ should be read');

        $this->assertNotEmpty($result->artifactReferences);
        foreach ($result->artifactReferences as $ref) {
            $this->assertTrue($provider->deleteArtifact($ref), 'Delete Analyze Result must succeed');
        }
    }
}
