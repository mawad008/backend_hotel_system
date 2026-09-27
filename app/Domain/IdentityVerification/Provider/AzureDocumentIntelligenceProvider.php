<?php

namespace App\Domain\IdentityVerification\Provider;

use App\Domain\IdentityVerification\Provider\Azure\DocumentFieldMapper;
use App\Domain\IdentityVerification\Provider\Azure\PrebuiltIdDocumentMapper;
use App\Domain\IdentityVerification\Provider\Contracts\IdentityDocumentProviderInterface;
use App\Domain\IdentityVerification\Provider\Data\DocumentExtractionRequest;
use App\Domain\IdentityVerification\Provider\Data\DocumentExtractionResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Throwable;

/**
 * Production OCR: Azure AI Document Intelligence, REST API v4.0
 * (`2024-11-30` GA). One HTTP adapter for every model:
 *
 *   POST {endpoint}/documentintelligence/documentModels/{model}:analyze
 *        body {"base64Source": …}                           → 202 + Operation-Location
 *   GET  Operation-Location                                  → poll until succeeded/failed
 *   DELETE …/documentModels/{model}/analyzeResults/{id}      → drop Azure's copy now
 *                                                              (instead of its 24 h default)
 *
 * {@see self::extract()} is the prebuilt `prebuilt-idDocument` passport path
 * (unchanged behaviour); {@see self::analyze()} runs any configured model —
 * prebuilt or a trained custom model — and hands the raw analysis to a
 * {@see DocumentFieldMapper}. Front and back images are analysed as two
 * separate operations (each deleted afterwards). Model ids come from config
 * via {@see DocumentProviderRouter}; none is hard-coded here beyond the
 * constructor default of the documented prebuilt model.
 *
 * Security:
 *  - the key is sent only to the configured endpoint host — an
 *    Operation-Location pointing anywhere else is refused (the key never
 *    follows a redirect to a foreign host);
 *  - no request/response body is ever logged or put in an exception message;
 *    every failure is mapped to a fixed code;
 *  - documents travel in memory (base64 in the request body): no temporary
 *    file is written.
 */
final class AzureDocumentIntelligenceProvider implements IdentityDocumentProviderInterface
{
    public const NAME = 'azure_document_intelligence';

