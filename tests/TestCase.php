<?php

declare(strict_types=1);

namespace Confish\Tests;

use Confish\Confish;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** @var list<Request> */
    protected array $recorded = [];

    /**
     * @param  list<Response>  $responses
     */
    protected function makeClient(array $responses, int $maxRetries = 1): Confish
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $this->recorded = [];
        $stack->push(Middleware::history($this->recorded));
        $http = new GuzzleClient(['handler' => $stack]);

        return new Confish(
            envId: 'env_test',
            apiKey: 'confish_sk_test',
            baseUrl: 'https://api.test',
            httpClient: $http,
            maxRetries: $maxRetries,
            maxRetryDelay: 0.001,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function bodyOf(int $index): array
    {
        $request = $this->recorded[$index]['request'] ?? null;
        if (! $request instanceof Request) {
            $this->fail("No recorded request at index $index");
        }
        $contents = (string) $request->getBody();
        if ($contents === '') {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
