<?php

declare(strict_types=1);

namespace Confish\Exception;

class ConfishException extends \RuntimeException
{
    /**
     * @param  array<string, mixed>|string|null  $body
     */
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly array|string|null $body = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
