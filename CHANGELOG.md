# Changelog

## v0.3.0 — 2026-07-12

### Added

- **Monolog handler.** `Confish\MonologHandler` (Monolog v3) buffers records
  in memory — with the timestamp captured at log time — and ships them
  through the batch log endpoint: it flushes at 50 buffered records
  (`flushAt:`), on `flush()`, and on `close()`/destruct, chunked to at most
  100 entries per request. Delivery failures never throw into the logging
  path: dropped entries are counted (`droppedCount()`) and reported to an
  optional `onError` callback. Monolog stays optional — it ships in
  composer `suggest`, not `require`, and the class is only autoloaded when
  instantiated.
- **PSR-3 logger.** `Confish\PsrLogger` implements `Psr\Log\LoggerInterface`
  for code without Monolog, sending each record immediately through
  `$client->logs` — with the same never-throw guarantee,
  `droppedCount()`, and `onError` callback. Adds `psr/log` (^2 || ^3) as a
  dependency.
- `$client->logs->writeBatch($entries)` — writes up to
  `Logs::MAX_BATCH_SIZE` (100) entries in one request (each with `level`,
  `message`, optional `context`, and optional ISO 8601 `timestamp`) and
  returns the created log entry IDs.

## v0.2.0 — 2026-07-09

### Added

- **Feeds.** `$client->feed($slug)` returns a handle bound to one feed with
  `set($externalId, $data, ttl: ...)`, `list()`, `delete($externalId)`, and
  whole-partition `replace($items)` — the environment's partition becomes
  exactly the provided items (absent items are deleted, an empty list clears
  the feed; all-or-nothing) and a `FeedReplaceResult` with created/updated/
  deleted counts is returned. Plus the `FeedItem` value object. `set` is a
  declarative full-replace (PUT): omitting `ttl` makes the item permanent and
  clears any previously set TTL.
- `NotFoundException` — HTTP 404 responses now map to a dedicated exception
  instead of the base `ConfishException`.
- `LogLevel::Emergency` and `$client->logs->emergency()` — the full RFC 5424
  level set.
- `$client->logs->write($level, $message, $context)` for explicit-level writes.

### Breaking

- **Config namespace.** `$client->fetch()`, `$client->update()`, and
  `$client->replace()` moved to `$client->config->fetch()`,
  `$client->config->update()`, and `$client->config->replace()`.
- **Logs consolidation.** `$client->logger` is now `$client->logs`, and the
  flat `$client->log(...)` was removed in favor of `$client->logs->write(...)`.
- **Webhook verify.** `Webhook::verify()` now returns the parsed
  `WebhookPayload` on success instead of `true`, and throws on failure instead
  of returning `false`: `WebhookSignatureException` for a missing, malformed,
  or mismatched signature; `WebhookTimestampException` for a timestamp outside
  tolerance (both extend `WebhookVerificationException`).
- **Actions progress.** `$client->actions->update()` renamed to
  `$client->actions->progress()`, and `ActionUpdater::update()` renamed to
  `ActionUpdater::progress()`. The wire call is unchanged.

## v0.1.0

- Initial release.
