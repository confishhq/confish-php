<?php

declare(strict_types=1);

namespace Confish;

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
     * Verify a confish webhook signature.
     *
     * Returns true only if the signature matches AND the timestamp is within
     * $toleranceSeconds of the current time (pass 0 to disable timestamp checking).
     * Uses constant-time comparison.
     */
    public static function verify(
        string $body,
        ?string $signature,
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): bool {
        if ($signature === null || $signature === '' || $secret === '') {
            return false;
        }

        if (preg_match('/^ts=(\d+);sig=([a-fA-F0-9]+)$/', trim($signature), $matches) !== 1) {
            return false;
        }

        $ts = (int) $matches[1];
        $providedSig = $matches[2];

        if ($toleranceSeconds > 0) {
            $current = $now ?? time();
            if (abs($current - $ts) > $toleranceSeconds) {
                return false;
            }
        }

        $expected = hash_hmac('sha256', "{$ts}:{$body}", $secret);

        return hash_equals($expected, $providedSig);
    }
}
