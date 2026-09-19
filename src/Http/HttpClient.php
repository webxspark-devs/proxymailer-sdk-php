<?php

declare(strict_types=1);

namespace ProxyMailer\Http;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use ProxyMailer\Exception\ApiException;
use ProxyMailer\Exception\AuthenticationException;
use ProxyMailer\Exception\ProxyMailerException;
use ProxyMailer\Exception\RateLimitException;
use ProxyMailer\Exception\ValidationException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** @internal */
final class HttpClient
{
    private readonly ClientInterface $client;

    private readonly RequestFactoryInterface $requestFactory;

    private readonly StreamFactoryInterface $streamFactory;

    private readonly string $userAgent;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly float $timeoutSeconds,
        private readonly RetryPolicy $retryPolicy,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?string $userAgent = null,
    ) {
        $this->client = $client ?? $this->createDefaultClient($timeoutSeconds);
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        $this->userAgent = $userAgent ?? 'proxymailer-php/1.0.0';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{status:int, body:array<string,mixed>, headers:array<string,list<string>>}
     */
    public function postJson(string $path, array $payload): array
    {
        return $this->requestJson('POST', $path, $payload);
    }

    /**
     * @return array{status:int, body:array<string,mixed>, headers:array<string,list<string>>}
     */
    public function getJson(string $path): array
    {
        return $this->requestJson('GET', $path, null);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{status:int, body:array<string,mixed>, headers:array<string,list<string>>}
     */
    public function putJson(string $path, array $payload): array
    {
        return $this->requestJson('PUT', $path, $payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{status:int, body:array<string,mixed>, headers:array<string,list<string>>}
     */
    public function patchJson(string $path, array $payload): array
    {
        return $this->requestJson('PATCH', $path, $payload);
    }

    /**
     * @return array{status:int, body:array<string,mixed>, headers:array<string,list<string>>}
     */
    public function deleteJson(string $path): array
    {
        return $this->requestJson('DELETE', $path, null);
    }

    /**
     * @param  array<string,mixed>|null  $payload
     * @return array{status:int, body:array<string,mixed>, headers:array<string,list<string>>}
     */
    private function requestJson(string $method, string $path, ?array $payload): array
    {
        $attempt = 0;

        while (true) {
            try {
                $response = $this->dispatch($method, $path, $payload);
                $status = $response['status'];

                if ($this->isTerminalSuccess($status, $response['body'])) {
                    return $response;
                }

                if ($this->retryPolicy->shouldRetry($attempt, $status) && ! $this->isSyncFailurePayload($status, $response['body'])) {
                    $attempt++;
                    $sleep = $status === 429
                        ? ($this->retryAfterSeconds($response['headers']) ?? $this->retryPolicy->delaySeconds($attempt))
                        : $this->retryPolicy->delaySeconds($attempt);
                    usleep((int) ($sleep * 1_000_000));

                    continue;
                }

                $this->throwForStatus($status, $response['body'], $response['headers']);

                return $response;
            } catch (RateLimitException $e) {
                if (! $this->retryPolicy->shouldRetry($attempt, 429)) {
                    throw $e;
                }
                $attempt++;
                $sleep = $e->retryAfterSeconds ?? $this->retryPolicy->delaySeconds($attempt);
                usleep((int) ($sleep * 1_000_000));
            } catch (ProxyMailerException $e) {
                throw $e;
            } catch (\Throwable $e) {
                if ($attempt >= $this->retryPolicy->maxRetries) {
                    throw new ApiException(
                        'Transport failure talking to ProxyMailer: '.$e->getMessage(),
                        0,
                        [],
                        $e,
                    );
                }
                $attempt++;
                usleep((int) ($this->retryPolicy->delaySeconds($attempt) * 1_000_000));
            }
        }
    }

    /**
     * @param  array<string,mixed>|null  $payload
     * @return array{status:int, body:array<string,mixed>, headers:array<string,list<string>>}
     */
    private function dispatch(string $method, string $path, ?array $payload): array
    {
        $uri = $this->baseUrl.$path;
        $requestId = bin2hex(random_bytes(8));

        $request = $this->requestFactory
            ->createRequest($method, $uri)
            ->withHeader('Authorization', 'Bearer '.$this->apiKey)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', $this->userAgent)
            ->withHeader('X-ProxyMailer-Client', $this->userAgent)
            ->withHeader('X-Request-Id', $requestId);

        if ($payload !== null) {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($json));
        }

        $psrResponse = $this->client->sendRequest($request);
        $raw = (string) $psrResponse->getBody();
        $decoded = $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            $decoded = ['message' => $raw];
        }

        /** @var array<string, list<string>> $headers */
        $headers = [];
        foreach ($psrResponse->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = $values;
        }

        return [
            'status' => $psrResponse->getStatusCode(),
            'body' => $decoded,
            'headers' => $headers,
        ];
    }

    /** @param  array<string,mixed>  $body */
    private function isTerminalSuccess(int $status, array $body): bool
    {
        if ($status >= 200 && $status < 300) {
            return true;
        }

        return $this->isSyncFailurePayload($status, $body);
    }

    /** @param  array<string,mixed>  $body */
    private function isSyncFailurePayload(int $status, array $body): bool
    {
        return $status === 502 && isset($body['id'], $body['status']);
    }

    /**
     * @param  array<string,mixed>  $body
     * @param  array<string, list<string>>  $headers
     */
    private function throwForStatus(int $status, array $body, array $headers): void
    {
        if ($this->isTerminalSuccess($status, $body)) {
            return;
        }

        $message = (string) ($body['message'] ?? 'ProxyMailer request failed');

        if ($status === 401 || $status === 403) {
            throw new AuthenticationException($message, $status, $body);
        }

        if ($status === 422) {
            /** @var array<string, list<string>> $errors */
            $errors = is_array($body['errors'] ?? null) ? $body['errors'] : [];
            throw new ValidationException($message, $errors, $status);
        }

        if ($status === 429) {
            throw new RateLimitException($message, $this->retryAfterSeconds($headers), $status);
        }

        throw new ApiException($message, $status, $body);
    }

    /** @param  array<string, list<string>>  $headers */
    private function retryAfterSeconds(array $headers): ?int
    {
        if (! isset($headers['retry-after'][0]) || ! is_numeric($headers['retry-after'][0])) {
            return null;
        }

        return (int) $headers['retry-after'][0];
    }

    private function createDefaultClient(float $timeoutSeconds): ClientInterface
    {
        // Prefer Guzzle when present so the configured timeout is actually enforced.
        // Injected PSR-18 clients are responsible for their own timeouts.
        if (class_exists(\GuzzleHttp\Client::class)) {
            return new \GuzzleHttp\Client([
                'timeout' => $timeoutSeconds,
                'connect_timeout' => min(10.0, $timeoutSeconds),
                'http_errors' => false,
            ]);
        }

        return Psr18ClientDiscovery::find();
    }
}
