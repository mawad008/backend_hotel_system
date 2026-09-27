<?php

namespace App\Domain\Payment\Gateway\Exceptions;

use RuntimeException;

/**
 * Thrown by PaymentGatewayInterface::parseWebhook when an inbound payload
 * cannot be normalized (not JSON, not an object, or missing a required
 * field).
 *
 * The message is a fixed, safe string with a short machine-readable
 * reason. It never contains the raw body, a signature, or a secret
 * (Phase 5B §14/§17).
 */
class WebhookPayloadException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("The payment webhook payload could not be parsed ({$reason}).");
    }

    public static function malformedJson(): self
    {
        return new self('malformed_json');
    }

    public static function notAnObject(): self
    {
        return new self('not_an_object');
    }

    public static function missingField(string $field): self
    {
        return new self("missing_{$field}");
    }
}
