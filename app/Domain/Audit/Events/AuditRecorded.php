<?php

namespace App\Domain\Audit\Events;

use App\Domain\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after every audit entry is written. The audit trail already records
 * each operational action with its hotel, so other domains (the staff
 * inbox) can react to it without the acting domain depending on them.
 *
 * Dispatched synchronously inside the acting domain's transaction: a
 * listener's writes commit or roll back together with the action.
 * Listeners must therefore never call an external provider.
 */
class AuditRecorded
{
    use Dispatchable;

    public function __construct(
        public readonly AuditLog $log,
        public readonly ?Model $subject,
    ) {}
}
