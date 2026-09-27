<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Active Identity Verification Provider
    |--------------------------------------------------------------------------
    |
    | The provider IdentityVerificationProviderInterface resolves to. Phase 6
    | registers exactly one implementation — "dummy" — and an unknown value
    | fails loudly rather than falling back (see AppServiceProvider), mirroring
    | the Phase 5 payment gateway binding.
    |
    */

    'provider' => env('IDENTITY_PROVIDER', 'dummy'),

    /*
    |--------------------------------------------------------------------------
    | Confidence Thresholds (Phase 0 §10, R57 — GENUINELY UNRESOLVED)
    |--------------------------------------------------------------------------
    |
    | Phase 0 §20 item 2: "Verification confidence threshold numeric values
    | ... actual default numbers are not [confirmed]". No number is invented
    | here. Both values stay null until an approved requirement sets them.
    |
    | Score scale is a provider-normalized integer 0..100 (technical
    | decision — the interface normalizes whatever a real provider returns
    | into this range).
    |
    | - auto_approve:  score >= this  -> AUTO_APPROVED. When null, the domain
    |   CANNOT classify a match and fails safe to PENDING_MANUAL_REVIEW
    |   (never auto-approve, never auto-reject) — Phase 0 §10 "manual review
    |   is always reachable".
    | - manual_review: only distinguishes the MEDIUM band from the LOW band
    |   for audit/reporting. Both bands route to PENDING_MANUAL_REVIEW, so a
    |   null here never changes the workflow outcome.
    |
    */

    'thresholds' => [
        'auto_approve' => env('IDENTITY_VERIFICATION_AUTO_APPROVE_THRESHOLD'),
        'manual_review' => env('IDENTITY_VERIFICATION_MANUAL_REVIEW_THRESHOLD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Limit (Phase 0 §10, R57 — GENUINELY UNRESOLVED)
    |--------------------------------------------------------------------------
    |
    | Phase 0 §20 item 3: "Verification retry-count numeric limit (confirmed
    | configurable; number not specified)". No number is invented.
    |
    | Interpreted as the maximum number of RETRY attempts allowed beyond the
    | initial attempt. When null the domain does not assume a number: a
    | RETRY_ALLOWED session is routed to PENDING_MANUAL_REVIEW (still
    | human-resolvable) rather than guessing a limit.
    |
    */

    'max_retries' => env('IDENTITY_VERIFICATION_MAX_RETRIES'),

    /*
    |--------------------------------------------------------------------------
    | Identity Data Retention (Phase 0 §17, R58 — GENUINELY UNRESOLVED)
    |--------------------------------------------------------------------------
    |
    | Phase 0 §20 item 5 / §17: "a scheduled cleanup job reads a
    | retention-period config value (currently unset/placeholder)". The value
    | stays null; no purge job is scheduled in Phase 6 (deferred — see the
    | Phase 6 report). Domain code must treat null as "not configured".
    |
    */

    // Approved 2026-09-26: the hotel keeps a stay's identity images for 30
    // days after the stay ends, then they are deleted automatically.
    'retention_days' => (int) env('IDENTITY_VERIFICATION_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Per-Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Only the dummy provider exists in this phase. No secrets, no endpoints —
    | the approved Phase 6 workflow has no verification callback/webhook
    | (Phase 0 §16 lists no identity-verification webhook route), so the
    | provider needs no signing secret.
    |
    | - default_directive: the deterministic outcome the dummy provider uses
    |   when a caller supplies no explicit simulation directive. One of:
    |   high_match, medium_match, low_match, error.
    |
    */

    'providers' => [

        'dummy' => [
            'default_directive' => env('IDENTITY_DUMMY_DEFAULT_DIRECTIVE', 'high_match'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | OCR Document Provider (IdentityDocumentProviderInterface)
    |--------------------------------------------------------------------------
    |
    | Reads the structured fields off the uploaded ID document:
    |  - "azure_document_intelligence" — PRODUCTION. Azure AI Document
    |    Intelligence `prebuilt-idDocument` (REST 2024-11-30). Credentials
    |    live only here (server env) — never in the Flutter app.
    |  - "dummy" — deterministic synthetic specimen for automated tests /
    |    local dev without credentials. REFUSED when APP_ENV=production.
    |
    */

    'document_provider' => env('IDENTITY_DOCUMENT_PROVIDER', 'dummy'),

    'document_providers' => [

        'azure_document_intelligence' => [
            // e.g. https://<resource>.cognitiveservices.azure.com
            'endpoint' => env('AZURE_DOCUMENT_INTELLIGENCE_ENDPOINT'),
            'key' => env('AZURE_DOCUMENT_INTELLIGENCE_KEY'),
            'api_version' => env('AZURE_DOCUMENT_INTELLIGENCE_API_VERSION', '2024-11-30'),
            // The passport / legacy route model. Microsoft's documented prebuilt
            // ID model; overridable, never used for the custom routes below.
            'model' => env('AZURE_DI_PASSPORT_MODEL', 'prebuilt-idDocument'),
            'timeout_seconds' => (int) env('AZURE_DOCUMENT_INTELLIGENCE_TIMEOUT', 30),
            'poll_interval_ms' => (int) env('AZURE_DOCUMENT_INTELLIGENCE_POLL_MS', 1000),
        ],

        'dummy' => [
            // specimen_passport | expired_passport | bad_mrz_passport |
            // national_id_no_mrz | driver_license | no_document | provider_timeout
            'scenario' => env('IDENTITY_DUMMY_DOCUMENT_SCENARIO', 'specimen_passport'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Document Types (routes)
    |--------------------------------------------------------------------------
    |
    | One block per IdentityDocumentType. The guest picks the type explicitly;
    | DocumentProviderRouter sends the images to that type's Azure model.
    |
    | - enabled:     offered to guests / accepted on upload
    | - model:       Azure model id. Custom models have NO default: when unset
    |                the check becomes NEEDS_REVIEW (`provider_not_configured`)
    |                and an error is logged — never a dummy result, never a pass.
    | - back:        none | optional | required — back-side image policy
    | - auto_verify: whether a fully matching document may be VERIFIED
    |                automatically. OFF for the custom-model types until the
    |                trained model has been evaluated on an authorized dataset
    |                (see mobile/docs/identity-custom-ocr-models.md) — until
    |                then those documents always go to manual review.
    |
    | Retrain / replace a model = change the model id env var; no code change.
    |
    */

    'document_types' => [

        'passport' => [
            'enabled' => (bool) env('IDENTITY_DOC_PASSPORT_ENABLED', true),
            'model' => env('AZURE_DI_PASSPORT_MODEL', 'prebuilt-idDocument'),
            'back' => 'none',
            'auto_verify' => (bool) env('IDENTITY_DOC_PASSPORT_AUTO_VERIFY', true),
        ],

        'egyptian_national_id' => [
            'enabled' => (bool) env('IDENTITY_DOC_EGYPT_ID_ENABLED', true),
            'model' => env('AZURE_DI_EGYPT_ID_MODEL'),
            // Expiry date and gender are printed on the back.
            'back' => env('IDENTITY_DOC_EGYPT_ID_BACK', 'required'),
            'auto_verify' => (bool) env('IDENTITY_DOC_EGYPT_ID_AUTO_VERIFY', false),
        ],

        'saudi_national_id' => [
            'enabled' => (bool) env('IDENTITY_DOC_SAUDI_ID_ENABLED', true),
            'model' => env('AZURE_DI_SAUDI_ID_MODEL'),
            'back' => env('IDENTITY_DOC_SAUDI_ID_BACK', 'optional'),
            'auto_verify' => (bool) env('IDENTITY_DOC_SAUDI_ID_AUTO_VERIFY', false),
        ],

        'saudi_iqama' => [
            'enabled' => (bool) env('IDENTITY_DOC_SAUDI_IQAMA_ENABLED', true),
            'model' => env('AZURE_DI_SAUDI_IQAMA_MODEL'),
            'back' => env('IDENTITY_DOC_SAUDI_IQAMA_BACK', 'optional'),
            'auto_verify' => (bool) env('IDENTITY_DOC_SAUDI_IQAMA_AUTO_VERIFY', false),
        ],

        // Legacy generic route for older app builds (`national_id`,
        // `residence_permit`, no type): prebuilt model, pre-existing rules.
        'other_id' => [
            'enabled' => true,
            'model' => env('AZURE_DI_PASSPORT_MODEL', 'prebuilt-idDocument'),
            'back' => 'none',
            'auto_verify' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Document Check Rules
    |--------------------------------------------------------------------------
    |
    | - min_field_confidence: provider confidence (0..1) below which a field
    |   not proven by MRZ check digits sends the document to review.
    | - auto_verify_without_mrz: whether a national ID / residence permit
    |   WITHOUT a check-digit-verified MRZ may be auto-verified. Off: such
    |   documents are read and compared but end in needs_review, because
    |   their field extraction is not independently verifiable or tested.
    | - max_uploads_per_attempt: OCR runs per attempt before a failing
    |   document is routed to a human (cost + abuse cap).
    | - processing_stale_seconds: an in-flight check older than this no
    |   longer blocks a re-upload.
    |
    */

    'document_check' => [
        'min_field_confidence' => (float) env('IDENTITY_DOCUMENT_MIN_CONFIDENCE', 0.80),
        'auto_verify_without_mrz' => (bool) env('IDENTITY_DOCUMENT_AUTO_VERIFY_WITHOUT_MRZ', false),
        'max_uploads_per_attempt' => (int) env('IDENTITY_DOCUMENT_MAX_UPLOADS_PER_ATTEMPT', 5),
        'processing_stale_seconds' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Secure Identity Document Storage (Phase 0 §17, R38-R42)
    |--------------------------------------------------------------------------
    |
    | ID documents and live selfies are PII. They are written to a PRIVATE
    | disk only — never the "public" disk, never a public path. The approved
    | Phase 6 endpoint map (§16) has no document-download route, so the files
    | are write-only this phase: stored privately, referenced by the domain,
    | never served back.
    |
    */

    'storage' => [
        'disk' => env('IDENTITY_VERIFICATION_DISK', 'local'),
        'max_file_kb' => (int) env('IDENTITY_VERIFICATION_MAX_FILE_KB', 8192),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limits (Phase 0 §17 — "rate limiting on ... verification-upload")
    |--------------------------------------------------------------------------
    |
    | Per-minute ceiling for the verification-upload endpoints (documents /
    | selfie), registered as the named limiter `identity-verification.submit`
    | in AppServiceProvider and applied by the route `throttle:` middleware.
    | Keyed by authenticated user id (IP fallback).
    |
    */

    'rate_limits' => [
        'submit' => [
            'per_minute' => (int) env('IDENTITY_VERIFICATION_SUBMIT_RATE_LIMIT', 20),
        ],
    ],

];
