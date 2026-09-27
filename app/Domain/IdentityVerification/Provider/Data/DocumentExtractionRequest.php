<?php

namespace App\Domain\IdentityVerification\Provider\Data;

use App\Domain\IdentityVerification\DocumentCheck\IdentityDocumentType;
use LogicException;

/**
 * The input to IdentityDocumentProviderInterface::extract — the decrypted
 * document bytes, held in memory only for the duration of the call. Unlike
 * VerificationRequest this DTO necessarily carries the image, so it is
 * redacted from var_dump/print_r and refuses serialization (it can never be
 * queued, cached or logged by accident).
 */
final class DocumentExtractionRequest
{
    public function __construct(
        /** Opaque correlation id (the attempt's id), safe to log. */
        public readonly string $reference,
        #[\SensitiveParameter] public readonly string $bytes,
        public readonly string $mimeType,
        /** Guest-selected label as sent (kept for older callers). */
        public readonly ?string $documentTypeHint = null,
        /** The explicitly selected document type — decides the extraction route. */
        public readonly IdentityDocumentType $documentType = IdentityDocumentType::OtherIdentityDocument,
        /** Back side of a card, when the type needs / allows it. */
        #[\SensitiveParameter] public readonly ?string $backBytes = null,
        public readonly ?string $backMimeType = null,
    ) {}

    /** @return array<string, string|int> */
    public function __debugInfo(): array
    {
        return [
            'reference' => $this->reference,
            'documentType' => $this->documentType->value,
            'mimeType' => $this->mimeType,
            'bytes' => '[redacted '.strlen($this->bytes).' bytes]',
            'backBytes' => $this->backBytes === null ? null : '[redacted '.strlen($this->backBytes).' bytes]',
        ];
    }

    public function __serialize(): array
    {
        throw new LogicException('An identity document must never be serialized.');
    }
}
