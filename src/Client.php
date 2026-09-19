<?php

declare(strict_types=1);

namespace ProxyMailer;

use ProxyMailer\Exception\ValidationException;
use ProxyMailer\Http\HttpClient;
use ProxyMailer\Http\RetryPolicy;

/**
 * Official ProxyMailer PHP SDK client.
 *
 * Authenticate with a workspace API key (`pm_live_…`) and send through
 * `POST /api/v1/send` (alias: `/api/v1/email`).
 */
final class Client
{
    public const VERSION = '1.0.0';

    private readonly string $baseUrl;

    private readonly string $apiKey;

    private readonly HttpClient $http;

    /**
     * @param  string  $apiKey  Application API key (`pm_live_…`)
     * @param  string  $baseUrl  ProxyMailer base URL (no trailing slash)
     */
    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://mail.example.com',
        float $timeout = 30.0,
        int $maxRetries = 3,
        ?HttpClient $httpClient = null,
        ?string $userAgent = null,
    ) {
        $apiKey = trim($apiKey);
        if ($apiKey === '' || ! str_starts_with($apiKey, 'pm_live_')) {
            throw new \InvalidArgumentException('API key must be a ProxyMailer key starting with pm_live_.');
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->http = $httpClient ?? new HttpClient(
            $this->baseUrl,
            $this->apiKey,
            $timeout,
            new RetryPolicy($maxRetries),
            userAgent: $userAgent ?? ('proxymailer-php/'.self::VERSION),
        );
    }

    /**
     * Send an email through ProxyMailer.
     *
     * @param  array{
     *     from: string|array{email?: string, address?: string, name?: string},
     *     to: string|list<string|array{email?: string, address?: string, name?: string}>,
     *     subject?: string|null,
     *     text?: string|null,
     *     html?: string|null,
     *     cc?: string|list<string|array{email?: string, address?: string, name?: string}>|null,
     *     bcc?: string|list<string|array{email?: string, address?: string, name?: string}>|null,
     *     stream?: string|null,
     *     meta?: array<string, mixed>|null,
     *     sync?: bool|null,
     *     attachments?: list<array{filename?: string, name?: string, content_type?: string, type?: string, content?: string, content_base64?: string}>|null
     * }  $message
     */
    public function send(array $message): SendResult
    {
        $payload = $this->normalizeMessage($message);
        $response = $this->http->postJson('/api/v1/send', $payload);

        return SendResult::fromResponse($response['body'], $response['status']);
    }

    /**
     * List email groups for this API key's organization (read-only).
     *
     * @return array{data: list<array<string,mixed>>, meta: array<string,mixed>}
     */
    public function listGroups(): array
    {
        $response = $this->http->getJson('/api/v1/groups');

        return $response['body'];
    }

    /**
     * Fetch one email group (including resolved recipients).
     *
     * @return array<string,mixed>
     */
    public function getGroup(int $groupId): array
    {
        $response = $this->http->getJson('/api/v1/groups/'.$groupId);

        /** @var array<string,mixed> $data */
        $data = $response['body']['data'] ?? $response['body'];

        return $data;
    }

    /**
     * Fetch the tenant group/alias mapping graph for mail-client visualization.
     *
     * @return array{data: array{nodes: list<array<string,mixed>>, edges: list<array<string,mixed>>}, meta: array<string,int>}
     */
    public function getMappings(): array
    {
        $response = $this->http->getJson('/api/v1/mappings');

        return $response['body'];
    }

    /**
     * List approved senders this application registered (requires admin opt-in).
     *
     * @return array{data: list<array<string,mixed>>, meta: array<string,mixed>}
     */
    public function listApprovedSenders(int $page = 1, int $perPage = 50): array
    {
        $query = http_build_query([
            'page' => max(1, $page),
            'per_page' => min(100, max(1, $perPage)),
        ]);
        $response = $this->http->getJson('/api/v1/approved-senders?'.$query);

        return $response['body'];
    }

    /**
     * @return array<string,mixed>
     */
    public function getApprovedSender(int $senderId): array
    {
        $response = $this->http->getJson('/api/v1/approved-senders/'.$senderId);

        /** @var array<string,mixed> $data */
        $data = $response['body']['data'] ?? $response['body'];

        return $data;
    }

    /**
     * Register an approved sender owned by this application.
     *
     * @param  array{email: string, display_name?: string|null, sending_domain_id?: int|null}  $payload
     * @return array<string,mixed>
     */
    public function createApprovedSender(array $payload): array
    {
        $response = $this->http->postJson('/api/v1/approved-senders', $payload);

        /** @var array<string,mixed> $data */
        $data = $response['body']['data'] ?? $response['body'];

        return $data;
    }

    /**
     * @param  array{display_name?: string|null, status?: string, sending_domain_id?: int|null}  $payload
     * @return array<string,mixed>
     */
    public function updateApprovedSender(int $senderId, array $payload): array
    {
        $response = $this->http->patchJson('/api/v1/approved-senders/'.$senderId, $payload);

        /** @var array<string,mixed> $data */
        $data = $response['body']['data'] ?? $response['body'];

        return $data;
    }

    /**
     * @return array<string,mixed>
     */
    public function deleteApprovedSender(int $senderId): array
    {
        $response = $this->http->deleteJson('/api/v1/approved-senders/'.$senderId);

        /** @var array<string,mixed> $data */
        $data = $response['body']['data'] ?? $response['body'];

        return $data;
    }

    /**
     * Build an attachment array from raw bytes (base64-encoded for the API).
     *
     * @return array{filename: string, content_type: string, content: string}
     */
    public static function attachmentFromBytes(string $filename, string $bytes, string $contentType = 'application/octet-stream'): array
    {
        return [
            'filename' => $filename,
            'content_type' => $contentType,
            'content' => base64_encode($bytes),
        ];
    }

    /**
     * Build an attachment array from a local file path.
     *
     * @return array{filename: string, content_type: string, content: string}
     */
    public static function attachmentFromPath(string $path, ?string $filename = null, ?string $contentType = null): array
    {
        if (! is_readable($path)) {
            throw new \InvalidArgumentException("Attachment path is not readable: {$path}");
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \InvalidArgumentException("Unable to read attachment: {$path}");
        }

        $detected = function_exists('mime_content_type') ? mime_content_type($path) : false;

        return self::attachmentFromBytes(
            $filename ?? basename($path),
            $bytes,
            $contentType ?? ($detected !== false ? $detected : 'application/octet-stream'),
        );
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function normalizeMessage(array $message): array
    {
        if (! isset($message['from']) || $message['from'] === '' || $message['from'] === []) {
            throw new ValidationException('`from` is required.');
        }
        if (! isset($message['to']) || $message['to'] === '' || $message['to'] === []) {
            throw new ValidationException('`to` is required.');
        }

        $payload = [
            'from' => $message['from'],
            'to' => $message['to'],
            'stream' => $message['stream'] ?? 'transactional',
        ];

        foreach (['subject', 'text', 'html'] as $field) {
            if (array_key_exists($field, $message) && $message[$field] !== null) {
                $payload[$field] = $message[$field];
            }
        }

        foreach (['cc', 'bcc', 'meta', 'attachments'] as $field) {
            if (! empty($message[$field])) {
                $payload[$field] = $message[$field];
            }
        }

        if (array_key_exists('sync', $message) && $message['sync'] !== null) {
            $payload['sync'] = (bool) $message['sync'];
        }

        return $payload;
    }
}
