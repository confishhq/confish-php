<?php

declare(strict_types=1);

namespace Confish;

/**
 * A handle bound to one feed slug. Obtain via Confish::feed() — construction
 * makes no HTTP request.
 */
final class Feed
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $envId,
        private readonly string $slug,
    ) {
    }

    /**
     * Upserts an item by your own external ID (PUT — declarative full-replace).
     *
     * $ttl is in seconds (1 to 2592000). Omitting it makes the item permanent —
     * and, because PUT replaces the item's state entirely, omitting it also
     * clears any TTL set by a previous set().
     *
     * @param  array<string, mixed>  $data
     */
    public function set(string $externalId, array $data, ?int $ttl = null): FeedItem
    {
        $body = ['data' => $data];
        if ($ttl !== null) {
            $body['ttl'] = $ttl;
        }

        return FeedItem::fromArray(
            $this->http->request('PUT', "/c/{$this->envId}/feeds/{$this->slug}/items/".rawurlencode($externalId), $body) ?? []
        );
    }

    /**
     * Declaratively replaces the environment's entire partition of this feed
     * (PUT on the collection): the partition becomes exactly $items. Existing
     * external IDs update in place, new IDs are created, and anything absent
     * from $items is DELETED — an empty list clears the feed.
     *
     * Built for sync-style cron jobs that push their full dataset in one
     * request instead of N set() calls plus racy client-side diffing.
     * All-or-nothing: duplicate external_ids, more items than the plan's item
     * cap, or any schema-invalid item rejects the whole request with nothing
     * written.
     *
     * Each item is an array with `external_id`, `data`, and an optional `ttl`
     * in seconds — omit the ttl key to make that item permanent, same rule
     * as set().
     *
     * @param  list<array{external_id: string, data: array<string, mixed>, ttl?: int}>  $items
     */
    public function replace(array $items): FeedReplaceResult
    {
        return FeedReplaceResult::fromArray(
            $this->http->request('PUT', "/c/{$this->envId}/feeds/{$this->slug}/items", ['items' => $items]) ?? []
        );
    }

    /**
     * The environment's live items, newest first.
     *
     * @return list<FeedItem>
     */
    public function list(): array
    {
        $response = $this->http->request('GET', "/c/{$this->envId}/feeds/{$this->slug}/items");
        /** @var list<array<string, mixed>> $items */
        $items = is_array($response) && isset($response['items']) && is_array($response['items'])
            ? $response['items']
            : [];

        return array_map(static fn (array $i): FeedItem => FeedItem::fromArray($i), $items);
    }

    /**
     * Deletes an item by external ID. Idempotent — deleting an item that is
     * already gone succeeds, so retries never error.
     */
    public function delete(string $externalId): void
    {
        $this->http->request('DELETE', "/c/{$this->envId}/feeds/{$this->slug}/items/".rawurlencode($externalId));
    }
}
