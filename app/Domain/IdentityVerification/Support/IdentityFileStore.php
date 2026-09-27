<?php

namespace App\Domain\IdentityVerification\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 6 — the single place identity documents and selfies are written to,
 * read from and deleted from storage (Phase 0 §17, R38-R42).
 *
 * Hard rules enforced here:
 *  - the target disk comes from config('verification.storage.disk') and must
 *    NOT be the framework "public" disk;
 *  - files are ENCRYPTED AT REST with the application key (AES-256-CBC +
 *    HMAC via Laravel's encrypter) — the disk only ever holds ciphertext
 *    (`*.enc`); files written before encryption was introduced are still
 *    readable and are removed by the normal retention purge;
 *  - stored names are server-generated: session id / attempt id / kind plus
 *    a random suffix — never a client-supplied name or extension;
 *  - every path read or deleted must match the store's own layout, so a
 *    tampered path can never traverse outside `identity-verification/`;
 *  - only the relative storage path is returned — never a URL.
 *
 * No route serves these files back; OCR reads them in memory only.
 */
final class IdentityFileStore
{
    public const KIND_DOCUMENT = 'document';

    public const KIND_DOCUMENT_BACK = 'document_back';

    public const KIND_SELFIE = 'selfie';

    private const ROOT = 'identity-verification';

    /** `identity-verification/{sessionId}/{one safe file name}` — no further segments. */
    private const PATH_PATTERN = '#^identity-verification/\d+/[A-Za-z0-9][A-Za-z0-9_-]*\.[a-z0-9]{2,4}$#';

    public function disk(): string
    {
        $disk = (string) config('verification.storage.disk', 'local');

        if ($disk === 'public') {
            throw new RuntimeException('Identity verification files must never be stored on the public disk.');
        }

        return $disk;
    }

    /**
     * Encrypt and persist one uploaded file; returns its private relative path.
     */
    public function store(int $sessionId, int $attemptId, string $kind, UploadedFile $file): string
    {
        if (! in_array($kind, [self::KIND_DOCUMENT, self::KIND_DOCUMENT_BACK, self::KIND_SELFIE], true)) {
            throw new RuntimeException('Unknown identity file kind.');
        }

        $bytes = (string) file_get_contents($file->getRealPath());
        $path = sprintf('%s/%d/%d-%s-%s.enc', self::ROOT, $sessionId, $attemptId, $kind, Str::random(20));

        if (! Storage::disk($this->disk())->put($path, Crypt::encryptString($bytes), ['visibility' => 'private'])) {
            throw new RuntimeException('Failed to store the identity verification file.');
        }

        return $path;
    }

    /** Decrypted bytes of a stored file (in memory only). */
    public function read(string $path): string
    {
        $this->assertOwnPath($path);

        $raw = Storage::disk($this->disk())->get($path);

        if ($raw === null) {
            throw new RuntimeException('The identity verification file is missing.');
        }

        return str_ends_with($path, '.enc') ? Crypt::decryptString($raw) : $raw;
    }

    public function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $this->assertOwnPath($path);
        Storage::disk($this->disk())->delete($path);
    }

    /**
     * Keyed fingerprint of (document bytes + claim) — detects an identical
     * re-upload without storing anything reversible.
     */
    public function fingerprint(UploadedFile $file, string $claimCanonical, ?UploadedFile $back = null, string $documentType = ''): string
    {
        return hash_hmac(
            'sha256',
            implode("\x1F", [
                hash_file('sha256', $file->getRealPath()),
                $back !== null ? hash_file('sha256', $back->getRealPath()) : '',
                $documentType,
                $claimCanonical,
            ]),
            (string) config('app.key'),
        );
    }

    /** image/jpeg | image/png | application/pdf from magic bytes, else null. */
    public function detectMime(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($bytes, '%PDF-') => 'application/pdf',
            default => null,
        };
    }

    private function assertOwnPath(string $path): void
    {
        if (preg_match(self::PATH_PATTERN, $path) !== 1 || str_contains($path, '..')) {
            throw new RuntimeException('Refusing to access a path outside the identity store.');
        }
    }
}