    private const MODEL_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._~-]{1,63}$/';

    private const RESULT_PATTERN = '/^[A-Za-z0-9-]{8,64}$/';

    private readonly string $endpoint;

    public function __construct(
        private readonly HttpFactory $http,
        string $endpoint,
        #[\SensitiveParameter] private readonly string $key,
        private readonly string $apiVersion = '2024-11-30',
        private readonly string $model = 'prebuilt-idDocument',
        private readonly int $timeoutSeconds = 30,
        private readonly int $pollIntervalMs = 1000,
    ) {
        $endpoint = rtrim($endpoint, '/');

        if (! str_starts_with($endpoint, 'https://') || parse_url($endpoint, PHP_URL_HOST) === null) {
            throw new InvalidArgumentException('The Document Intelligence endpoint must be an https:// URL.');
        }

        if ($key === '') {
            throw new InvalidArgumentException('The Document Intelligence key is not configured.');
        }

        $this->endpoint = $endpoint;
    }

    public function name(): string
    {
        return self::NAME;
    }

    /** Prebuilt ID model (passports) — the original single-route behaviour. */
    public function extract(DocumentExtractionRequest $request): DocumentExtractionResult
    {
        return $this->analyze($this->model, $request, new PrebuiltIdDocumentMapper);
    }

    /**
     * Analyse the front (and back, when given) with `$model` and map the
     * results. The deadline covers both sides together.
     */
    public function analyze(string $model, DocumentExtractionRequest $request, DocumentFieldMapper $mapper): DocumentExtractionResult
    {
        if (preg_match(self::MODEL_PATTERN, $model) !== 1) {
            return DocumentExtractionResult::failed(DocumentExtractionResult::FAILURE_NOT_CONFIGURED, 'model_id_invalid');
        }

        $deadline = microtime(true) + $this->timeoutSeconds;
        $artifacts = [];

        $front = $this->analyzeOne($model, $request->bytes, $deadline);

        if ($front['artifact'] !== null) {
            $artifacts[] = $front['artifact'];
        }

        if ($front['analyze'] === null) {
            return DocumentExtractionResult::failed($front['failure'], $front['status'], $artifacts);
        }

        $back = null;

        if ($request->backBytes !== null) {
            $backRun = $this->analyzeOne($model, $request->backBytes, $deadline);

            if ($backRun['artifact'] !== null) {
                $artifacts[] = $backRun['artifact'];
            }

            if ($backRun['analyze'] === null) {
                return DocumentExtractionResult::failed($backRun['failure'], 'back_'.$backRun['status'], $artifacts);
            }

            $back = $backRun['analyze'];
        }

        $document = $mapper->map($front['analyze'], $back);

        return $document === null
            ? DocumentExtractionResult::failed(DocumentExtractionResult::FAILURE_NO_DOCUMENT, 'no_document', $artifacts)
            : DocumentExtractionResult::extracted($document, 'succeeded', $artifacts);
    }

    /**
     * Accepts "{model}/{resultId}" (current) or a bare result id (legacy —
     * the prebuilt model).
     */
    public function deleteArtifact(string $artifactReference): bool
    {
        [$model, $resultId] = str_contains($artifactReference, '/')
            ? explode('/', $artifactReference, 2)
            : [$this->model, $artifactReference];

        if (preg_match(self::RESULT_PATTERN, $resultId) !== 1 || preg_match(self::MODEL_PATTERN, $model) !== 1) {
            return true; // not something we could have created — nothing to delete
        }

        try {
            $response = $this->client()->timeout(10)->delete(sprintf(
                '%s/documentintelligence/documentModels/%s/analyzeResults/%s?api-version=%s',
                $this->endpoint,
                rawurlencode($model),
                $resultId,
                rawurlencode($this->apiVersion),
            ));
        } catch (Throwable) {
            return false;
        }

        return $response->status() === 204 || $response->status() === 404 || $response->successful();
    }

    /**
     * One analyze operation (submit + poll).
     *
     * @return array{analyze: array<string, mixed>|null, failure: string, status: string, artifact: string|null}
     */
    private function analyzeOne(string $model, string $bytes, float $deadline): array
    {
        $fail = fn (string $failure, string $status, ?string $artifact = null) => ['analyze' => null, 'failure' => $failure, 'status' => $status, 'artifact' => $artifact];

        try {
            $submit = $this->client()
                ->timeout(max(5, $this->timeoutSeconds))
                ->post($this->analyzeUrl($model), ['base64Source' => base64_encode($bytes)]);
        } catch (ConnectionException) {
            return $fail(DocumentExtractionResult::FAILURE_TIMEOUT, 'submit_connection');
        } catch (Throwable) {
            return $fail(DocumentExtractionResult::FAILURE_PROVIDER_ERROR, 'submit_exception');
        }

        if ($submit->status() !== 202) {
            // 404 on a custom model = the configured model id does not exist.
            return $submit->status() === 404
                ? $fail(DocumentExtractionResult::FAILURE_NOT_CONFIGURED, 'model_not_found')
                : $fail($this->failureFor($submit), 'submit_http_'.$submit->status());
        }

        $operation = (string) $submit->header('Operation-Location');
        $resultId = $this->resultIdFrom($operation);

        if ($resultId === null) {
            return $fail(DocumentExtractionResult::FAILURE_PROVIDER_ERROR, 'bad_operation_location');
        }

        $artifact = $model.'/'.$resultId;
        $wait = $this->retryAfterMs($submit);

        while (true) {
            if (microtime(true) + $wait / 1000 > $deadline) {
                // Still running on Azure: its copy is deleted later by the retention job.
                return $fail(DocumentExtractionResult::FAILURE_TIMEOUT, 'poll_timeout', $artifact);
            }

            Sleep::for($wait)->milliseconds();

            try {
                $poll = $this->client()->timeout(10)->get($operation);
            } catch (Throwable) {
                return $fail(DocumentExtractionResult::FAILURE_TIMEOUT, 'poll_connection', $artifact);
            }

            if (! $poll->successful()) {
                return $fail($this->failureFor($poll), 'poll_http_'.$poll->status(), $artifact);
            }

            $status = (string) $poll->json('status');

            if ($status === 'succeeded') {
                return ['analyze' => (array) $poll->json('analyzeResult'), 'failure' => '', 'status' => 'succeeded', 'artifact' => $artifact];
            }

            if ($status === 'failed' || $status === 'canceled') {
                $code = (string) $poll->json('error.code');

                return $fail(
                    in_array($code, ['InvalidContent', 'InvalidRequest', 'InvalidArgument'], true)
                        ? DocumentExtractionResult::FAILURE_INVALID_INPUT
                        : DocumentExtractionResult::FAILURE_PROVIDER_ERROR,
                    'analysis_'.$status,
                    $artifact,
                );
            }

            $wait = $this->retryAfterMs($poll);
        }
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return $this->http
            ->withHeaders(['Ocp-Apim-Subscription-Key' => $this->key])
            ->acceptJson()
            ->withoutRedirecting();
    }

    private function analyzeUrl(string $model): string
    {
        return sprintf(
            '%s/documentintelligence/documentModels/%s:analyze?api-version=%s&pages=1-2',
            $this->endpoint,
            rawurlencode($model),
            rawurlencode($this->apiVersion),
        );
    }

    /** The Operation-Location must be on our endpoint host (the key never leaves it). */
    private function resultIdFrom(string $operation): ?string
    {
        if ($operation === ''
            || ! str_starts_with($operation, 'https://')
            || strcasecmp((string) parse_url($operation, PHP_URL_HOST), (string) parse_url($this->endpoint, PHP_URL_HOST)) !== 0) {
            return null;
        }

        $path = (string) parse_url($operation, PHP_URL_PATH);

        return preg_match('#/analyzeResults/([A-Za-z0-9-]{8,64})$#', $path, $m) === 1 ? $m[1] : null;
    }

    private function retryAfterMs(Response $response): int
    {
        $retryAfter = (int) $response->header('Retry-After');

        return $retryAfter > 0 ? min($retryAfter * 1000, 3000) : $this->pollIntervalMs;
    }

    private function failureFor(Response $response): string
    {
        return match (true) {
            $response->status() === 429 => DocumentExtractionResult::FAILURE_RATE_LIMITED,
            in_array($response->status(), [401, 403], true) => DocumentExtractionResult::FAILURE_AUTH_ERROR,
            $response->status() === 400 || $response->status() === 415 => DocumentExtractionResult::FAILURE_INVALID_INPUT,
            default => DocumentExtractionResult::FAILURE_PROVIDER_ERROR,
        };
    }
}
