<?php

namespace App\Domain\Loyalty\Repositories;

use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Loyalty\Repositories\Contracts\LoyaltyAccountRepositoryInterface;

class EloquentLoyaltyAccountRepository implements LoyaltyAccountRepositoryInterface
{
    public function find(int $id): ?LoyaltyAccount
    {
        return LoyaltyAccount::query()->find($id);
    }

    public function findByGuest(int $guestId): ?LoyaltyAccount
    {
        return LoyaltyAccount::query()->where('guest_id', $guestId)->first();
    }

    public function findByGuestForUpdate(int $guestId): ?LoyaltyAccount
    {
        return LoyaltyAccount::query()->where('guest_id', $guestId)->lockForUpdate()->first();
    }

    public function create(array $data): LoyaltyAccount
    {
        return LoyaltyAccount::create($data)->refresh();
    }

    public function update(LoyaltyAccount $account, array $data): LoyaltyAccount
    {
        $account->update($data);

        return $account->refresh();
    }
}
