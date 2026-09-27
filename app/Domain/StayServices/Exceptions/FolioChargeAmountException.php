<?php

namespace App\Domain\StayServices\Exceptions;

use RuntimeException;

/**
 * Defensive guard: thrown when a computed line total does not fit the
 * DECIMAL(12,2) money column (quantity x unit price out of range). The Form
 * Request caps quantity and price so this is not reachable through the API,
 * but the service layer never trusts that alone.
 */
class FolioChargeAmountException extends RuntimeException
{
    public function __construct(public readonly string $reason = 'amount_out_of_range')
    {
        parent::__construct("The computed charge amount is invalid ({$reason}).");
    }
}
