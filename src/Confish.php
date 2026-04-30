<?php

declare(strict_types=1);

namespace Confish;

use GuzzleHttp\Client as GuzzleClient;

final class Confish
{
    public const DEFAULT_BASE_URL = 'https://confi.sh';

    public readonly Actions $actions;
    public readonly Logger $logger;
    private readonly HttpClient $http;
    private readonly string $envId;

    public function __construct(
        string $envId,
        string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?GuzzleClient $httpClient = null,
        string $userAgent = 'confish-php',
        int $maxRetries = 2,
        float $maxRetryDelay = 30.0,
    ) {
        if ($envId === '') {
            throw new \InvalidArgumentException('envId is required');
        }
        if ($apiKey === '') {
            throw new \InvalidArgumentException('apiKey is required');
        }

        $this->envId = $envId;
        $this->http = new HttpClient(
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            client: $httpClient ?? new GuzzleClient(['timeout' => 30.0]),
            userAgent: $userAgent,
            maxRetries: $maxRetries,
            maxRetryDelay: $maxRetryDelay,
        );
        $this->actions = new Actions($this->http, $envId);
        $this->logger = new Logger($this);
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

    /**
     * @param  array<string, mixed>|null  $context
     */
    public function log(LogLevel $level, string $message, ?array $context = null): string
    {
        $body = ['level' => $level->value, 'message' => $message];
        if ($context !== null) {
            $body['context'] = $context;
        }
        $response = $this->http->request('POST', "/c/{$this->envId}/log", $body) ?? [];

        return (string) ($response['id'] ?? '');
    }
}
