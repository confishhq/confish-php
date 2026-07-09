<?php

declare(strict_types=1);

namespace Confish;

final class FeedItem
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $id,
        public readonly string $externalId,
        public readonly array $data = [],
        public readonly ?string $expiresAt = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            externalId: (string) $data['external_id'],
            data: isset($data['data']) && is_array($data['data']) ? $data['data'] : [],
            expiresAt: isset($data['expires_at']) ? (string) $data['expires_at'] : null,
            createdAt: isset($data['created_at']) ? (string) $data['created_at'] : null,
            updatedAt: isset($data['updated_at']) ? (string) $data['updated_at'] : null,
        );
    }
}
