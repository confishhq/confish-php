<?php

declare(strict_types=1);

namespace Confish\Tests;

use Confish\Confish;
use Confish\Exception\AuthException;
use Confish\Exception\ConflictException;
use Confish\Exception\RateLimitException;
use Confish\Exception\ValidationException;
use Confish\LogLevel;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

final class ConfishTest extends TestCase
{
    public function test_fetch_returns_decoded_array(): void
    {
        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'application/json'], '{"site_name":"My App","max_upload_mb":25}'),
        ]);

        $config = $client->fetch();

        self::assertSame('My App', $config['site_name']);
        $request = $this->recorded[0]['request'];
        self::assertInstanceOf(Request::class, $request);
        self::assertSame('Bearer confish_sk_test', $request->getHeaderLine('Authorization'));
        self::assertSame('/c/env_test', $request->getUri()->getPath());
    }

    public function test_update_wraps_values_in_patch(): void
    {
        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'application/json'], '{}'),
        ]);

        $client->update(['maintenance_mode' => true]);

        self::assertSame('PATCH', $this->recorded[0]['request']->getMethod());
        self::assertSame(['values' => ['maintenance_mode' => true]], $this->bodyOf(0));
    }

    public function test_replace_uses_put(): void
    {
        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'application/json'], '{}'),
        ]);

        $client->replace(['site_name' => 'X']);

        self::assertSame('PUT', $this->recorded[0]['request']->getMethod());
    }

    public function test_auth_error_on_401(): void
    {
        $client = $this->makeClient([
            new Response(401, [], '{"error":"Missing API key"}'),
        ]);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Missing API key');
        $client->fetch();
    }

    public function test_validation_error_exposes_field_errors(): void
    {
        $client = $this->makeClient([
            new Response(422, [], '{"message":"invalid","errors":{"values.max_upload_mb":["Must be at most 100."]}}'),
        ]);

        try {
            $client->update(['x' => 1]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['values.max_upload_mb' => ['Must be at most 100.']], $e->errors);
        }
    }

    public function test_rate_limit_retries_then_succeeds(): void
    {
        $client = $this->makeClient([
            new Response(429, ['Retry-After' => '0'], '{"error":"limited"}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}'),
        ], maxRetries: 1);

        $config = $client->fetch();

        self::assertSame(['ok' => true], $config);
        self::assertCount(2, $this->recorded);
    }

    public function test_rate_limit_exhausts_retries(): void
    {
        $client = $this->makeClient([
            new Response(429, ['Retry-After' => '0', 'X-RateLimit-Limit' => '60'], '{"error":"limited"}'),
            new Response(429, ['Retry-After' => '0', 'X-RateLimit-Limit' => '60'], '{"error":"limited"}'),
        ], maxRetries: 1);

        try {
            $client->fetch();
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            self::assertSame(60, $e->limit);
        }
    }

    public function test_conflict_on_ack(): void
    {
        $client = $this->makeClient([
            new Response(409, [], '{"error":"already acknowledged"}'),
        ]);

        $this->expectException(ConflictException::class);
        $client->actions->ack('a1');
    }

    public function test_logger_sends_level_and_context(): void
    {
        $client = $this->makeClient([
            new Response(201, ['Content-Type' => 'application/json'], '{"id":"log_1"}'),
        ]);

        $logId = $client->logger->info('hello', ['user_id' => 1]);

        self::assertSame('log_1', $logId);
        self::assertSame(
            ['level' => 'info', 'message' => 'hello', 'context' => ['user_id' => 1]],
            $this->bodyOf(0),
        );
    }

    public function test_log_with_explicit_level_enum(): void
    {
        $client = $this->makeClient([
            new Response(201, ['Content-Type' => 'application/json'], '{"id":"log_2"}'),
        ]);

        $client->log(LogLevel::Critical, 'system down');

        self::assertSame(['level' => 'critical', 'message' => 'system down'], $this->bodyOf(0));
    }

    public function test_constructor_validates_required_fields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('envId');
        new Confish('', 'k');
    }

    public function test_constructor_validates_api_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('apiKey');
        new Confish('e', '');
    }
}
