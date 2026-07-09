# Changelog

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
