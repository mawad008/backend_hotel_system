<?php

namespace App\Domain\IdentityVerification\Provider;

use App\Domain\IdentityVerification\DocumentCheck\IdentityDocumentCatalog;
use App\Domain\IdentityVerification\DocumentCheck\IdentityDocumentType;
use App\Domain\IdentityVerification\Provider\Azure\DocumentFieldMapper;
use App\Domain\IdentityVerification\Provider\Azure\EgyptianNationalIdMapper;
use App\Domain\IdentityVerification\Provider\Azure\PrebuiltIdDocumentMapper;
use App\Domain\IdentityVerification\Provider\Azure\SaudiIdentityCardMapper;
use App\Domain\IdentityVerification\Provider\Contracts\IdentityDocumentProviderInterface;
use App\Domain\IdentityVerification\Provider\Data\DocumentExtractionRequest;
use App\Domain\IdentityVerification\Provider\Data\DocumentExtractionResult;

/**
 * Production document route selection, by the guest's EXPLICIT document
 * type (never guessed from OCR text):
 *
 *   PASSPORT              → Azure prebuilt-idDocument   (AZURE_DI_PASSPORT_MODEL)
 *   EGYPTIAN_NATIONAL_ID  → Azure custom model          (AZURE_DI_EGYPT_ID_MODEL)
 *   SAUDI_NATIONAL_ID     → Azure custom model          (AZURE_DI_SAUDI_ID_MODEL)
 *   SAUDI_IQAMA           → Azure custom model          (AZURE_DI_SAUDI_IQAMA_MODEL)
 *   other_id (legacy)     → Azure prebuilt-idDocument   (pre-existing behaviour)
 *
 * The router knows ONLY the real Azure adapter — there is no code path to
 * the dummy provider. A disabled type or an unconfigured model returns a
 * controlled `not_configured` failure (→ NEEDS_REVIEW, error logged), never
 * a success.
 */
final class DocumentProviderRouter implements IdentityDocumentProviderInterface
{
    public function __construct(
        private readonly AzureDocumentIntelligenceProvider $azure,
        private readonly IdentityDocumentCatalog $catalog,
    ) {}

    public function name(): string
    {
        return $this->azure->name();
    }

    public function extract(DocumentExtractionRequest $request): DocumentExtractionResult
    {
        $settings = $this->catalog->settings($request->documentType);

        if (! $settings->enabled) {
            return DocumentExtractionResult::failed(DocumentExtractionResult::FAILURE_NOT_CONFIGURED, 'document_type_disabled');
        }

        if (! $settings->modelConfigured()) {
            return DocumentExtractionResult::failed(DocumentExtractionResult::FAILURE_NOT_CONFIGURED, 'model_not_configured');
        }

        return $this->azure->analyze((string) $settings->model, $request, self::mapperFor($request->documentType));
    }

    public function deleteArtifact(string $artifactReference): bool
    {
        return $this->azure->deleteArtifact($artifactReference);
    }

    public static function mapperFor(IdentityDocumentType $type): DocumentFieldMapper
    {
        return match ($type) {
            IdentityDocumentType::Passport, IdentityDocumentType::OtherIdentityDocument => new PrebuiltIdDocumentMapper,
            IdentityDocumentType::EgyptianNationalId => new EgyptianNationalIdMapper,
            IdentityDocumentType::SaudiNationalId, IdentityDocumentType::SaudiIqama => new SaudiIdentityCardMapper($type),
        };
    }
}
