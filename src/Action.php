<?php

declare(strict_types=1);

namespace Confish;

final class Action
{
    /**
     * @param  array<string, mixed>|null  $params
     * @param  list<array<string, mixed>>  $updates
     * @param  array<string, mixed>|null  $result
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly ActionStatus $status,
        public readonly ?array $params = null,
        public readonly array $updates = [],
        public readonly ?array $result = null,
        public readonly ?string $expiresAt = null,
        public readonly ?string $acknowledgedAt = null,
        public readonly ?string $completedAt = null,
        public readonly ?string $createdAt = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            type: (string) $data['type'],
            status: ActionStatus::from((string) $data['status']),
            params: isset($data['params']) && is_array($data['params']) ? $data['params'] : null,
            updates: isset($data['updates']) && is_array($data['updates']) ? array_values($data['updates']) : [],
            result: isset($data['result']) && is_array($data['result']) ? $data['result'] : null,
            expiresAt: isset($data['expires_at']) ? (string) $data['expires_at'] : null,
            acknowledgedAt: isset($data['acknowledged_at']) ? (string) $data['acknowledged_at'] : null,
            completedAt: isset($data['completed_at']) ? (string) $data['completed_at'] : null,
            createdAt: isset($data['created_at']) ? (string) $data['created_at'] : null,
        );
    }
}
