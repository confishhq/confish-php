<?php

declare(strict_types=1);

namespace Confish;

use Confish\Exception\ConfishException;
use Confish\Exception\WebhookSignatureException;
use Confish\Exception\WebhookTimestampException;

/**
 * Webhook signature verification.
 *
 * Always pass the raw, unparsed request body — re-serializing parsed JSON alters
 * byte order and breaks signature comparison.
 */
final class Webhook
{
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Verify a confish webhook signature and parse its payload.
     *
     * Verification and parsing are one operation: the returned payload is
     * guaranteed to come from the exact bytes that were verified. Uses
     * constant-time comparison. Timestamps further than $toleranceSeconds
     * from the current time are rejected (pass 0 to disable that check).
     *
     * @throws WebhookSignatureException when the signature is missing, malformed, or does not match
     * @throws WebhookTimestampException when the signature matches but the timestamp is outside tolerance
     * @throws ConfishException when the verified body is not valid JSON
     */
    public static function verify(
        string $body,
        ?string $signature,
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): WebhookPayload {
        if ($signature === null || $signature === '' || $secret === '') {
            throw new WebhookSignatureException('Missing webhook signature or secret');
        }

        if (preg_match('/^ts=(\d+);sig=([a-fA-F0-9]+)$/', trim($signature), $matches) !== 1) {
            throw new WebhookSignatureException('Malformed webhook signature header');
        }

        $ts = (int) $matches[1];
        $providedSig = $matches[2];

        $expected = hash_hmac('sha256', "{$ts}:{$body}", $secret);
        if (! hash_equals($expected, $providedSig)) {
            throw new WebhookSignatureException('Webhook signature does not match');
        }

        if ($toleranceSeconds > 0) {
            $current = $now ?? time();
            if (abs($current - $ts) > $toleranceSeconds) {
                throw new WebhookTimestampException("Webhook timestamp is outside the {$toleranceSeconds}s tolerance");
            }
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfishException('Failed to parse webhook body as JSON', null, $body, $e);
        }
        if (! is_array($decoded)) {
            throw new ConfishException('Failed to parse webhook body as JSON', null, $body);
        }

        /** @var array<string, mixed> $decoded */
        return WebhookPayload::fromArray($decoded);
    }
}
