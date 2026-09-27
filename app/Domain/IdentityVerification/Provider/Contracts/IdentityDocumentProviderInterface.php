<?php

namespace App\Domain\IdentityVerification\Provider\Contracts;

use App\Domain\IdentityVerification\Provider\Data\DocumentExtractionRequest;
use App\Domain\IdentityVerification\Provider\Data\DocumentExtractionResult;

/**
 * The OCR / document-extraction boundary: reads the structured identity
 * fields off an ID-document image. Sits next to (not inside)
 * IdentityVerificationProviderInterface, which remains the selfie-vs-document
 * face-match boundary.
 *
 *   IdentityDocumentProviderInterface
 *     ├── AzureDocumentIntelligenceProvider   (production)
 *     └── DummyIdentityDocumentProvider       (automated tests / local only)
 *
 * Same rules as the match provider: an implementation never touches a
 * session / attempt / decision, never opens a DB transaction, never writes
 * an audit row or a file, and never logs a document, extracted field or raw
 * provider payload. It is never called with a DB transaction open.
 *
 * `extract()` never throws for an unreadable document or a provider outage —
 * that is a {@see DocumentExtractionResult} failure. It throws only for a
 * programming / configuration error.
 */
interface IdentityDocumentProviderInterface
{
    /** Stable provider key stored on the attempt (`azure_document_intelligence`, `dummy`). */
    public function name(): string;

    public function extract(DocumentExtractionRequest $request): DocumentExtractionResult;

    /**
     * Delete the provider-side copy of an analysis (input document + result).
     * Returns true once it is gone (including "already gone").
     */
    public function deleteArtifact(string $artifactReference): bool;
}
