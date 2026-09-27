<?php

namespace App\Domain\Support\Exceptions;

use RuntimeException;

class InvalidProblemReportStatusTransitionException extends RuntimeException
{
    public static function from(string $current, string $target): self
    {
        return new self("Cannot transition a problem report from [{$current}] to [{$target}].");
    }
}
