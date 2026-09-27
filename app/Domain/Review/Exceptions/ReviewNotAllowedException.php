<?php

namespace App\Domain\Review\Exceptions;

use RuntimeException;

/**
 * Thrown when a review cannot be submitted. `$reason` is a short, fixed
 * machine code — never a secret or an internal detail (same convention as
 * LoyaltyNotAllowedException).
 */
class ReviewNotAllowedException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("This review action is not allowed ({$reason}).");
    }

    public static function reservationNotCompleted(string $status): self
    {
        return new self("reservation_not_completed:{$status}");
    }

    /** A category that already has guest ratings cannot be hard deleted. */
    public static function categoryInUse(): self
    {
        return new self('category_in_use');
    }

    /** A rated category is not an active category of the review's hotel. */
    public static function invalidCategory(int $categoryId): self
    {
        return new self("invalid_category:{$categoryId}");
    }

    /** A reorder must list exactly the hotel's categories, each once. */
    public static function reorderMismatch(): self
    {
        return new self('reorder_mismatch');
    }
}
