<?php

declare(strict_types=1);

namespace Confish;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;

/**
 * PSR-3 logger that sends every record to confish immediately — one request
 * per call — through the SDK's logs handle.
 *
 * This is the path for code that types against Psr\Log\LoggerInterface
 * without Monolog. If you run Monolog, or you log at a volume where a
 * request per record is wasteful, use MonologHandler instead: it buffers
 * and ships in batches.
 *
 * Transport failures never throw into the logging path: a failed record is
 * dropped, counted (droppedCount()), and reported to the optional $onError
 * callback. Failures are never logged back through the logger itself.
 *
 * Message placeholders are not interpolated — context is passed through
 * structured, which is what the confish dashboard renders. Per PSR-3, an
 * unknown level throws Psr\Log\InvalidArgumentException.
 */
final class PsrLogger extends AbstractLogger
{
    private int $droppedCount = 0;

    private readonly Logs $logs;

    /**
     * @param  (\Closure(\Throwable, int): void)|null  $onError  Called with the failure and the number of records dropped (always 1 for this logger).
     */
    public function __construct(
        Confish $client,
        private readonly ?\Closure $onError = null,
    ) {
        $this->logs = $client->logs;
    }

    /**
     * @param  LogLevel|string|\Stringable  $level
     * @param  array<mixed>  $context
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $mapped = $this->mapLevel($level);

        try {
            $this->logs->write($mapped, (string) $message, $context === [] ? null : $this->normalizeContext($context));
        } catch (\Throwable $e) {
            $this->droppedCount++;
            $this->reportError($e);
        }
    }

    /**
     * Number of records dropped because they could not be delivered.
     */
    public function droppedCount(): int
    {
        return $this->droppedCount;
    }

    private function mapLevel(mixed $level): LogLevel
    {
        if ($level instanceof LogLevel) {
            return $level;
        }

        if (is_string($level) || $level instanceof \Stringable) {
            $mapped = LogLevel::tryFrom(strtolower((string) $level));
            if ($mapped !== null) {
                return $mapped;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Unknown log level: %s',
            is_scalar($level) || $level instanceof \Stringable ? (string) $level : get_debug_type($level),
        ));
    }

    /**
     * Throwables in context would JSON-encode to "{}" (their properties are
     * protected), so they are converted to a readable shape before sending.
     *
     * @param  array<mixed>  $context
     * @return array<string, mixed>
     */
    private function normalizeContext(array $context): array
    {
        $normalized = [];
        foreach ($context as $key => $value) {
            $normalized[(string) $key] = $value instanceof \Throwable
                ? [
                    'class'   => $value::class,
                    'message' => $value->getMessage(),
                    'file'    => $value->getFile(),
                    'line'    => $value->getLine(),
                ]
                : $value;
        }

        return $normalized;
    }

    private function reportError(\Throwable $e): void
    {
        if ($this->onError === null) {
            return;
        }

        try {
            ($this->onError)($e, 1);
        } catch (\Throwable) {
            // Observer failures must not reach the logging path either.
        }
    }
}
