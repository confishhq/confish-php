<?php

declare(strict_types=1);

namespace Confish\Tests;

use Confish\Exception\NotFoundException;
use Confish\Exception\ValidationException;
use Confish\FeedItem;
use Confish\FeedReplaceResult;
use GuzzleHttp\Psr7\Response;

final class FeedTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function itemJson(array $overrides = []): string
    {
        return json_encode(array_merge([
            'id'          => 'fi_1',
            'external_id' => 'sitemap-crawl',
            'data'        => ['status' => 'running'],
            'expires_at'  => null,
            'created_at'  => '2026-07-09T12:00:00+00:00',
            'updated_at'  => '2026-07-09T12:00:00+00:00',
        ], $overrides), JSON_THROW_ON_ERROR);
    }

    public function test_set_sends_put_with_data_and_ttl(): void
    {
        $client = $this->makeClient([
            new Response(201, ['Content-Type' => 'application/json'], $this->itemJson([
                'expires_at' => '2026-07-10T12:00:00+00:00',
            ])),
        ]);

        $item = $client->feed('jobs')->set('sitemap-crawl', ['status' => 'running'], ttl: 86_400);

        $request = $this->recorded[0]['request'];
        self::assertSame('PUT', $request->getMethod());
        self::assertSame('/c/env_test/feeds/jobs/items/sitemap-crawl', $request->getUri()->getPath());
        self::assertSame(['data' => ['status' => 'running'], 'ttl' => 86_400], $this->bodyOf(0));
        self::assertInstanceOf(FeedItem::class, $item);
        self::assertSame('fi_1', $item->id);
        self::assertSame('sitemap-crawl', $item->externalId);
        self::assertSame(['status' => 'running'], $item->data);
        self::assertSame('2026-07-10T12:00:00+00:00', $item->expiresAt);
    }

    public function test_set_omits_ttl_key_when_unset(): void
    {
        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'application/json'], $this->itemJson()),
        ]);

        $item = $client->feed('jobs')->set('sitemap-crawl', ['status' => 'done']);

        $body = $this->bodyOf(0);
        self::assertArrayNotHasKey('ttl', $body);
        self::assertSame(['data' => ['status' => 'done']], $body);
        self::assertNull($item->expiresAt);
    }

    public function test_replace_sends_collection_put_and_parses_counts(): void
    {
        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'application/json'], '{"created":1,"updated":1,"deleted":2}'),
        ]);

        $result = $client->feed('jobs')->replace([
            ['external_id' => 'sitemap-crawl', 'data' => ['status' => 'running'], 'ttl' => 3_600],
            ['external_id' => 'image-crawl', 'data' => ['status' => 'queued']],
        ]);

        $request = $this->recorded[0]['request'];
        self::assertSame('PUT', $request->getMethod());
        self::assertSame('/c/env_test/feeds/jobs/items', $request->getUri()->getPath());
        self::assertSame([
            'items' => [
                ['external_id' => 'sitemap-crawl', 'data' => ['status' => 'running'], 'ttl' => 3_600],
                ['external_id' => 'image-crawl', 'data' => ['status' => 'queued']],
            ],
        ], $this->bodyOf(0));
        self::assertArrayNotHasKey('ttl', $this->bodyOf(0)['items'][1]);
        self::assertInstanceOf(FeedReplaceResult::class, $result);
        self::assertSame(1, $result->created);
        self::assertSame(1, $result->updated);
        self::assertSame(2, $result->deleted);
    }

    public function test_replace_with_empty_list_clears_feed(): void
    {
        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'application/json'], '{"created":0,"updated":0,"deleted":5}'),
        ]);

        $result = $client->feed('jobs')->replace([]);

        self::assertSame(['items' => []], $this->bodyOf(0));
        self::assertSame(0, $result->created);
        self::assertSame(5, $result->deleted);
    }

    public function test_replace_maps_422_to_validation_exception(): void
    {
        $client = $this->makeClient([
            new Response(422, [], '{"message":"invalid","errors":{"items.1.data.status":["The status field is required."]}}'),
        ]);

        try {
            $client->feed('jobs')->replace([
                ['external_id' => 'a', 'data' => ['status' => 'running']],
                ['external_id' => 'b', 'data' => ['nope' => true]],
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['items.1.data.status' => ['The status field is required.']], $e->errors);
        }
    }

    public function test_list_unwraps_items_array(): void
    {
        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'application/json'],
                '{"items":['.$this->itemJson(['id' => 'fi_2', 'external_id' => 'b']).','.$this->itemJson().']}'),
        ]);

        $items = $client->feed('jobs')->list();

        self::assertSame('GET', $this->recorded[0]['request']->getMethod());
        self::assertSame('/c/env_test/feeds/jobs/items', $this->recorded[0]['request']->getUri()->getPath());
        self::assertCount(2, $items);
        self::assertInstanceOf(FeedItem::class, $items[0]);
        self::assertSame('b', $items[0]->externalId);
        self::assertSame('sitemap-crawl', $items[1]->externalId);
    }

    public function test_delete_sends_delete_and_tolerates_empty_204(): void
    {
        $client = $this->makeClient([
            new Response(204, [], ''),
        ]);

        $client->feed('jobs')->delete('sitemap-crawl');

        self::assertCount(1, $this->recorded);
        self::assertSame('DELETE', $this->recorded[0]['request']->getMethod());
        self::assertSame('/c/env_test/feeds/jobs/items/sitemap-crawl', $this->recorded[0]['request']->getUri()->getPath());
    }

    public function test_unknown_feed_maps_to_not_found_exception(): void
    {
        $client = $this->makeClient([
            new Response(404, [], '{"error":"Feed not found"}'),
        ]);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Feed not found');
        $client->feed('nope')->list();
    }

    public function test_schema_mismatch_exposes_field_errors(): void
    {
        $client = $this->makeClient([
            new Response(422, [], '{"message":"invalid","errors":{"data.status":["The status field is required."]}}'),
        ]);

        try {
            $client->feed('jobs')->set('x', ['nope' => true]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['data.status' => ['The status field is required.']], $e->errors);
        }
    }

    public function test_full_feed_maps_to_validation_exception_with_message_only(): void
    {
        $client = $this->makeClient([
            new Response(422, [], '{"error":"Feed is full (100 items). Delete items, set TTLs, or upgrade your plan."}'),
        ]);

        try {
            $client->feed('jobs')->set('x', ['status' => 'running']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertStringContainsString('Feed is full', $e->getMessage());
            self::assertSame([], $e->errors);
        }
    }
}
