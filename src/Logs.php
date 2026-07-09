<?php

declare(strict_types=1);

namespace Confish;

final class Logs
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $envId,
    ) {
    }

    /**
     * Writes a log entry at an explicit level and returns the log entry ID.
     *
     * @param  array<string, mixed>|null  $context
     */
    public function write(LogLevel $level, string $message, ?array $context = null): string
    {
        $body = ['level' => $level->value, 'message' => $message];
        if ($context !== null) {
            $body['context'] = $context;
        }
        $response = $this->http->request('POST', "/c/{$this->envId}/log", $body) ?? [];

        return (string) ($response['id'] ?? '');
    }

    /** @param  array<string, mixed>|null  $context */
    public function debug(string $message, ?array $context = null): string
    {
        return $this->write(LogLevel::Debug, $message, $context);
    }

    /** @param  array<string, mixed>|null  $context */
    public function info(string $message, ?array $context = null): string
    {
        return $this->write(LogLevel::Info, $message, $context);
    }

    /** @param  array<string, mixed>|null  $context */
    public function notice(string $message, ?array $context = null): string
    {
        return $this->write(LogLevel::Notice, $message, $context);
    }

    /** @param  array<string, mixed>|null  $context */
    public function warning(string $message, ?array $context = null): string
    {
        return $this->write(LogLevel::Warning, $message, $context);
    }

    /** @param  array<string, mixed>|null  $context */
    public function error(string $message, ?array $context = null): string
    {
        return $this->write(LogLevel::Error, $message, $context);
    }

    /** @param  array<string, mixed>|null  $context */
    public function critical(string $message, ?array $context = null): string
    {
        return $this->write(LogLevel::Critical, $message, $context);
    }

    /** @param  array<string, mixed>|null  $context */
    public function alert(string $message, ?array $context = null): string
    {
        return $this->write(LogLevel::Alert, $message, $context);
    }

    /** @param  array<string, mixed>|null  $context */
    public function emergency(string $message, ?array $context = null): string
    {
        return $this->write(LogLevel::Emergency, $message, $context);
    }
}
