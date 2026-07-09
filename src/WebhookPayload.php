<?php

declare(strict_types=1);

namespace Confish;

/** Parsed webhook payload returned by {@see Webhook::verify()}. */
final class WebhookPayload
{
    /**
     * @param  array<string, mixed>  $application  e.g. ['name' => 'My App']
     * @param  array<string, mixed>  $environment  e.g. ['name' => 'Production', 'env_id' => '...', 'url' => '...']
     * @param  list<string>  $changes
     * @param  array<string, mixed>|null  $values
     */
    public function __construct(
        public readonly string $event,
        public readonly ?string $timestamp = null,
        public readonly array $application = [],
        public readonly array $environment = [],
        public readonly array $changes = [],
        public readonly ?array $values = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            event: isset($data['event']) ? (string) $data['event'] : '',
            timestamp: isset($data['timestamp']) ? (string) $data['timestamp'] : null,
            application: isset($data['application']) && is_array($data['application']) ? $data['application'] : [],
            environment: isset($data['environment']) && is_array($data['environment']) ? $data['environment'] : [],
            changes: isset($data['changes']) && is_array($data['changes']) ? array_values($data['changes']) : [],
            values: isset($data['values']) && is_array($data['values']) ? $data['values'] : null,
        );
    }
}
