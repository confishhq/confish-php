<?php

declare(strict_types=1);

namespace Confish;

final class Logger
{
    public function __construct(private readonly Confish $client)
    {
    }

    /** @param  array<string, mixed>|null  $context */
    public function debug(string $message, ?array $context = null): string
    {
        return $this->client->log(LogLevel::Debug, $message, $context);
    }

    /** @param  array<string, mixed>|null  $context */
    public function info(string $message, ?array $context = null): string
    {
        return $this->client->log(LogLevel::Info, $message, $context);
    }

    /** @param  array<string, mixed>|null  $context */
    public function notice(string $message, ?array $context = null): string
    {
        return $this->client->log(LogLevel::Notice, $message, $context);
    }

    /** @param  array<string, mixed>|null  $context */
    public function warning(string $message, ?array $context = null): string
    {
        return $this->client->log(LogLevel::Warning, $message, $context);
    }

    /** @param  array<string, mixed>|null  $context */
    public function error(string $message, ?array $context = null): string
    {
        return $this->client->log(LogLevel::Error, $message, $context);
    }

    /** @param  array<string, mixed>|null  $context */
    public function critical(string $message, ?array $context = null): string
    {
        return $this->client->log(LogLevel::Critical, $message, $context);
    }

    /** @param  array<string, mixed>|null  $context */
    public function alert(string $message, ?array $context = null): string
    {
        return $this->client->log(LogLevel::Alert, $message, $context);
    }
}
