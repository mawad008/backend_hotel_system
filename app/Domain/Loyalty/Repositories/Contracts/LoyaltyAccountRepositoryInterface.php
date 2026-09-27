<?php

namespace App\Domain\Loyalty\Repositories\Contracts;

use App\Domain\Loyalty\Models\LoyaltyAccount;

interface LoyaltyAccountRepositoryInterface
{
    public function find(int $id): ?LoyaltyAccount;

    public function findByGuest(int $guestId): ?LoyaltyAccount;

    /**
     * `findByGuest()` under a `SELECT ... FOR UPDATE` row lock. Call only
     * from within an active DB::transaction().
     */
    public function findByGuestForUpdate(int $guestId): ?LoyaltyAccount;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): LoyaltyAccount;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LoyaltyAccount $account, array $data): LoyaltyAccount;
}
