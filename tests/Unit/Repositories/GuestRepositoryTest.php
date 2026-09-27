<?php

namespace Tests\Unit\Repositories;

use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Repositories\EloquentGuestRepository;
use Tests\TestCase;

class GuestRepositoryTest extends TestCase
{
    private EloquentGuestRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EloquentGuestRepository;
    }

    public function test_find_returns_the_guest_by_id(): void
    {
        $guest = Guest::factory()->create();

        $found = $this->repository->find($guest->id);

        $this->assertNotNull($found);
        $this->assertTrue($found->is($guest));
    }

    public function test_find_returns_null_for_a_missing_guest(): void
    {
        $this->assertNull($this->repository->find(999999));
    }
}
