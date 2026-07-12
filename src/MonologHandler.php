<?php

declare(strict_types=1);

namespace Confish;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Monolog v3 handler that ships log records to confish in batches.
 *
 * Records are buffered in memory — with the timestamp captured at log time —
 * and sent through the batch log endpoint when the buffer reaches $flushAt
 * records, on flush(), and on close(). Monolog calls close() from the
 * handler's destructor, so pending records are delivered when the process
 * shuts down. Oversized buffers are chunked to Logs::MAX_BATCH_SIZE entries
 * per request. Record extra (added by processors) is sent under the context
 * `extra` key.
 *
 * PHP processes are typically short-lived, so the size trigger plus
 * close()/destruct covers most setups. Long-running workers (queue consumers,
 * daemons) should call flush() on their own cadence or wrap this handler in
 * Monolog's own BufferHandler.
 *
 * Transport failures never throw into the logging path: a failed batch is
 * dropped, counted (droppedCount()), and reported to the optional $onError
 * callback. Failures are never logged back through the handler itself, so a
 * delivery outage cannot feed back on itself.
 *
 * monolog/monolog (^3.0) is a suggested dependency, not a required one —
 * this class is only autoloaded when you instantiate it, so the SDK works
 * without Monolog installed.
 */
final class MonologHandler extends AbstractProcessingHandler
{
    /** @var list<array{level: string, message: string, context?: array<string, mixed>, timestamp: string}> */
    private array $buffer = [];

    private int $droppedCount = 0;

    private readonly Logs $logs;

    /**
     * @param  int  $flushAt  Number of buffered records that triggers an automatic flush.
     * @param  (\Closure(\Throwable, int): void)|null  $onError  Called with the failure and the number of entries dropped.
     */
    public function __construct(
        Confish $client,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        private readonly int $flushAt = 50,
        private readonly ?\Closure $onError = null,
    ) {
        if ($flushAt < 1) {
            throw new \InvalidArgumentException('flushAt must be at least 1');
        }

        parent::__construct($level, $bubble);
        $this->logs = $client->logs;
    }

    /**
     * Sends all buffered entries now. Safe to call at any time — transport
     * failures are swallowed, counted, and reported via the onError callback.
     */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $entries = $this->buffer;
        $this->buffer = [];

        foreach (array_chunk($entries, Logs::MAX_BATCH_SIZE) as $chunk) {
            try {
                $this->logs->writeBatch($chunk);
            } catch (\Throwable $e) {
                $this->droppedCount += count($chunk);
                $this->reportError($e, count($chunk));
            }
        }
    }

    public function close(): void
    {
        $this->flush();
        parent::close();
    }

    public function reset(): void
    {
        $this->flush();
        parent::reset();
    }

    /**
     * Number of entries dropped because their batch could not be delivered.
     */
    public function droppedCount(): int
    {
        return $this->droppedCount;
    }

    protected function write(LogRecord $record): void
    {
        $entry = [
            'level'   => $record->level->toPsrLogLevel(),
            'message' => $record->message,
        ];

        $context = $this->normalizeContext($record->context);
        if ($record->extra !== []) {
            $context['extra'] = $record->extra;
        }
        if ($context !== []) {
            $entry['context'] = $context;
        }

        $entry['timestamp'] = $record->datetime->format(\DateTimeInterface::ATOM);

        $this->buffer[] = $entry;

        if (count($this->buffer) >= $this->flushAt) {
            $this->flush();
        }
    }

    /**
     * Throwables in context would JSON-encode to "{}" (their properties are
     * protected), so they are converted to a readable shape before buffering.
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

    private function reportError(\Throwable $e, int $droppedEntries): void
    {
        if ($this->onError === null) {
            return;
        }

        try {
            ($this->onError)($e, $droppedEntries);
        } catch (\Throwable) {
            // Observer failures must not reach the logging path either.
        }
    }
}
