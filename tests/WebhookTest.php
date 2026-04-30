<?php

declare(strict_types=1);

namespace Confish\Tests;

use Confish\Webhook;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private function sign(string $secret, int $ts, string $body): string
    {
        return hash_hmac('sha256', "$ts:$body", $secret);
    }

    public function test_accepts_valid_signature(): void
    {
        $body = '{"event":"environment.updated"}';
        $ts = 1_700_000_000;
        $sig = $this->sign('whsec_test', $ts, $body);

        self::assertTrue(Webhook::verify(
            body: $body,
            signature: "ts=$ts;sig=$sig",
            secret: 'whsec_test',
            now: $ts,
        ));
    }

    public function test_rejects_wrong_secret(): void
    {
        $body = '{}';
        $ts = 1_700_000_000;
        $sig = $this->sign('other', $ts, $body);

        self::assertFalse(Webhook::verify(
            body: $body,
            signature: "ts=$ts;sig=$sig",
            secret: 'whsec_test',
            now: $ts,
        ));
    }

    public function test_rejects_tampered_body(): void
    {
        $secret = 'whsec_test';
        $ts = 1_700_000_000;
        $sig = $this->sign($secret, $ts, '{"a":1}');

        self::assertFalse(Webhook::verify(
            body: '{"a":2}',
            signature: "ts=$ts;sig=$sig",
            secret: $secret,
            now: $ts,
        ));
    }

    public function test_rejects_stale_timestamp(): void
    {
        $secret = 'whsec_test';
        $ts = 1_700_000_000;
        $sig = $this->sign($secret, $ts, '{}');

        self::assertFalse(Webhook::verify(
            body: '{}',
            signature: "ts=$ts;sig=$sig",
            secret: $secret,
            toleranceSeconds: 300,
            now: $ts + 600,
        ));
    }

    public function test_accepts_stale_timestamp_when_tolerance_disabled(): void
    {
        $secret = 'whsec_test';
        $ts = 1_700_000_000;
        $sig = $this->sign($secret, $ts, '{}');

        self::assertTrue(Webhook::verify(
            body: '{}',
            signature: "ts=$ts;sig=$sig",
            secret: $secret,
            toleranceSeconds: 0,
            now: $ts + 99_999,
        ));
    }

    public function test_rejects_malformed_headers(): void
    {
        foreach (['', 'garbage', 'ts=abc;sig=def', 'ts=1;sig='] as $header) {
            self::assertFalse(
                Webhook::verify(body: '{}', signature: $header, secret: 'whsec_test'),
                "header: $header",
            );
        }
        self::assertFalse(Webhook::verify(body: '{}', signature: null, secret: 'whsec_test'));
    }
}
