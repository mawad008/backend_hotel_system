<?php

namespace App\Domain\Payment\Exceptions;

use RuntimeException;

/**
 * The hotel has no deposit percentage configured, so no deposit amount can
 * be derived and no hold can be taken (0% is a rule — "no deposit" — this is
 * the absence of one).
 */
class DepositRuleUndefinedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The hotel has no deposit rule configured.');
    }
}
