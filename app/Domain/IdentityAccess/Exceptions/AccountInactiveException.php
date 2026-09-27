<?php

namespace App\Domain\IdentityAccess\Exceptions;

use RuntimeException;

class AccountInactiveException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This account has been deactivated.');
    }
}
