<?php

declare(strict_types=1);

namespace Confish;

final class Logs
{
    /**
     * Maximum number of entries the batch endpoint accepts per request.
     */
    public const MAX_BATCH_SIZE = 100;

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

    /**
     * Writes up to MAX_BATCH_SIZE log entries in one request and returns the
     * created log entry IDs, in entry order.
     *
     * Each entry has a `level` (a LogLevel or its string value), a `message`,
     * an optional `context` object, and an optional ISO 8601 `timestamp` for
     * records captured earlier than they are sent.
     *
     * @param  list<array{level: LogLevel|string, message: string, context?: array<string, mixed>, timestamp?: string}>  $entries
     * @return list<string>
     */
    public function writeBatch(array $entries): array
    {
        if (count($entries) > self::MAX_BATCH_SIZE) {
            throw new \InvalidArgumentException(
                sprintf('writeBatch accepts at most %d entries per request, got %d', self::MAX_BATCH_SIZE, count($entries)),
            );
        }
        if ($entries === []) {
            return [];
        }

        $payload = array_map(static function (array $entry): array {
            if ($entry['level'] instanceof LogLevel) {
                $entry['level'] = $entry['level']->value;
            }

            return $entry;
        }, $entries);

        $response = $this->http->request('POST', "/c/{$this->envId}/logs", ['entries' => $payload]) ?? [];

        /** @var list<string> $ids */
        $ids = isset($response['ids']) && is_array($response['ids']) ? array_values($response['ids']) : [];

        return $ids;
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
