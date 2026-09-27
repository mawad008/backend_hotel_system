<?php

namespace App\Domain\IdentityVerification\Provider\Azure;

use App\Domain\IdentityVerification\Provider\Data\ExtractedIdentityDocument;

/**
 * Maps a raw Azure `analyzeResult` (front, and back when analysed) into the
 * provider-neutral {@see ExtractedIdentityDocument}. One mapper per model
 * family; a custom model's mapper defines its LABELING CONTRACT — the field
 * names the model must be trained with (see
 * mobile/docs/identity-custom-ocr-models.md).
 *
 * A field the document does not carry maps to null — never an invented value.
 */
interface DocumentFieldMapper
{
    /**
     * @param  array<string, mixed>  $front  analyzeResult of the front image
     * @param  array<string, mixed>|null  $back  analyzeResult of the back image
     */
    public function map(array $front, ?array $back): ?ExtractedIdentityDocument;
}
