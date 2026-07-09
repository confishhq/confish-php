<?php

declare(strict_types=1);

namespace Confish;

use Confish\Exception\AuthException;
use Confish\Exception\ConflictException;
use Confish\Exception\ConfishException;
use Confish\Exception\ForbiddenException;
use Confish\Exception\NetworkException;
use Confish\Exception\NotFoundException;
use Confish\Exception\RateLimitException;
use Confish\Exception\ServerException;
use Confish\Exception\ValidationException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal
 */
final class HttpClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly GuzzleClient $client,
        private readonly string $userAgent,
        private readonly int $maxRetries,
        private readonly float $maxRetryDelay,
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>|null
     */
    public function request(string $method, string $path, ?array $body = null): ?array
    {
        $url = rtrim($this->baseUrl, '/').$path;
        $headers = [
            'Authorization' => 'Bearer '.$this->apiKey,
            'Accept'        => 'application/json',
            'User-Agent'    => $this->userAgent,
        ];
        $rawBody = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $rawBody = json_encode($body, JSON_THROW_ON_ERROR);
        }

        $attempt = 0;
        while (true) {
            try {
                $request = new Request($method, $url, $headers, $rawBody);
                $response = $this->client->send($request, ['http_errors' => false]);
            } catch (ConnectException $e) {
                throw new NetworkException("Network request to $url failed: ".$e->getMessage(), null, null, $e);
            } catch (GuzzleException $e) {
                throw new NetworkException("Request to $url failed: ".$e->getMessage(), null, null, $e);
            }

            $status = $response->getStatusCode();
            $contents = (string) $response->getBody();

            if ($status >= 200 && $status < 300) {
                if ($contents === '') {
                    return null;
                }
                try {
                    /** @var array<string, mixed> $decoded */
                    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

                    return $decoded;
                } catch (\JsonException $e) {
                    throw new ConfishException(
                        'Failed to parse response body as JSON',
                        $status,
                        $contents,
                        $e,
                    );
                }
            }

            $payload = $this->decodePayload($contents);
            $exception = $this->errorFromResponse($status, $payload, $response);

            if (! $this->shouldRetry($attempt, $exception)) {
                throw $exception;
            }

            usleep((int) ($this->retryDelay($attempt, $exception) * 1_000_000));
            $attempt++;
        }
    }

    /**
     * @return array<string, mixed>|string|null
     */
    private function decodePayload(string $contents): array|string|null
    {
        if ($contents === '') {
            return null;
        }
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : $contents;
        } catch (\JsonException) {
            return $contents;
        }
    }

    /**
     * @param  array<string, mixed>|string|null  $body
     */
    private function errorFromResponse(int $status, array|string|null $body, ResponseInterface $response): ConfishException
    {
        $message = $this->extractMessage($body, "Request failed ($status)");

        return match (true) {
            $status === 401                   => new AuthException($message, $status, $body),
            $status === 403                   => new ForbiddenException($message, $status, $body),
            $status === 404                   => new NotFoundException($message, $status, $body),
            $status === 409                   => new ConflictException($message, $status, $body),
            $status === 422                   => new ValidationException($message, $status, $body, $this->extractValidationErrors($body)),
            $status === 429                   => new RateLimitException(
                $message,
                $status,
                $body,
                $this->headerInt($response, 'Retry-After'),
                $this->headerInt($response, 'X-RateLimit-Limit'),
                $this->headerInt($response, 'X-RateLimit-Remaining'),
            ),
            $status >= 500                    => new ServerException($message, $status, $body),
            default                           => new ConfishException($message, $status, $body),
        };
    }

    /**
     * @param  array<string, mixed>|string|null  $body
     */
    private function extractMessage(array|string|null $body, string $fallback): string
    {
        if (is_array($body)) {
            foreach (['error', 'message'] as $key) {
                if (isset($body[$key]) && is_string($body[$key])) {
                    return $body[$key];
                }
            }
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>|string|null  $body
     * @return array<string, list<string>>
     */
    private function extractValidationErrors(array|string|null $body): array
    {
        if (is_array($body) && isset($body['errors']) && is_array($body['errors'])) {
            /** @var array<string, list<string>> $errors */
            $errors = $body['errors'];

            return $errors;
        }

        return [];
    }

    private function headerInt(ResponseInterface $response, string $name): ?int
    {
        $value = $response->getHeaderLine($name);
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function shouldRetry(int $attempt, ConfishException $exception): bool
    {
        if ($attempt >= $this->maxRetries) {
            return false;
        }

        return $exception instanceof RateLimitException || $exception instanceof ServerException;
    }

    private function retryDelay(int $attempt, ConfishException $exception): float
    {
        if ($exception instanceof RateLimitException && $exception->retryAfter !== null) {
            return min((float) $exception->retryAfter, $this->maxRetryDelay);
        }

        return min((float) (2 ** $attempt), $this->maxRetryDelay);
    }
}
