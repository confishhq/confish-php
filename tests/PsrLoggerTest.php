<?php

declare(strict_types=1);

namespace Confish\Tests;

use Confish\Exception\ServerException;
use Confish\LogLevel;
use Confish\PsrLogger;
use GuzzleHttp\Psr7\Response;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel as PsrLogLevel;

final class PsrLoggerTest extends TestCase
{
    private function logResponse(string $id = 'log_1'): Response
    {
        return new Response(201, ['Content-Type' => 'application/json'], "{\"id\":\"$id\"}");
    }

    public function test_delegates_each_call_to_the_single_entry_endpoint(): void
    {
        $client = $this->makeClient([$this->logResponse()]);
        $logger = new PsrLogger($client);

        $logger->info('Crawl started', ['sitemap' => 'https://example.com/sitemap.xml']);

        self::assertCount(1, $this->recorded);
        self::assertSame('POST', $this->recorded[0]['request']->getMethod());
        self::assertSame('/c/env_test/log', $this->recorded[0]['request']->getUri()->getPath());
        self::assertSame(
            [
                'level'   => 'info',
                'message' => 'Crawl started',
                'context' => ['sitemap' => 'https://example.com/sitemap.xml'],
            ],
            $this->bodyOf(0),
        );
    }

    public function test_maps_all_psr_levels_including_emergency(): void
    {
        $client = $this->makeClient(array_map(
            fn (int $i): Response => $this->logResponse("log_$i"),
            range(1, 8),
        ));
        $logger = new PsrLogger($client);

        $logger->debug('d');
        $logger->info('i');
        $logger->notice('n');
        $logger->warning('w');
        $logger->error('e');
        $logger->critical('c');
        $logger->alert('a');
        $logger->emergency('em');

        self::assertCount(8, $this->recorded);
        $levels = array_map(fn (int $i): mixed => $this->bodyOf($i)['level'], range(0, 7));
        self::assertSame(
            ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'],
            $levels,
        );
    }

    public function test_log_accepts_psr_level_constants(): void
    {
        $client = $this->makeClient([$this->logResponse()]);
        $logger = new PsrLogger($client);

        $logger->log(PsrLogLevel::EMERGENCY, 'meltdown');

        self::assertSame(['level' => 'emergency', 'message' => 'meltdown'], $this->bodyOf(0));
    }

    public function test_log_accepts_confish_level_enum(): void
    {
        $client = $this->makeClient([$this->logResponse()]);
        $logger = new PsrLogger($client);

        $logger->log(LogLevel::Critical, 'queue backlog past threshold');

        self::assertSame(['level' => 'critical', 'message' => 'queue backlog past threshold'], $this->bodyOf(0));
    }

    public function test_unknown_level_throws_psr_invalid_argument_exception(): void
    {
        $client = $this->makeClient([]);
        $logger = new PsrLogger($client);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('verbose');
        $logger->log('verbose', 'nope');
    }

    public function test_stringable_messages_are_cast(): void
    {
        $client = $this->makeClient([$this->logResponse()]);
        $logger = new PsrLogger($client);
        $message = new class
        {
            public function __toString(): string
            {
                return 'from stringable';
            }
        };

        $logger->notice($message);

        self::assertSame(['level' => 'notice', 'message' => 'from stringable'], $this->bodyOf(0));
    }

    public function test_empty_context_is_omitted(): void
    {
        $client = $this->makeClient([$this->logResponse()]);
        $logger = new PsrLogger($client);

        $logger->info('no context');

        self::assertArrayNotHasKey('context', $this->bodyOf(0));
    }

    public function test_throwables_in_context_are_normalized(): void
    {
        $client = $this->makeClient([$this->logResponse()]);
        $logger = new PsrLogger($client);

        $logger->error('Incident opened', ['exception' => new \RuntimeException('disk full')]);

        /** @var array{class: string, message: string, file: string, line: int} $exception */
        $exception = $this->bodyOf(0)['context']['exception'];
        self::assertSame(\RuntimeException::class, $exception['class']);
        self::assertSame('disk full', $exception['message']);
        self::assertArrayHasKey('file', $exception);
        self::assertArrayHasKey('line', $exception);
    }

    public function test_transport_failures_are_swallowed_and_counted(): void
    {
        $client = $this->makeClient([
            new Response(500, [], '{"error":"boom"}'),
            new Response(500, [], '{"error":"boom"}'),
            $this->logResponse(),
        ], maxRetries: 0);
        /** @var list<array{0: \Throwable, 1: int}> $captured */
        $captured = [];
        $logger = new PsrLogger($client, onError: static function (\Throwable $e, int $dropped) use (&$captured): void {
            $captured[] = [$e, $dropped];
        });

        $logger->error('one');
        $logger->error('two');
        $logger->info('recovered');

        self::assertSame(2, $logger->droppedCount());
        self::assertCount(2, $captured);
        self::assertInstanceOf(ServerException::class, $captured[0][0]);
        self::assertSame(1, $captured[0][1]);
        self::assertCount(3, $this->recorded);
    }

    public function test_on_error_callback_failures_are_swallowed(): void
    {
        $client = $this->makeClient([new Response(500, [], '{"error":"boom"}')], maxRetries: 0);
        $logger = new PsrLogger($client, onError: static function (): void {
            throw new \RuntimeException('observer exploded');
        });

        $logger->error('one');

        self::assertSame(1, $logger->droppedCount());
    }
}
