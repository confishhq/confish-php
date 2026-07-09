# confish/sdk

Official PHP SDK for [confish](https://confi.sh) — typed configuration, feeds, actions, and webhook verification.

- One dependency (Guzzle)
- Typed exceptions and automatic retry on `429`/`5xx`
- Long-running action consumer with graceful-shutdown hook
- HMAC-SHA256 webhook verification (no extra deps)

## Install

```sh
composer require confish/sdk
```

Requires PHP 8.1+.

## Quick start

```php
use Confish\Confish;

$client = new Confish(
    envId: getenv('CONFISH_ENV_ID'),
    apiKey: getenv('CONFISH_API_KEY'),
);

$config = $client->config->fetch();
echo $config['site_name'];
```

`fetch`, `update`, and `replace` return `array<string, mixed>`. PHPStan/Psalm users can document expected shapes via array shapes:

```php
/** @var array{site_name: string, max_upload_mb: int, maintenance_mode: bool} $config */
$config = $client->config->fetch();
```

## Reading and writing config

```php
// GET /c/{env_id}
$config = $client->config->fetch();

// PATCH — only listed fields change
$client->config->update(['maintenance_mode' => true]);

// PUT — replaces everything; omitted fields reset to defaults
$client->config->replace([
    'site_name'        => 'My App',
    'max_upload_mb'    => 50,
    'maintenance_mode' => false,
]);
```

`update` and `replace` return the full updated configuration.

> Write access must be enabled in environment settings before `update` and `replace` will work.

## Feeds

Feeds hold living state — job statuses, crawl results, active incidents — keyed by your own external IDs. `$client->feed($slug)` returns a handle bound to one feed; no HTTP happens until you call a method on it.

```php
$jobs = $client->feed('jobs');

// PUT — create-or-replace ("upsert") by external ID; returns a FeedItem
$jobs->set('sitemap-crawl', ['status' => 'running', 'urls' => 1423], ttl: 86400);

// The environment's live items, newest first — list<FeedItem>
foreach ($jobs->list() as $item) {
    echo "{$item->externalId}: {$item->data['status']}\n";
}

// Idempotent — deleting an item that is already gone succeeds
$jobs->delete('sitemap-crawl');
```

`set` is a declarative full-replace: the item's data becomes exactly what you pass. `ttl` is in seconds (1 to 2592000 = 30 days); **omitting it makes the item permanent and clears any TTL set by a previous `set`** — TTLs are never carried over. External IDs are limited to 255 characters.

For sync-style cron jobs that push their full dataset each run, `replace` makes the feed exactly the items you pass in one request — no client-side diffing:

```php
$result = $jobs->replace([
    ['external_id' => 'sitemap-crawl', 'data' => ['status' => 'running'], 'ttl' => 86400],
    ['external_id' => 'image-crawl',   'data' => ['status' => 'queued']],
]);
echo "{$result->created} created, {$result->updated} updated, {$result->deleted} deleted";
```

**Anything absent from the payload is deleted**, and an empty list clears the feed. The request is all-or-nothing: duplicate `external_id`s, more items than your plan's item cap, or any schema-invalid item rejects the whole request with a `422` and nothing written. The per-item `ttl` key follows the same rule as `set` — omit it to make that item permanent.

Each `FeedItem` exposes `id`, `externalId`, `data` (`array<string, mixed>`), `expiresAt` (nullable), `createdAt`, and `updatedAt`.

An unknown feed slug throws `NotFoundException`. A `422` throws `ValidationException` — with per-field messages in `$e->errors` when the item data doesn't match the feed's schema, or a message-only error when the feed is at its item cap.

## Logging

```php
use Confish\LogLevel;

$client->logs->info('Worker started', ['region' => 'eu-west-1']);
$client->logs->error('Job failed', ['job_id' => 'abc']);

// Or with an explicit level:
$logId = $client->logs->write(LogLevel::Critical, 'system down', ['code' => 503]);
```

Levels via the `LogLevel` enum: `Debug`, `Info`, `Notice`, `Warning`, `Error`, `Critical`, `Alert`, `Emergency`. Levels follow RFC 5424 (syslog), so they map 1:1 onto PSR-3/Monolog levels.

## Actions

The action consumer polls for pending actions, acknowledges them, runs your handler, and reports completion or failure — including idempotent skip if another consumer claimed the same action first.

```php
use Confish\Action;
use Confish\ActionUpdater;
use Confish\Confish;
use Confish\Exception\SkipActionException;

$client = new Confish(envId: '...', apiKey: '...');

// Wire SIGTERM/SIGINT to a stop flag (requires ext-pcntl).
$shouldStop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$shouldStop) { $shouldStop = true; });
    pcntl_signal(SIGINT,  function () use (&$shouldStop) { $shouldStop = true; });
}

$client->actions->consume(
    handler: function (Action $action, ActionUpdater $u): ?array {
        if ($action->type === 'rebuild_index') {
            $u->progress('Rebuilding search index', ['params' => $action->params]);
            // ... do work ...
            return ['documents_indexed' => 15230, 'duration_ms' => 8412];
        }
        throw new RuntimeException("unknown action type: {$action->type}");
    },
    pollInterval: 15.0,    // base — defaults to 15s
    maxPollInterval: 60.0, // adaptive backoff cap
    shouldStop: fn (): bool => $shouldStop,
    onError: fn (Throwable $e, Action $a) => error_log("action {$a->id}: {$e->getMessage()}"),
);
```

What happens automatically:
- A returned `array` becomes the action's `result` on completion.
- Throwing any exception fails the action with `['error' => $e->getMessage()]`.
- Throwing `SkipActionException` leaves the action acknowledged without resolving it.
- A `409 Conflict` on ack is silently skipped — safe to run multiple consumers.
- The `shouldStop` callback is checked at the top of every poll; the loop exits cleanly.
- After 3 consecutive empty polls the loop doubles its sleep up to `maxPollInterval`, resetting to `pollInterval` the moment any action is processed. Idle consumers make ~240 requests/hour by default.

You can also drive the lifecycle manually:

```php
$actions = $client->actions->list();
$client->actions->ack('action_id');
$client->actions->progress('action_id', 'Crawling sitemap 2 of 5', ['step' => 2]);
$client->actions->complete('action_id', ['pages_crawled' => 1423]);
$client->actions->fail('action_id', ['error' => 'timeout']);
```

## Webhook verification

`Webhook::verify` parses and verifies in one step: it returns the parsed `WebhookPayload` on success and throws on failure, so a bad signature can never be ignored.

```php
use Confish\Exception\WebhookVerificationException;
use Confish\Webhook;

// Inside a controller / route handler:
$body      = file_get_contents('php://input');         // raw, unparsed
$signature = $_SERVER['HTTP_X_CONFISH_SIGNATURE'] ?? null;

try {
    $payload = Webhook::verify(
        body: $body,
        signature: $signature,
        secret: getenv('CONFISH_WEBHOOK_SECRET'),
    );
} catch (WebhookVerificationException $e) {
    http_response_code(401);
    exit('Invalid webhook: '.$e->getMessage());
}

// $payload->event, $payload->environment['env_id'], $payload->changes, $payload->values ...
```

Laravel example:

```php
use Confish\Exception\WebhookVerificationException;
use Confish\Webhook;
use Illuminate\Http\Request;

Route::post('/webhook', function (Request $request) {
    try {
        $payload = Webhook::verify(
            body: $request->getContent(),
            signature: $request->header('X-Confish-Signature'),
            secret: config('services.confish.webhook_secret'),
        );
    } catch (WebhookVerificationException) {
        abort(401);
    }

    // handle $payload->event ...
    return response()->noContent();
});
```

Failures are typed: `WebhookSignatureException` when the signature is missing, malformed, or doesn't match, and `WebhookTimestampException` when the signature matches but the timestamp is outside tolerance. Both extend `WebhookVerificationException`. Verification uses constant-time comparison and rejects timestamps older than 5 minutes by default — pass `toleranceSeconds: 0` to disable. Always pass the **raw, unparsed body** — re-serializing parsed JSON breaks verification.

## Errors

```php
use Confish\Exception\{
    AuthException,
    ConfishException,
    ConflictException,
    ForbiddenException,
    NetworkException,
    NotFoundException,
    RateLimitException,
    ServerException,
    ValidationException,
};

try {
    $client->config->fetch();
} catch (RateLimitException $e) {
    sleep($e->retryAfter ?? 1);
} catch (ValidationException $e) {
    foreach ($e->errors as $field => $messages) {
        echo "$field: ".implode(', ', $messages);
    }
} catch (ConfishException $e) {
    echo "HTTP {$e->statusCode}: {$e->getMessage()}";
}
```

By default the client retries `429` (honoring `Retry-After`) and `5xx` responses up to twice. Tune with `maxRetries` on the constructor.

## Options

```php
use GuzzleHttp\Client as GuzzleClient;

$client = new Confish(
    envId: 'a1b2c3d4e5f6',
    apiKey: 'confish_sk_...',
    baseUrl: Confish::DEFAULT_BASE_URL, // override for self-hosted
    httpClient: new GuzzleClient(['timeout' => 10.0]), // inject your own
    userAgent: 'my-app/1.0',
    maxRetries: 2,
    maxRetryDelay: 30.0,
);
```

## License

MIT
