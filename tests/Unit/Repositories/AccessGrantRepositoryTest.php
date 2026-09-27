<?php

namespace Tests\Unit\Repositories;

use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\DigitalAccess\Repositories\EloquentAccessGrantRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccessGrantRepositoryTest extends TestCase
{
    private function repo(): EloquentAccessGrantRepository
    {
        return new EloquentAccessGrantRepository;
    }

    public function test_find_by_reservation_and_idempotency_key(): void
    {
        $grant = AccessGrant::factory()->create(['idempotency_key' => 'k-abc']);

        $this->assertSame($grant->id, $this->repo()->findByReservation($grant->reservation_id)?->id);
        $this->assertSame($grant->id, $this->repo()->findByIdempotencyKey('k-abc')?->id);
        $this->assertNull($this->repo()->findByReservation(999999));
        $this->assertNull($this->repo()->findByIdempotencyKey('missing'));
    }

    public function test_lock_helpers_work_inside_a_transaction(): void
    {
        $grant = AccessGrant::factory()->create();

        DB::transaction(function () use ($grant) {
            $this->assertSame($grant->id, $this->repo()->findForUpdate($grant->id)?->id);
            $this->assertSame($grant->id, $this->repo()->findByReservationForUpdate($grant->reservation_id)?->id);
        });
    }

    public function test_update_persists_and_returns_a_fresh_model(): void
    {
        $grant = AccessGrant::factory()->create();

        $updated = $this->repo()->update($grant, ['status' => AccessGrant::STATUS_ISSUE_REQUESTED]);

        $this->assertSame(AccessGrant::STATUS_ISSUE_REQUESTED, $updated->status);
        $this->assertSame(AccessGrant::STATUS_ISSUE_REQUESTED, $grant->fresh()->status);
    }

    public function test_reservation_id_is_unique(): void
    {
        $grant = AccessGrant::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        AccessGrant::factory()->create(['reservation_id' => $grant->reservation_id]);
    }
}
