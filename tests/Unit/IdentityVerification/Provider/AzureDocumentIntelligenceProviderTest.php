<?php

namespace Tests\Unit\IdentityVerification\Provider;

use App\Domain\IdentityVerification\Provider\AzureDocumentIntelligenceProvider;
use App\Domain\IdentityVerification\Provider\Data\DocumentExtractionRequest;
use App\Domain\IdentityVerification\Provider\Data\DocumentExtractionResult;
use App\Domain\IdentityVerification\Provider\Data\ExtractedIdentityDocument;
use App\Domain\IdentityVerification\Provider\DummyIdentityDocumentProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Contract tests for the Azure adapter against the documented
 * `prebuilt-idDocument` v4.0 (2024-11-30) request/response shapes, with the
 * HTTP layer faked. Synthetic specimen data only.
 */
class AzureDocumentIntelligenceProviderTest extends TestCase
{
    private const ENDPOINT = 'https://unit-test.cognitiveservices.azure.com';

    private const RESULT_ID = '3b31320d-8bab-4f88-b19c-2322a7f11034';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function provider(int $timeout = 30, ?Factory $http = null): AzureDocumentIntelligenceProvider
    {
        return new AzureDocumentIntelligenceProvider($http ?? app(Factory::class), self::ENDPOINT, 'secret-key', timeoutSeconds: $timeout);
    }

    /** A fresh, independently faked HTTP client (Http::fake() stubs stack). */
    private function faked(array $stubs): Factory
    {
        $factory = new Factory;
        $factory->fake($stubs);

        return $factory;
    }

    private function request(): DocumentExtractionRequest
    {
        return new DocumentExtractionRequest('42', "\xFF\xD8\xFFfake-jpeg-bytes", 'image/jpeg', 'passport');
    }

    private function operation(): string
    {
        return self::ENDPOINT.'/documentintelligence/documentModels/prebuilt-idDocument/analyzeResults/'.self::RESULT_ID.'?api-version=2024-11-30';
    }

    /** @return array<string, mixed> */
    private function passportResult(): array
    {
        $mrz = DummyIdentityDocumentProvider::td3('ERIKSSON', 'ANNA MARIA', 'L898902C3', 'UTO', '1974-08-12', '2034-04-15');

        return [
            'status' => 'succeeded',
            'analyzeResult' => [
                'apiVersion' => '2024-11-30',
                'modelId' => 'prebuilt-idDocument',
                'content' => "UTOPIA\nPASSPORT\n".$mrz,
                'documents' => [[
                    'docType' => 'idDocument.passport',
                    'confidence' => 0.99,
                    'fields' => [
                        'FirstName' => ['type' => 'string', 'valueString' => 'ANNA', 'content' => 'ANNA', 'confidence' => 0.98],
                        'MiddleName' => ['type' => 'string', 'valueString' => 'MARIA', 'content' => 'MARIA', 'confidence' => 0.97],
                        'LastName' => ['type' => 'string', 'valueString' => 'ERIKSSON', 'content' => 'ERIKSSON', 'confidence' => 0.99],
                        'DocumentNumber' => ['type' => 'string', 'valueString' => 'L898902C3', 'content' => 'L898902C3', 'confidence' => 0.99],
                        'DateOfBirth' => ['type' => 'date', 'valueDate' => '1974-08-12', 'content' => '12 AUG 1974', 'confidence' => 0.99],
                        'DateOfExpiration' => ['type' => 'date', 'valueDate' => '2034-04-15', 'content' => '15 APR 2034', 'confidence' => 0.99],
                        'Nationality' => ['type' => 'countryRegion', 'valueCountryRegion' => 'UTO', 'content' => 'UTOPIAN', 'confidence' => 0.95],
                        'CountryRegion' => ['type' => 'countryRegion', 'valueCountryRegion' => 'UTO', 'confidence' => 0.95],
                        'MachineReadableZone' => ['type' => 'object', 'content' => $mrz, 'confidence' => 0.99, 'valueObject' => []],
                    ],
                ]],
            ],
        ];
    }

    public function test_submits_base64_to_the_id_model_polls_and_maps_the_fields(): void
    {
        Http::fake([
            self::ENDPOINT.'/documentintelligence/documentModels/prebuilt-idDocument:analyze*' => Http::response('', 202, ['Operation-Location' => $this->operation()]),
            self::ENDPOINT.'/documentintelligence/documentModels/prebuilt-idDocument/analyzeResults/*' => Http::sequence()
                ->push(['status' => 'running'], 200, ['Retry-After' => '1'])
                ->push($this->passportResult(), 200),
        ]);

        $result = $this->provider()->extract($this->request());

        $this->assertNull($result->failure);
        $this->assertSame(['prebuilt-idDocument/'.self::RESULT_ID], $result->artifactReferences);
        $doc = $result->document;
        $this->assertSame(ExtractedIdentityDocument::KIND_PASSPORT, $doc->kind);
        $this->assertSame('ANNA MARIA', $doc->givenNames);
        $this->assertSame('ERIKSSON', $doc->surname);
        $this->assertSame('L898902C3', $doc->documentNumber);
        $this->assertSame('1974-08-12', $doc->dateOfBirth);
        $this->assertSame('2034-04-15', $doc->dateOfExpiry);
        $this->assertSame('UTO', $doc->nationality);
        $this->assertStringStartsWith('P<UTOERIKSSON', $doc->mrzText);
        $this->assertSame(0.98, $doc->confidenceOf('name'));

        Http::assertSent(function (Request $r): bool {
            if ($r->method() !== 'POST') {
                return false;
            }

            return str_contains($r->url(), 'prebuilt-idDocument:analyze?api-version=2024-11-30')
                && $r->hasHeader('Ocp-Apim-Subscription-Key', 'secret-key')
                && base64_decode($r['base64Source']) === "\xFF\xD8\xFFfake-jpeg-bytes";
        });
    }

