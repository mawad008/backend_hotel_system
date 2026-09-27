<?php

namespace Tests\Unit\Payment\Workflow\Fakes;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityAccess\Models\User;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * An AuditLogger that records normally until it sees an action containing a
 * configured needle, then throws — used to prove a Step C transaction
 * rolls back completely when a downstream write fails.
 */
final class ConditionalThrowingAuditLogger extends AuditLogger
{
    /** @var list<string> */
    public array $recorded = [];

    public function __construct(private readonly string $throwOnActionContaining) {}

    public function record(
        ?User $actor,
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?int $hotelId = null,
    ): AuditLog {
        if (str_contains($action, $this->throwOnActionContaining)) {
            throw new RuntimeException("audit failure for {$action}");
        }

        $this->recorded[] = $action;

        return AuditLog::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'hotel_id' => $hotelId,
            'before' => $before,
            'after' => $after,
            'ip_address' => null,
            'user_agent' => null,
        ]);
    }
}
