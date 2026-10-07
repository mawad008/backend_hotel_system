<?php

namespace App\Domain\Support\Exceptions;

use App\Support\ErrorText;
use RuntimeException;

class InvalidProblemReportStatusTransitionException extends RuntimeException
{
    public static function from(string $current, string $target): self
    {
        return new self(ErrorText::message('problem_report_status_transition', ['from' => ErrorText::status($current), 'to' => ErrorText::status($target)]));
    }
}
