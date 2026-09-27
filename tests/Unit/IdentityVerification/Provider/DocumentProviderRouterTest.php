<?php

namespace Tests\Unit\IdentityVerification\Provider;

use App\Domain\IdentityVerification\DocumentCheck\IdentityDocumentCatalog;
use App\Domain\IdentityVerification\DocumentCheck\IdentityDocumentType as T;
use App\Domain\IdentityVerification\Provider\AzureDocumentIntelligenceProvider;
use App\Domain\IdentityVerification\Provider\Data\DocumentExtractionRequest;
use App\Domain\IdentityVerification\Provider\Data\DocumentExtractionResult;
use App\Domain\IdentityVerification\Provider\Data\ExtractedIdentityDocument;
use App\Domain\IdentityVerification\Provider\DocumentProviderRouter;
use App\Domain\IdentityVerification\Provider\DummyIdentityDocumentProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use ReflectionClass;
use Tests\TestCase;

/**
 * Routing by explicit document type, and the custom-model field mapping,
 * against the documented Azure REST shapes (HTTP faked, synthetic values).
 */
class DocumentProviderRouterTest extends TestCase
{
    private const ENDPOINT = 'https://router-test.cognitiveservices.azure.com';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config([
            'verification.document_types.passport.model' => 'prebuilt-idDocument',
            'verification.document_types.egyptian_national_id.model' => 'egypt-id-v3',
            'verification.document_types.saudi_national_id.model' => 'saudi-id-v2',
            'verification.document_types.saudi_iqama.model' => 'saudi-iqama-v2',
        ]);
    }

    private function router(Factory $http): DocumentProviderRouter
    {
        return new DocumentProviderRouter(
            new AzureDocumentIntelligenceProvider($http, self::ENDPOINT, 'secret-key'),
            new IdentityDocumentCatalog,
        );
    }

    private function request(T $type, bool $back = false): DocumentExtractionRequest
    {
        return new DocumentExtractionRequest('7', "\xFF\xD8\xFFfront", 'image/jpeg', $type->value, $type, $back ? "\xFF\xD8\xFFback" : null, $back ? 'image/jpeg' : null);
    }

    /** A faked Azure that answers every model with the given fields per side. */
    private function azure(array $frontFields, array $backFields = [], ?callable $record = null): Factory
    {
        $factory = new Factory;
        $counter = 0;
        $factory->fake(function (Request $r) use (&$counter, $frontFields, $backFields, $record) {
            $record && $record($r);

            if ($r->method() === 'POST') {
                $counter++;
                preg_match('#documentModels/([^:]+):analyze#', $r->url(), $m);
                $id = sprintf('00000000-0000-0000-0000-%012d', $counter);

                return Http::response('', 202, ['Operation-Location' => self::ENDPOINT."/documentintelligence/documentModels/{$m[1]}/analyzeResults/{$id}?api-version=2024-11-30"]);
            }

            if ($r->method() === 'GET') {
                $isBack = str_ends_with(explode('?', $r->url())[0], '000000000002');
                $fields = $isBack ? $backFields : $frontFields;

                return Http::response(['status' => 'succeeded', 'analyzeResult' => ['documents' => $fields === [] ? [] : [['docType' => 'custom', 'fields' => $fields]]]], 200);
            }

            return Http::response('', 204);
        });

        return $factory;
    }

    private static function s(string $v, float $c = 0.95): array
    {
        return ['type' => 'string', 'valueString' => $v, 'content' => $v, 'confidence' => $c];
    }

    // ── Routing ─────────────────────────────────────────────────────

    public function test_each_type_goes_to_its_configured_model(): void
    {
        $expected = [
            'passport' => 'prebuilt-idDocument',
            'egyptian_national_id' => 'egypt-id-v3',
            'saudi_national_id' => 'saudi-id-v2',
            'saudi_iqama' => 'saudi-iqama-v2',
            'other_id' => 'prebuilt-idDocument',
        ];

        foreach ($expected as $type => $model) {
            $urls = [];
            $this->router($this->azure(['X' => self::s('x')], record: function (Request $r) use (&$urls) {
                if ($r->method() === 'POST') {
                    $urls[] = $r->url();
                }
            }))->extract($this->request(T::from($type)));

            $this->assertCount(1, $urls, $type);
            $this->assertStringContainsString("/documentModels/{$model}:analyze", $urls[0], $type);
        }
    }

    public function test_missing_model_is_a_controlled_failure_with_no_http_call_and_no_dummy(): void
    {
        config(['verification.document_types.egyptian_national_id.model' => null]);
        $http = new Factory;
        $http->fake();

        $result = $this->router($http)->extract($this->request(T::EgyptianNationalId, back: true));

        $this->assertNull($result->document);
        $this->assertSame(DocumentExtractionResult::FAILURE_NOT_CONFIGURED, $result->failure);
        $this->assertSame('model_not_configured', $result->providerStatus);
        $http->assertNothingSent();
    }

    public function test_disabled_type_is_a_controlled_failure(): void
    {
        config(['verification.document_types.saudi_iqama.enabled' => false]);
        $http = new Factory;
        $http->fake();

        $result = $this->router($http)->extract($this->request(T::SaudiIqama));

        $this->assertSame(DocumentExtractionResult::FAILURE_NOT_CONFIGURED, $result->failure);
        $this->assertSame('document_type_disabled', $result->providerStatus);
        $http->assertNothingSent();
    }

    public function test_a_model_id_that_does_not_exist_on_azure_is_not_configured(): void
    {
        $http = new Factory;
        $http->fake(['*' => Http::response(['error' => ['code' => 'ModelNotFound']], 404)]);

        $this->assertSame(DocumentExtractionResult::FAILURE_NOT_CONFIGURED, $this->router($http)->extract($this->request(T::SaudiNationalId))->failure);
    }

    public function test_the_router_has_no_reference_to_the_dummy_provider(): void
    {
        $source = file_get_contents((new ReflectionClass(DocumentProviderRouter::class))->getFileName());

        $this->assertStringNotContainsString('Dummy', preg_replace('#/\*.*?\*/#s', '', $source));
        $this->assertStringNotContainsString(DummyIdentityDocumentProvider::class, $source);
    }

    // ── Front + back ────────────────────────────────────────────────

    public function test_front_and_back_are_two_analyses_and_both_artifacts_are_reported(): void
    {
        $posts = 0;
        $result = $this->router($this->azure(
            ['FirstName' => self::s('سامي'), 'FamilyNames' => self::s('عادل فؤاد منصور'), 'NationalIdNumber' => self::s('٢٩٠٠١١٥٠١١٢٣٥٧'), 'Address' => self::s('القاهرة')],
            ['NationalIdNumber' => self::s('٢٩٠٠١١٥٠١١٢٣٥٧'), 'Gender' => self::s('ذكر'), 'ExpiryDate' => self::s('٢٠٣١/٠١/٠١')],
            function (Request $r) use (&$posts) {
                if ($r->method() === 'POST') {
                    $posts++;
                }
            },
        ))->extract($this->request(T::EgyptianNationalId, back: true));

        $this->assertSame(2, $posts);
        $this->assertCount(2, $result->artifactReferences);
        $this->assertStringStartsWith('egypt-id-v3/', $result->artifactReferences[0]);
        $doc = $result->document;
        $this->assertSame(ExtractedIdentityDocument::KIND_NATIONAL_ID, $doc->kind);
        $this->assertSame('سامي', $doc->givenNames);
        $this->assertSame('عادل فؤاد منصور', $doc->surname);
        $this->assertSame('٢٩٠٠١١٥٠١١٢٣٥٧', $doc->documentNumber);
        $this->assertSame('٢٠٣١/٠١/٠١', $doc->dateOfExpiry);
        $this->assertNull($doc->dateOfBirth, 'the card prints no birth date — it is derived later, never invented');
        $this->assertSame('male', $doc->gender);
        $this->assertSame('EGY', $doc->issuingCountry);
        $this->assertSame([], $doc->consistencyIssues);
    }

    public function test_egyptian_front_back_number_disagreement_is_flagged(): void
    {
        $doc = $this->router($this->azure(
            ['FirstName' => self::s('سامي'), 'FamilyNames' => self::s('منصور'), 'NationalIdNumber' => self::s('29001150112357')],
            ['NationalIdNumber' => self::s('29001150112358'), 'ExpiryDate' => self::s('2031-01-01')],
        ))->extract($this->request(T::EgyptianNationalId, back: true))->document;

        $this->assertSame(['front_back_number_conflict'], $doc->consistencyIssues);
    }

    public function test_a_failing_back_analysis_fails_the_extraction_but_keeps_both_artifacts(): void
    {
        $factory = new Factory;
        $n = 0;
        $factory->fake(function (Request $r) use (&$n) {
            if ($r->method() === 'POST') {
                $n++;

                return Http::response('', 202, ['Operation-Location' => self::ENDPOINT.'/documentintelligence/documentModels/egypt-id-v3/analyzeResults/00000000-0000-0000-0000-00000000000'.$n]);
            }

            return str_ends_with(explode('?', $r->url())[0], '1')
                ? Http::response(['status' => 'succeeded', 'analyzeResult' => ['documents' => [['fields' => ['NationalIdNumber' => self::s('29001150112357')]]]]])
                : Http::response(['status' => 'failed', 'error' => ['code' => 'InternalServerError']]);
        });

        $result = $this->router($factory)->extract($this->request(T::EgyptianNationalId, back: true));

        $this->assertSame(DocumentExtractionResult::FAILURE_PROVIDER_ERROR, $result->failure);
        $this->assertSame('back_analysis_failed', $result->providerStatus);
        $this->assertCount(2, $result->artifactReferences);
    }

    // ── Saudi mappers ───────────────────────────────────────────────

    public function test_saudi_national_id_mapping_keeps_both_name_scripts(): void
    {
        $doc = $this->router($this->azure([
            'FullNameArabic' => self::s('فهد سالم ناصر الحربي'),
            'FullNameLatin' => self::s('FAHAD SALEM NASSER ALHARBI'),
            'IdNumber' => self::s('١٠٩٨٧٦٥٤٣٢'),
            'DateOfBirth' => self::s('١٤١٠/٠٣/١٥ هـ'),
            'ExpiryDate' => self::s('1452/01/01'),
        ]))->extract($this->request(T::SaudiNationalId))->document;

        $this->assertSame(ExtractedIdentityDocument::KIND_NATIONAL_ID, $doc->kind);
        $this->assertSame('فهد سالم ناصر', $doc->givenNames);
        $this->assertSame('الحربي', $doc->surname);
        $this->assertSame('FAHAD SALEM NASSER', $doc->alternateGivenNames);
        $this->assertSame('ALHARBI', $doc->alternateSurname);
        $this->assertSame('١٠٩٨٧٦٥٤٣٢', $doc->documentNumber);
        $this->assertNull($doc->nationality, 'not printed on this version → null, never assumed');
        $this->assertSame('SAU', $doc->issuingCountry);
    }

    public function test_saudi_iqama_mapping_is_a_distinct_schema(): void
    {
        $doc = $this->router($this->azure([
            'FullNameArabic' => self::s('راجيف كومار شارما'),
            'IqamaNumber' => self::s('2098765432'),
            'Nationality' => self::s('هندي'),
            'ExpiryDate' => self::s('1451/06/01'),
            // An `IdNumber` label must NOT be read for an Iqama.
            'IdNumber' => self::s('1111111111'),
        ]))->extract($this->request(T::SaudiIqama))->document;

        $this->assertSame(ExtractedIdentityDocument::KIND_RESIDENCE_PERMIT, $doc->kind);
        $this->assertSame('2098765432', $doc->documentNumber);
        $this->assertSame('IND', $doc->nationality);
        $this->assertNull($doc->dateOfBirth);
    }

    public function test_an_empty_custom_analysis_is_no_document(): void
    {
        $result = $this->router($this->azure([]))->extract($this->request(T::SaudiIqama));

        $this->assertSame(DocumentExtractionResult::FAILURE_NO_DOCUMENT, $result->failure);
        $this->assertCount(1, $result->artifactReferences, 'still deleted');
    }

    public function test_artifacts_are_deleted_against_their_own_model(): void
    {
        $http = new Factory;
        $http->fake(['*' => Http::response('', 204)]);

        $this->assertTrue($this->router($http)->deleteArtifact('saudi-iqama-v2/00000000-0000-0000-0000-000000000001'));
        $this->assertTrue($this->router($http)->deleteArtifact('00000000-0000-0000-0000-000000000009'));

        $http->assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_contains($r->url(), '/documentModels/saudi-iqama-v2/analyzeResults/00000000-0000-0000-0000-000000000001'));
        $http->assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_contains($r->url(), '/documentModels/prebuilt-idDocument/analyzeResults/00000000-0000-0000-0000-000000000009'));
    }

    public function test_a_tampered_artifact_reference_is_never_put_in_a_url(): void
    {
        $http = new Factory;
        $http->fake();

        $this->assertTrue($this->router($http)->deleteArtifact('../../x/00000000-0000-0000-0000-000000000001'));
        $this->assertTrue($this->router($http)->deleteArtifact('egypt-id-v3/../../secret'));
        $http->assertNothingSent();
    }
}
