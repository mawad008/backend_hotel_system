<?php

namespace App\Http\Requests\Api\V1\IdentityVerification;

/**
 * The guest's own ID-document upload
 * (POST /api/v1/guest/reservations/{reservation}/identity/documents).
 *
 * Same as the staff request, but the guest MUST state the document number
 * and — unless the document encodes it in the number (Egyptian ID) — the
 * date of birth: without them the OCR result has nothing to be compared
 * against and could never be verified automatically.
 */
class GuestSubmitIdentityDocumentRequest extends SubmitIdentityDocumentRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['document_number'] = self::required($rules['document_number']);

        if (! $this->selectedType()->numberEncodesBirthDate()) {
            $rules['date_of_birth'] = self::required($rules['date_of_birth']);
        }

        return $rules;
    }

    /** Swap `sometimes|nullable` for `required` (closure rules kept). */
    private static function required(array $rules): array
    {
        return ['required', ...array_values(array_filter(
            $rules,
            fn (mixed $r) => ! (is_string($r) && in_array($r, ['sometimes', 'nullable'], true)),
        ))];
    }
}
