<?php

declare(strict_types=1);

namespace Confish\Tests;

use Confish\Exception\ConfishException;
use Confish\Exception\WebhookSignatureException;
use Confish\Exception\WebhookTimestampException;
use Confish\Exception\WebhookVerificationException;
use Confish\Webhook;
use Confish\WebhookPayload;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private function sign(string $secret, int $ts, string $body): string
    {
        return hash_hmac('sha256', "$ts:$body", $secret);
    }

    public function test_returns_parsed_payload_for_valid_signature(): void
    {
        $body = json_encode([
            'event'       => 'environment.updated',
            'timestamp'   => '2026-07-09T12:00:00+00:00',
            'application' => ['name' => 'My App'],
            'environment' => ['name' => 'Production', 'env_id' => 'env_1', 'url' => 'https://confi.sh/c/env_1'],
            'changes'     => ['site_name'],
            'values'      => ['site_name' => 'X'],
        ], JSON_THROW_ON_ERROR);
        $ts = 1_700_000_000;
        $sig = $this->sign('whsec_test', $ts, $body);

        $payload = Webhook::verify(
            body: $body,
            signature: "ts=$ts;sig=$sig",
            secret: 'whsec_test',
            now: $ts,
        );

        self::assertInstanceOf(WebhookPayload::class, $payload);
        self::assertSame('environment.updated', $payload->event);
        self::assertSame('2026-07-09T12:00:00+00:00', $payload->timestamp);
        self::assertSame(['name' => 'My App'], $payload->application);
        self::assertSame('env_1', $payload->environment['env_id']);
        self::assertSame(['site_name'], $payload->changes);
        self::assertSame(['site_name' => 'X'], $payload->values);
    }

    public function test_payload_defaults_for_omitted_optional_fields(): void
    {
        $body = '{"event":"environment.deleted"}';
        $ts = 1_700_000_000;
        $sig = $this->sign('whsec_test', $ts, $body);

        $payload = Webhook::verify(
            body: $body,
            signature: "ts=$ts;sig=$sig",
            secret: 'whsec_test',
            now: $ts,
        );

        self::assertNull($payload->timestamp);
        self::assertSame([], $payload->changes);
        self::assertNull($payload->values);
    }

    public function test_rejects_wrong_secret(): void
    {
        $body = '{}';
        $ts = 1_700_000_000;
        $sig = $this->sign('other', $ts, $body);

        $this->expectException(WebhookSignatureException::class);
        Webhook::verify(
            body: $body,
            signature: "ts=$ts;sig=$sig",
            secret: 'whsec_test',
            now: $ts,
        );
    }

    public function test_rejects_tampered_body(): void
    {
        $secret = 'whsec_test';
        $ts = 1_700_000_000;
        $sig = $this->sign($secret, $ts, '{"a":1}');

        $this->expectException(WebhookSignatureException::class);
        Webhook::verify(
            body: '{"a":2}',
            signature: "ts=$ts;sig=$sig",
            secret: $secret,
            now: $ts,
        );
    }

    public function test_rejects_stale_timestamp_with_timestamp_exception(): void
    {
        $secret = 'whsec_test';
        $ts = 1_700_000_000;
        $sig = $this->sign($secret, $ts, '{}');

        $this->expectException(WebhookTimestampException::class);
        Webhook::verify(
            body: '{}',
            signature: "ts=$ts;sig=$sig",
            secret: $secret,
            toleranceSeconds: 300,
            now: $ts + 600,
        );
    }

    public function test_failure_reasons_share_a_common_base_exception(): void
    {
        $secret = 'whsec_test';
        $ts = 1_700_000_000;
        $sig = $this->sign($secret, $ts, '{}');

        try {
            Webhook::verify(body: '{}', signature: "ts=$ts;sig=$sig", secret: $secret, now: $ts + 600);
            $this->fail('Expected WebhookVerificationException');
        } catch (WebhookVerificationException $e) {
            self::assertInstanceOf(WebhookTimestampException::class, $e);
        }
    }

    public function test_accepts_stale_timestamp_when_tolerance_disabled(): void
    {
        $secret = 'whsec_test';
        $ts = 1_700_000_000;
        $sig = $this->sign($secret, $ts, '{"event":"environment.updated"}');

        $payload = Webhook::verify(
            body: '{"event":"environment.updated"}',
            signature: "ts=$ts;sig=$sig",
            secret: $secret,
            toleranceSeconds: 0,
            now: $ts + 99_999,
        );

        self::assertSame('environment.updated', $payload->event);
    }

    public function test_rejects_malformed_headers(): void
    {
        foreach (['', 'garbage', 'ts=abc;sig=def', 'ts=1;sig='] as $header) {
            try {
                Webhook::verify(body: '{}', signature: $header, secret: 'whsec_test');
                $this->fail("Expected WebhookSignatureException for header: $header");
            } catch (WebhookSignatureException) {
                // expected
            }
        }

        $this->expectException(WebhookSignatureException::class);
        Webhook::verify(body: '{}', signature: null, secret: 'whsec_test');
    }

    public function test_rejects_verified_body_that_is_not_json(): void
    {
        $secret = 'whsec_test';
        $ts = 1_700_000_000;
        $body = 'not json';
        $sig = $this->sign($secret, $ts, $body);

        $this->expectException(ConfishException::class);
        $this->expectExceptionMessage('Failed to parse webhook body as JSON');
        Webhook::verify(
            body: $body,
            signature: "ts=$ts;sig=$sig",
            secret: $secret,
            now: $ts,
        );
    }
}
