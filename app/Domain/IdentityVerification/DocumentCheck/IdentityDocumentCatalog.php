<?php

namespace App\Domain\IdentityVerification\DocumentCheck;

/**
 * Reads the per-document-type configuration. The single place that knows
 * which types are enabled, which Azure model serves each, whether a back
 * image is needed and whether automatic verification is allowed.
 */
class IdentityDocumentCatalog
{
    public function settings(IdentityDocumentType $type): DocumentTypeSettings
    {
        $c = (array) config('verification.document_types.'.$type->value, []);
        $back = (string) ($c['back'] ?? DocumentTypeSettings::BACK_NONE);

        return new DocumentTypeSettings(
            type: $type,
            enabled: (bool) ($c['enabled'] ?? false),
            model: isset($c['model']) && trim((string) $c['model']) !== '' ? trim((string) $c['model']) : null,
            back: in_array($back, [DocumentTypeSettings::BACK_NONE, DocumentTypeSettings::BACK_OPTIONAL, DocumentTypeSettings::BACK_REQUIRED], true)
                ? $back
                : DocumentTypeSettings::BACK_NONE,
            autoVerify: (bool) ($c['auto_verify'] ?? false),
        );
    }

    /**
     * The types a guest can pick, in display order. The legacy generic
     * route is kept for old clients but never offered.
     *
     * @return list<DocumentTypeSettings>
     */
    public function selectable(): array
    {
        $out = [];

        foreach ([IdentityDocumentType::EgyptianNationalId, IdentityDocumentType::SaudiNationalId, IdentityDocumentType::SaudiIqama, IdentityDocumentType::Passport] as $type) {
            $settings = $this->settings($type);

            if ($settings->enabled) {
                $out[] = $settings;
            }
        }

        return $out;
    }

    /** @return list<DocumentTypeSettings> every type, enabled or not (staff view). */
    public function all(): array
    {
        return array_map(fn (IdentityDocumentType $t) => $this->settings($t), IdentityDocumentType::cases());
    }
}
