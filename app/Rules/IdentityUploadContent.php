<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Validates an identity upload by its ACTUAL content, not its name or the
 * client-declared Content-Type:
 *  - the magic bytes must be one of the allowed types (JPEG / PNG / PDF);
 *  - an image must fully decode (getimagesize) as that same type, within
 *    10 000 × 10 000 px (the OCR provider's limit);
 *  - a PDF must not carry active content (JavaScript, launch actions,
 *    embedded files).
 */
final class IdentityUploadContent implements ValidationRule
{
    private const MAX_DIMENSION = 10000;

    /** @param list<'jpeg'|'png'|'pdf'> $allowed */
    public function __construct(private readonly array $allowed) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail(__('validation.file', ['attribute' => $attribute]));

            return;
        }

        $handle = @fopen($value->getRealPath(), 'rb');
        $head = $handle ? (string) fread($handle, 16) : '';
        $handle && fclose($handle);

        $type = match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($head, '%PDF-') => 'pdf',
            default => null,
        };

        if ($type === null || ! in_array($type, $this->allowed, true)) {
            $fail(__('validation.mimes', ['attribute' => $attribute, 'values' => implode(', ', $this->allowed)]));

            return;
        }

        if ($type === 'pdf') {
            $content = (string) file_get_contents($value->getRealPath());

            if (preg_match('#/(JavaScript|JS|Launch|EmbeddedFile|RichMedia|XFA)\b#', $content) === 1) {
                $fail(__('validation.mimes', ['attribute' => $attribute, 'values' => implode(', ', $this->allowed)]));
            }

            return;
        }

        $info = @getimagesize($value->getRealPath());
        $expected = $type === 'jpeg' ? IMAGETYPE_JPEG : IMAGETYPE_PNG;

        if ($info === false || $info[2] !== $expected
            || $info[0] < 1 || $info[1] < 1
            || $info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION) {
            $fail(__('validation.image', ['attribute' => $attribute]));
        }
    }
}
