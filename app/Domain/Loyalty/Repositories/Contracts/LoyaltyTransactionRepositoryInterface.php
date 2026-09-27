<?php

namespace App\Domain\Loyalty\Repositories\Contracts;

use App\Domain\Loyalty\Models\LoyaltyTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface LoyaltyTransactionRepositoryInterface
{
    /**
     * The account's ledger, newest first.
     *
     * @return LengthAwarePaginator<LoyaltyTransaction>
     */
    public function paginateForAccount(int $accountId, int $perPage = 20): LengthAwarePaginator;

    /**
     * The one ledger entry of a given type tied to a source, or null. Used
     * alongside the `(loyalty_account_id, type, source_type, source_id)`
     * UNIQUE to keep earn/redeem idempotent.
     */
    public function findByAccountTypeAndSource(int $accountId, string $type, string $sourceType, int $sourceId): ?LoyaltyTransaction;

    /**
     * The decimal-string SUM of `points` for an account — the authoritative
     * balance, used to (re)compute the cache. Returns "0" when empty.
     */
    public function sumPointsForAccount(int $accountId): int;

    /**
     * Sum of `points` by transaction type (earn/redeem/…) for $hotelId,
     * created within [$from, $to] — the loyalty report's data source.
     * Loyalty transactions carry no hotel_id of their own (a guest's
     * ledger is group-wide); scope is resolved through
     * `source_type = 'reservation'` joined to that reservation's own
     * hotel_id — the exact same source reference LoyaltyService itself
     * writes on every earn/redeem.
     *
     * @return array<string, int>
     */
    public function sumsByTypeForHotel(int $hotelId, string $from, string $to): array;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): LoyaltyTransaction;
}
