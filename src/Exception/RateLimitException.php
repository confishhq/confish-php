<?php

declare(strict_types=1);

namespace Confish\Exception;

/** HTTP 429 — rate limit exceeded. */
final class RateLimitException extends ConfishException
{
    /**
     * @param  array<string, mixed>|string|null  $body
     */
    public function __construct(
        string $message,
        ?int $statusCode = null,
        array|string|null $body = null,
        public readonly ?int $retryAfter = null,
        public readonly ?int $limit = null,
        public readonly ?int $remaining = null,
    ) {
        parent::__construct($message, $statusCode, $body);
    }
}
