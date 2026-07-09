<?php

declare(strict_types=1);

namespace Confish;

use GuzzleHttp\Client as GuzzleClient;

final class Confish
{
    public const DEFAULT_BASE_URL = 'https://confi.sh';

    public readonly Config $config;
    public readonly Actions $actions;
    public readonly Logs $logs;
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
        $this->config = new Config($this->http, $envId);
        $this->actions = new Actions($this->http, $envId);
        $this->logs = new Logs($this->http, $envId);
    }

    /**
     * Returns a handle bound to the feed with the given slug.
     * No HTTP request is made until a method is called on the handle.
     */
    public function feed(string $slug): Feed
    {
        return new Feed($this->http, $this->envId, $slug);
    }
}
