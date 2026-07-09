<?php

declare(strict_types=1);

namespace Confish;

/** Outcome of a whole-partition {@see Feed::replace()}. */
final class FeedReplaceResult
{
    public function __construct(
        public readonly int $created,
        public readonly int $updated,
        public readonly int $deleted,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            created: (int) ($data['created'] ?? 0),
            updated: (int) ($data['updated'] ?? 0),
            deleted: (int) ($data['deleted'] ?? 0),
        );
    }
}
