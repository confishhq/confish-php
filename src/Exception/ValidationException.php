<?php

declare(strict_types=1);

namespace Confish\Exception;

/** HTTP 422 — request body failed validation. */
final class ValidationException extends ConfishException
{
    /**
     * @param  array<string, mixed>|string|null  $body
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        string $message,
        ?int $statusCode = null,
        array|string|null $body = null,
        public readonly array $errors = [],
    ) {
        parent::__construct($message, $statusCode, $body);
    }
}
