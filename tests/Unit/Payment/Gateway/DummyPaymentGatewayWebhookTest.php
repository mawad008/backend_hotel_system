<?php

namespace Tests\Unit\Payment\Gateway;

use App\Domain\Payment\Gateway\DummyPaymentGateway;
use App\Domain\Payment\Gateway\Exceptions\WebhookPayloadException;
use App\Domain\Payment\Gateway\GatewayResultStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 5B test matrix G, H — webhook parsing + signature verification
 * (Phase 5B instructions §13, §14, §20G, §20H).
 */
class DummyPaymentGatewayWebhookTest extends TestCase
{
    private const SECRET = 'phase-5b-test-secret';

    private function gateway(string $secret = self::SECRET): DummyPaymentGateway
    {
        return new DummyPaymentGateway($secret);
    }

    private function validBody(): string
    {
        return json_encode([
            'event_id' => 'dummy_evt_1',
            'type' => 'Hold.Succeeded',
            'provider_reference' => 'dummy_hold_abc123',
            'status' => 'succeeded',
            'amount' => '150.00',
            'currency' => 'EUR',
        ]);
    }

    // ---- G. Webhook parsing --------------------------------------------

    public function test_a_valid_dummy_payload_is_normalized(): void
    {
        $event = $this->gateway()->parseWebhook($this->validBody());

        $this->assertSame('dummy_evt_1', $event->providerEventId);
        $this->assertSame('hold.succeeded', $event->eventType);
        $this->assertSame('dummy_hold_abc123', $event->providerReference);
        $this->assertSame(GatewayResultStatus::Succeeded, $event->status);
        $this->assertSame(hash('sha256', $this->validBody()), $event->payloadHash);
        $this->assertArrayNotHasKey('secret', $event->normalizedPayload);
    }

    public function test_parsing_is_deterministic(): void
    {
        $this->assertEquals(
            $this->gateway()->parseWebhook($this->validBody())->toArray(),
            $this->gateway()->parseWebhook($this->validBody())->toArray(),
        );
    }

    public function test_normalized_payload_is_an_allow_list_only(): void
    {
        $body = json_encode([
            'type' => 'hold.succeeded',
            'provider_reference' => 'dummy_hold_x',
            'internal_note' => 'drop me',
            'callback_url' => 'https://drop.me',
        ]);

        $event = $this->gateway()->parseWebhook($body);

        $this->assertSame(['type', 'provider_reference'], array_keys($event->normalizedPayload));
    }

    public function test_unknown_status_string_normalizes_to_null_without_error(): void
    {
        $body = json_encode(['type' => 'hold.weird', 'status' => 'flibble']);

        $this->assertNull($this->gateway()->parseWebhook($body)->status);
    }

    public function test_missing_optional_fields_are_null(): void
    {
        $event = $this->gateway()->parseWebhook(json_encode(['type' => 'hold.pending']));

        $this->assertNull($event->providerEventId);
        $this->assertNull($event->providerReference);
        $this->assertNull($event->status);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'not json' => ['not-json-at-all'],
            'json array not object' => ['[1,2,3]'],
            'json scalar' => ['42'],
            'json null' => ['null'],
            'empty string' => [''],
            'object without type' => ['{"provider_reference":"dummy_hold_x"}'],
            'object with empty type' => ['{"type":""}'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_an_invalid_payload_raises_the_dedicated_exception(string $body): void
    {
        $this->expectException(WebhookPayloadException::class);

        $this->gateway()->parseWebhook($body);
    }

    public function test_a_payload_carrying_card_data_is_rejected(): void
    {
        $body = json_encode([
            'type' => 'hold.succeeded',
            'data' => ['card_number' => '4111111111111111', 'cvv' => '123'],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $this->gateway()->parseWebhook($body);
    }

    public function test_parsing_writes_nothing_to_the_database(): void
    {
        $this->gateway()->parseWebhook($this->validBody());

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertDatabaseCount('payment_webhook_events', 0);
    }

    public function test_the_exception_message_never_contains_the_raw_body(): void
    {
        try {
            $this->gateway()->parseWebhook('{"sensitive":"do-not-echo-me"}');
            $this->fail('Expected a WebhookPayloadException.');
        } catch (WebhookPayloadException $e) {
            $this->assertStringNotContainsString('do-not-echo-me', $e->getMessage());
        }
    }

    // ---- H. Signature verification ------------------------------------

    public function test_a_valid_signature_is_accepted(): void
    {
        $body = $this->validBody();
        $signature = hash_hmac('sha256', $body, self::SECRET);

        $this->assertTrue($this->gateway()->verifySignature($body, $signature));
    }

    public function test_an_invalid_signature_is_rejected(): void
    {
        $this->assertFalse($this->gateway()->verifySignature($this->validBody(), 'deadbeef'));
    }

    public function test_a_signature_made_with_the_wrong_secret_is_rejected(): void
    {
        $body = $this->validBody();
        $signature = hash_hmac('sha256', $body, 'the-wrong-secret');

        $this->assertFalse($this->gateway()->verifySignature($body, $signature));
    }

    public function test_a_signature_for_a_tampered_body_is_rejected(): void
    {
        $signature = hash_hmac('sha256', $this->validBody(), self::SECRET);
        $tampered = str_replace('150.00', '1.00', $this->validBody());

        $this->assertFalse($this->gateway()->verifySignature($tampered, $signature));
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function emptySignatures(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
        ];
    }

    #[DataProvider('emptySignatures')]
    public function test_a_missing_signature_is_rejected(?string $signature): void
    {
        $this->assertFalse($this->gateway()->verifySignature($this->validBody(), $signature));
    }

    public function test_verification_fails_closed_when_no_secret_is_configured(): void
    {
        $body = $this->validBody();
        // A signature computed against an empty secret must still be rejected.
        $signature = hash_hmac('sha256', $body, '');

        $this->assertFalse($this->gateway('')->verifySignature($body, $signature));
    }

    public function test_verification_uses_a_timing_safe_comparison(): void
    {
        $source = file_get_contents((new \ReflectionClass(DummyPaymentGateway::class))->getFileName());

        $this->assertStringContainsString('hash_equals(', $source);
        $this->assertMatchesRegularExpression('/verifySignature\([^)]*\)\s*:\s*bool\s*\{.*hash_equals\(/s', $source);
        $this->assertStringNotContainsString('=== $signature', $source);
    }

    public function test_verify_signature_never_throws(): void
    {
        $this->assertFalse($this->gateway()->verifySignature('', null));
        $this->assertFalse($this->gateway()->verifySignature('{}', 'x'));
        $this->addToAssertionCount(1);
    }

    public function test_the_sign_helper_round_trips_with_verification(): void
    {
        $gateway = $this->gateway();
        $body = $this->validBody();

        $this->assertTrue($gateway->verifySignature($body, $gateway->sign($body)));
    }
}