    public function test_the_key_is_never_sent_to_a_foreign_operation_location(): void
    {
        Http::fake([
            '*:analyze*' => Http::response('', 202, ['Operation-Location' => 'https://evil.example.com/analyzeResults/'.self::RESULT_ID]),
            '*' => Http::response(['status' => 'succeeded'], 200),
        ]);

        $result = $this->provider()->extract($this->request());

        $this->assertSame(DocumentExtractionResult::FAILURE_PROVIDER_ERROR, $result->failure);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.example.com'));
    }

    public function test_http_errors_map_to_fixed_codes(): void
    {
        foreach ([401 => 'auth_error', 403 => 'auth_error', 429 => 'rate_limited', 400 => 'invalid_input', 500 => 'provider_error'] as $status => $code) {
            $http = $this->faked(['*' => Http::response(['error' => ['code' => 'X', 'message' => 'details that must not leak']], $status)]);

            $result = $this->provider(http: $http)->extract($this->request());

            $this->assertSame($code, $result->failure, "HTTP {$status}");
            $this->assertNull($result->document);
        }
    }

    public function test_failed_analysis_and_empty_documents(): void
    {
        $http = $this->faked([
            '*:analyze*' => Http::response('', 202, ['Operation-Location' => $this->operation()]),
            '*analyzeResults*' => Http::response(['status' => 'failed', 'error' => ['code' => 'InvalidContent']], 200),
        ]);
        $this->assertSame(DocumentExtractionResult::FAILURE_INVALID_INPUT, $this->provider(http: $http)->extract($this->request())->failure);

        $http = $this->faked([
            '*:analyze*' => Http::response('', 202, ['Operation-Location' => $this->operation()]),
            '*analyzeResults*' => Http::response(['status' => 'succeeded', 'analyzeResult' => ['documents' => []]], 200),
        ]);
        $empty = $this->provider(http: $http)->extract($this->request());
        $this->assertSame(DocumentExtractionResult::FAILURE_NO_DOCUMENT, $empty->failure);
        $this->assertSame(['prebuilt-idDocument/'.self::RESULT_ID], $empty->artifactReferences, 'even an empty analysis must be deleted');
    }

    public function test_polling_gives_up_at_the_deadline_and_reports_the_artifact(): void
    {
        Http::fake([
            '*:analyze*' => Http::response('', 202, ['Operation-Location' => $this->operation(), 'Retry-After' => '3']),
            '*analyzeResults*' => Http::response(['status' => 'running'], 200, ['Retry-After' => '3']),
        ]);

        $result = $this->provider(timeout: 1)->extract($this->request());

        $this->assertSame(DocumentExtractionResult::FAILURE_TIMEOUT, $result->failure);
        $this->assertSame(['prebuilt-idDocument/'.self::RESULT_ID], $result->artifactReferences);
    }

    public function test_driver_licence_doc_type_maps_to_its_kind(): void
    {
        $body = $this->passportResult();
        $body['analyzeResult']['documents'][0]['docType'] = 'idDocument.driverLicense';

        Http::fake([
            '*:analyze*' => Http::response('', 202, ['Operation-Location' => $this->operation()]),
            '*analyzeResults*' => Http::response($body, 200),
        ]);

        $this->assertSame(ExtractedIdentityDocument::KIND_DRIVER_LICENSE, $this->provider()->extract($this->request())->document->kind);
    }

    public function test_delete_artifact_calls_delete_analyze_result(): void
    {
        Http::fake(['*' => Http::response('', 204)]);

        $this->assertTrue($this->provider()->deleteArtifact(self::RESULT_ID));

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && $r->url() === self::ENDPOINT.'/documentintelligence/documentModels/prebuilt-idDocument/analyzeResults/'.self::RESULT_ID.'?api-version=2024-11-30');
    }

    public function test_delete_artifact_treats_404_as_gone_and_5xx_as_pending(): void
    {
        $this->assertTrue($this->provider(http: $this->faked(['*' => Http::response('', 404)]))->deleteArtifact(self::RESULT_ID));
        $this->assertFalse($this->provider(http: $this->faked(['*' => Http::response('', 503)]))->deleteArtifact(self::RESULT_ID));
    }

    public function test_a_malformed_artifact_reference_is_never_put_in_a_url(): void
    {
        Http::fake();

        $this->assertTrue($this->provider()->deleteArtifact('../../other/model'));
        Http::assertNothingSent();
    }

    public function test_configuration_is_validated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AzureDocumentIntelligenceProvider(app(Factory::class), 'http://insecure.example.com', 'k');
    }

    public function test_request_and_result_objects_never_expose_pii_when_dumped_or_serialized(): void
    {
        $request = $this->request();
        $this->assertStringNotContainsString('fake-jpeg-bytes', print_r($request, true));

        $this->expectException(\LogicException::class);
        serialize($request);
    }
}
