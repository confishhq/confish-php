<?php

declare(strict_types=1);

namespace Confish\Tests;

use Confish\Exception\ServerException;
use Confish\MonologHandler;
use GuzzleHttp\Psr7\Response;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;

final class MonologHandlerTest extends TestCase
{
    private function idsResponse(int $count): Response
    {
        $ids = array_map(static fn (int $i): string => "log_$i", range(1, $count));

        return new Response(201, ['Content-Type' => 'application/json'], json_encode(['ids' => $ids], JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entriesOf(int $index): array
    {
        /** @var list<array<string, mixed>> $entries */
        $entries = $this->bodyOf($index)['entries'] ?? [];

        return $entries;
    }

    public function test_maps_all_levels_including_emergency(): void
    {
        $client = $this->makeClient([$this->idsResponse(8)]);
        $handler = new MonologHandler($client);
        $logger = new Logger('app', [$handler]);

        $logger->debug('d');
        $logger->info('i');
        $logger->notice('n');
        $logger->warning('w');
        $logger->error('e');
        $logger->critical('c');
        $logger->alert('a');
        $logger->emergency('em');
        $handler->flush();

        self::assertCount(1, $this->recorded);
        self::assertSame('POST', $this->recorded[0]['request']->getMethod());
        self::assertSame('/c/env_test/logs', $this->recorded[0]['request']->getUri()->getPath());
        self::assertSame(
            ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'],
            array_column($this->entriesOf(0), 'level'),
        );
    }

    public function test_buffers_until_flush_threshold(): void
    {
        $client = $this->makeClient([$this->idsResponse(50)]);
        $handler = new MonologHandler($client);
        $logger = new Logger('app', [$handler]);

        for ($i = 1; $i <= 49; $i++) {
            $logger->info("record $i");
        }
        self::assertCount(0, $this->recorded);

        $logger->info('record 50');

        self::assertCount(1, $this->recorded);
        self::assertCount(50, $this->entriesOf(0));
    }

    public function test_chunks_oversized_buffers_to_100_entries_per_request(): void
    {
        $client = $this->makeClient([$this->idsResponse(100), $this->idsResponse(50)]);
        $handler = new MonologHandler($client, flushAt: 150);
        $logger = new Logger('app', [$handler]);

        for ($i = 1; $i <= 150; $i++) {
            $logger->info("record $i");
        }

        self::assertCount(2, $this->recorded);
        self::assertCount(100, $this->entriesOf(0));
        self::assertCount(50, $this->entriesOf(1));
    }

    public function test_close_flushes_remaining_entries(): void
    {
        $client = $this->makeClient([$this->idsResponse(3)]);
        $handler = new MonologHandler($client);
        $logger = new Logger('app', [$handler]);

        $logger->info('one');
        $logger->info('two');
        $logger->info('three');
        self::assertCount(0, $this->recorded);

        $handler->close();

        self::assertCount(1, $this->recorded);
        self::assertSame(['one', 'two', 'three'], array_column($this->entriesOf(0), 'message'));
    }

    public function test_destructor_flushes_remaining_entries(): void
    {
        $client = $this->makeClient([$this->idsResponse(1)]);
        $handler = new MonologHandler($client);
        $logger = new Logger('app', [$handler]);

        $logger->warning('shutting down');
        self::assertCount(0, $this->recorded);

        unset($logger, $handler);

        self::assertCount(1, $this->recorded);
        self::assertSame('warning', $this->entriesOf(0)[0]['level']);
    }

    public function test_sends_context_extra_and_timestamp_per_entry(): void
    {
        $client = $this->makeClient([$this->idsResponse(1)]);
        $handler = new MonologHandler($client);
        $logger = new Logger('app', [$handler]);
        $logger->pushProcessor(static fn (LogRecord $record): LogRecord => $record->with(extra: ['host' => 'worker-1']));

        $before = new \DateTimeImmutable('-2 seconds');
        $logger->error('Crawl failed', ['job_id' => 'crawl_42', 'attempt' => 3]);
        $handler->flush();
        $after = new \DateTimeImmutable('+2 seconds');

        $entry = $this->entriesOf(0)[0];
        self::assertSame('error', $entry['level']);
        self::assertSame('Crawl failed', $entry['message']);
        self::assertSame(
            ['job_id' => 'crawl_42', 'attempt' => 3, 'extra' => ['host' => 'worker-1']],
            $entry['context'],
        );
        $timestamp = new \DateTimeImmutable($entry['timestamp']);
        self::assertGreaterThanOrEqual($before, $timestamp);
        self::assertLessThanOrEqual($after, $timestamp);
    }

    public function test_timestamps_are_captured_at_log_time_not_flush_time(): void
    {
        $client = $this->makeClient([$this->idsResponse(1)]);
        $handler = new MonologHandler($client);

        $loggedAt = new \DateTimeImmutable('2026-07-12 09:58:12+00:00');
        $handler->handle(new LogRecord(
            datetime: $loggedAt,
            channel: 'app',
            level: Level::Info,
            message: 'captured earlier',
        ));
        $handler->flush();

        self::assertSame('2026-07-12T09:58:12+00:00', $this->entriesOf(0)[0]['timestamp']);
    }

    public function test_throwables_in_context_are_normalized(): void
    {
        $client = $this->makeClient([$this->idsResponse(1)]);
        $handler = new MonologHandler($client);
        $logger = new Logger('app', [$handler]);

        $logger->error('Import failed', ['exception' => new \RuntimeException('disk full')]);
        $handler->flush();

        $context = $this->entriesOf(0)[0]['context'];
        self::assertSame(\RuntimeException::class, $context['exception']['class']);
        self::assertSame('disk full', $context['exception']['message']);
        self::assertArrayHasKey('file', $context['exception']);
        self::assertArrayHasKey('line', $context['exception']);
    }

    public function test_respects_minimum_level(): void
    {
        $client = $this->makeClient([$this->idsResponse(1)]);
        $handler = new MonologHandler($client, level: Level::Warning);
        $logger = new Logger('app', [$handler]);

        $logger->info('ignored');
        $logger->error('kept');
        $handler->flush();

        $entries = $this->entriesOf(0);
        self::assertCount(1, $entries);
        self::assertSame('kept', $entries[0]['message']);
    }

    public function test_transport_failures_are_swallowed_and_counted(): void
    {
        $client = $this->makeClient([
            new Response(500, [], '{"error":"boom"}'),
            $this->idsResponse(1),
        ], maxRetries: 0);
        /** @var list<array{0: \Throwable, 1: int}> $captured */
        $captured = [];
        $handler = new MonologHandler($client, onError: static function (\Throwable $e, int $dropped) use (&$captured): void {
            $captured[] = [$e, $dropped];
        });
        $logger = new Logger('app', [$handler]);

        $logger->error('one');
        $logger->error('two');
        $logger->error('three');
        $handler->flush();

        self::assertSame(3, $handler->droppedCount());
        self::assertCount(1, $captured);
        self::assertInstanceOf(ServerException::class, $captured[0][0]);
        self::assertSame(3, $captured[0][1]);

        // The buffer was cleared on failure; subsequent records deliver fresh.
        $logger->info('recovered');
        $handler->flush();
        self::assertSame(3, $handler->droppedCount());
        self::assertCount(1, $this->entriesOf(1));
    }

    public function test_on_error_callback_failures_are_swallowed(): void
    {
        $client = $this->makeClient([new Response(500, [], '{"error":"boom"}')], maxRetries: 0);
        $handler = new MonologHandler($client, onError: static function (): void {
            throw new \RuntimeException('observer exploded');
        });
        $logger = new Logger('app', [$handler]);

        $logger->error('one');
        $handler->flush();

        self::assertSame(1, $handler->droppedCount());
    }

    public function test_flush_at_must_be_positive(): void
    {
        $client = $this->makeClient([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('flushAt');
        new MonologHandler($client, flushAt: 0);
    }
}
