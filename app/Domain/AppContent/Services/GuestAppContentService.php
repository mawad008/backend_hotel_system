<?php

namespace App\Domain\AppContent\Services;

use App\Domain\AppContent\Models\GuestAppContent;
use App\Domain\AppContent\Repositories\Contracts\GuestAppContentRepositoryInterface;
use App\Domain\AppContent\Support\GuestAppMediaStore;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityAccess\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reads and edits the Guest App's branding + entry content. Every write is
 * audited. Image replacement deletes the previous file only after the new
 * row is committed, so a failed write never leaves the app without an image.
 */
class GuestAppContentService
{
    public function __construct(
        private readonly GuestAppContentRepositoryInterface $contents,
        private readonly GuestAppMediaStore $store,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function current(): GuestAppContent
    {
        return $this->contents->current();
    }

    /**
     * @param  array<string, mixed>  $data  only GuestAppContent::TEXT_FIELDS
     */
    public function updateText(array $data, ?User $actor): GuestAppContent
    {
        return DB::transaction(function () use ($data, $actor) {
            $content = $this->contents->current();
            $before = $content->only(GuestAppContent::TEXT_FIELDS);

            $clean = [];
            foreach (GuestAppContent::TEXT_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $clean[$field] = $this->normalizeMap($data[$field]);
                }
            }

            if (array_key_exists('faq', $data)) {
                $before['faq'] = $content->faq;
                $clean['faq'] = $this->normalizeFaq($data['faq']);
            }

            $content = $this->contents->update($content, $clean);

            $this->auditLogger->record(
                $actor,
                'guest-app-content.updated',
                $content,
                before: $before,
                after: $content->only([...GuestAppContent::TEXT_FIELDS, 'faq']),
            );

            return $content;
        });
    }

    public function uploadImage(string $slot, UploadedFile $file, ?User $actor): GuestAppContent
    {
        $this->assertSlot($slot);

        $path = $this->store->store($slot, $file);
        $disk = $this->store->disk();

        try {
            [$content, $oldPath, $oldDisk] = DB::transaction(function () use ($slot, $path, $disk, $actor) {
                $content = $this->contents->current();
                $oldPath = $content->getAttribute("{$slot}_path");
                $oldDisk = $content->getAttribute("{$slot}_disk");

                $content = $this->contents->update($content, [
                    "{$slot}_path" => $path,
                    "{$slot}_disk" => $disk,
                ]);

                $this->auditLogger->record(
                    $actor,
                    'guest-app-content.image-uploaded',
                    $content,
                    before: ['slot' => $slot, 'path' => $oldPath],
                    after: ['slot' => $slot, 'path' => $path],
                );

                return [$content, $oldPath, $oldDisk];
            });
        } catch (\Throwable $e) {
            $this->store->delete($path, $disk);
            throw $e;
        }

        $this->store->delete($oldPath, $oldDisk);

        return $content;
    }

    public function removeImage(string $slot, ?User $actor): GuestAppContent
    {
        $this->assertSlot($slot);

        [$content, $oldPath, $oldDisk] = DB::transaction(function () use ($slot, $actor) {
            $content = $this->contents->current();
            $oldPath = $content->getAttribute("{$slot}_path");
            $oldDisk = $content->getAttribute("{$slot}_disk");

            $content = $this->contents->update($content, [
                "{$slot}_path" => null,
                "{$slot}_disk" => null,
            ]);

            $this->auditLogger->record(
                $actor,
                'guest-app-content.image-removed',
                $content,
                before: ['slot' => $slot, 'path' => $oldPath],
                after: ['slot' => $slot, 'path' => null],
            );

            return [$content, $oldPath, $oldDisk];
        });

        $this->store->delete($oldPath, $oldDisk);

        return $content;
    }

    /**
     * Trim values and drop empty locales; an all-empty map becomes null
     * ("use the app default") rather than a map of blanks.
     *
     * @return array<string, string>|null
     */
    /**
     * Keep only complete entries (a question and an answer in at least one
     * locale), in the submitted order.
     *
     * @return list<array{question_i18n: array<string, string>, answer_i18n: array<string, string>}>|null
     */
    private function normalizeFaq(mixed $items): ?array
    {
        if (! is_array($items)) {
            return null;
        }

        $clean = [];
        foreach ($items as $item) {
            $question = $this->normalizeMap(is_array($item) ? ($item['question_i18n'] ?? null) : null);
            $answer = $this->normalizeMap(is_array($item) ? ($item['answer_i18n'] ?? null) : null);
            if ($question !== null && $answer !== null) {
                $clean[] = ['question_i18n' => $question, 'answer_i18n' => $answer];
            }
        }

        return $clean === [] ? null : $clean;
    }

    private function normalizeMap(mixed $map): ?array
    {
        if (! is_array($map)) {
            return null;
        }

        $clean = [];
        foreach ($map as $locale => $value) {
            if (is_string($value) && trim($value) !== '') {
                $clean[(string) $locale] = trim($value);
            }
        }

        return $clean === [] ? null : $clean;
    }

    private function assertSlot(string $slot): void
    {
        if (! in_array($slot, GuestAppContent::IMAGE_SLOTS, true)) {
            throw new InvalidArgumentException("Unknown guest app image slot [{$slot}].");
        }
    }
}
