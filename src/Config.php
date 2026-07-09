<?php

declare(strict_types=1);

namespace Confish;

final class Config
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $envId,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function fetch(): array
    {
        return $this->http->request('GET', "/c/{$this->envId}") ?? [];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function update(array $values): array
    {
        return $this->http->request('PATCH', "/c/{$this->envId}", ['values' => $values]) ?? [];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function replace(array $values): array
    {
        return $this->http->request('PUT', "/c/{$this->envId}", ['values' => $values]) ?? [];
    }
}
