<?php

namespace App\Http\Requests\Api\V1\IdentityVerification;

use App\Domain\IdentityVerification\DocumentCheck\IdentityClaim;
use App\Domain\IdentityVerification\DocumentCheck\IdentityDocumentCatalog;
use App\Domain\IdentityVerification\DocumentCheck\IdentityDocumentType;
use App\Domain\IdentityVerification\DocumentCheck\IdentityTextNormalizer;
use App\Domain\IdentityVerification\DocumentCheck\NationalNumbers\EgyptianNationalIdNumber;
use App\Domain\IdentityVerification\DocumentCheck\NationalNumbers\NationalNumberRules;
use App\Rules\IdentityUploadContent;
use Closure;
use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Validation for an ID-document upload (staff
 * POST /api/v1/identity-verification/{reservation}/documents; the guest
 * variant {@see GuestSubmitIdentityDocumentRequest} tightens it).
 *
 * Images: `front_image` (or the original `document` field — same thing) and
 * `back_image`, which is required / optional / refused per document type
 * (config `verification.document_types.<type>.back`). Every image is
 * validated by its real content (magic bytes + full decode + size +
 * dimensions), not its extension.
 *
 * `document_type` is the guest's explicit selection (passport,
 * egyptian_national_id, saudi_national_id, saudi_iqama; legacy
 * national_id / residence_permit accepted). A disabled type is refused.
 *
 * The claim fields are what the guest says is on the document; they are
 * compared against the OCR result and then discarded — never stored,
 * logged or returned. Identity (user/hotel/role) is never read from the body.
 */
class SubmitIdentityDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Accept Arabic-Indic / Eastern Arabic digits in typed values.
        foreach (['document_number', 'date_of_birth'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => IdentityTextNormalizer::digits(trim($this->input($field)))]);
            }
        }

        foreach (['nationality', 'country'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => strtoupper(trim($this->input($field)))]);
            }
        }
    }

    public function rules(): array
    {
        $maxKb = (int) config('verification.storage.max_file_kb', 8192);
        $image = ['file', 'mimes:jpg,jpeg,png,pdf', "max:{$maxKb}", new IdentityUploadContent(['jpeg', 'png', 'pdf'])];
        $type = $this->selectedType();
        $settings = app(IdentityDocumentCatalog::class)->settings($type);

        return [
            'document' => ['required_without:front_image', ...$image],
            'front_image' => ['required_without:document', ...$image],
            'back_image' => match (true) {
                $settings->backRequired() => ['required', ...$image],
                $settings->backAllowed() => ['sometimes', 'nullable', ...$image],
                default => ['prohibited'],
            },

            'document_type' => [
                'sometimes', 'nullable', 'string', Rule::in(IdentityDocumentType::acceptedInputs()),
                function (string $attribute, mixed $value, Closure $fail) use ($settings): void {
                    if (! $settings->enabled) {
                        $fail(__('validation.in', ['attribute' => $attribute]));
                    }
                },
            ],

            // Issuing country (ISO alpha-3). Must agree with a type that fixes it.
            'country' => [
                'sometimes', 'nullable', 'string', 'regex:/^[A-Z]{3}$/',
                function (string $attribute, mixed $value, Closure $fail) use ($type): void {
                    if (is_string($value) && $type->issuingCountry() !== null && $value !== $type->issuingCountry()) {
                        $fail(__('validation.in', ['attribute' => $attribute]));
                    }
                },
            ],

            // The guest's claim (compared, never stored).
            'full_name' => ['sometimes', 'nullable', 'string', 'min:2', 'max:120', "regex:/^[\\p{L}\\p{M}\\s'’\\-.]+$/u"],
            'document_number' => [
                'sometimes', 'nullable', 'string', 'min:3', 'max:30', 'regex:/^[\p{L}\p{N}\s\-]+$/u',
                function (string $attribute, mixed $value, Closure $fail) use ($type): void {
                    // Egyptian / Saudi numbers must be structurally possible.
                    $inspection = is_string($value) ? NationalNumberRules::inspect($type, $value) : null;

                    if ($inspection !== null && ! $inspection->valid) {
                        $fail(__('validation.regex', ['attribute' => $attribute]));
                    }
                },
            ],
            'date_of_birth' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:1900-01-01', 'before:today'],
            'nationality' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{3}$/'],
        ];
    }

    public function selectedType(): IdentityDocumentType
    {
        $value = $this->input('document_type');

        return IdentityDocumentType::fromInput(is_string($value) ? $value : null);
    }

    /** Canonical type value to store (null when the client sent none). */
    public function documentType(): ?string
    {
        $value = $this->validated('document_type');

        return is_string($value) && $value !== '' ? IdentityDocumentType::fromInput($value)->value : null;
    }

    public function frontImage(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('front_image') ?? $this->file('document');

        return $file;
    }

    public function backImage(): ?UploadedFile
    {
        $file = $this->file('back_image');

        return $file instanceof UploadedFile ? $file : null;
    }

    /**
     * The claim, defaulting the name to the guest's profile name. For an
     * Egyptian ID the date of birth is encoded in the national number, so
     * when it is not typed it is derived from the TYPED number (documented
     * rule, not a guess).
     */
    public function claim(?string $profileName): IdentityClaim
    {
        $name = $this->validated('full_name');
        $number = $this->validated('document_number');
        $dob = $this->validated('date_of_birth');
        $nationality = $this->validated('nationality');
        $number = is_string($number) && $number !== '' ? $number : null;

        $birth = is_string($dob) && $dob !== '' ? new DateTimeImmutable($dob) : null;

        if ($birth === null && $number !== null && $this->selectedType()->numberEncodesBirthDate()) {
            $birth = EgyptianNationalIdNumber::inspect($number)->birthDate;
        }

        return new IdentityClaim(
            fullName: is_string($name) && trim($name) !== '' ? trim($name) : ($profileName ?: null),
            documentNumber: $number,
            dateOfBirth: $birth,
            nationality: is_string($nationality) && $nationality !== '' ? $nationality : null,
        );
    }
}
