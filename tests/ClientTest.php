<?php

declare(strict_types=1);

namespace ProxyMailer\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use ProxyMailer\Client;
use ProxyMailer\Exception\ApiException;
use ProxyMailer\Exception\AuthenticationException;
use ProxyMailer\Exception\RateLimitException;
use ProxyMailer\Exception\ValidationException;
use ProxyMailer\Http\HttpClient;
use ProxyMailer\Http\RetryPolicy;
use Psr\Http\Message\RequestInterface;

final class ClientTest extends TestCase
{
    private const KEY = 'pm_live_abcdefghijklmnopqrstuvwxyz0123456789ab';

    public function test_send_success_maps_result(): void
    {
        $mock = new MockClient;
        $mock->addResponse(new Response(202, ['Content-Type' => 'application/json'], json_encode([
            'id' => '11111111-1111-1111-1111-111111111111',
            'status' => 'queued',
            'provider_message_id' => null,
            'attachment_count' => 0,
        ], JSON_THROW_ON_ERROR)));

        $client = $this->clientWith($mock);
        $result = $client->send([
            'from' => 'noreply@acme.test',
            'to' => ['user@example.com'],
            'subject' => 'Hello',
            'text' => 'Hi',
        ]);

        $this->assertTrue($result->isQueued());
        $this->assertSame(202, $result->httpStatus);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $result->id);
        $this->assertNull($result->providerMessageId);
        $this->assertSame(0, $result->attachmentCount);
        $request = $mock->getLastRequest();
        $this->assertSame('Bearer '.self::KEY, $request->getHeaderLine('Authorization'));
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/api/v1/send', $request->getUri()->getPath());
        $this->assertStringContainsString('proxymailer-php/', $request->getHeaderLine('User-Agent'));
        $this->assertStringContainsString('proxymailer-php/', $request->getHeaderLine('X-ProxyMailer-Client'));
        $this->assertNotSame('', $request->getHeaderLine('X-Request-Id'));
    }

    public function test_auth_error_throws(): void
    {
        $mock = new MockClient;
        $mock->addResponse(new Response(401, [], json_encode(['message' => 'Valid application API key required.'], JSON_THROW_ON_ERROR)));
        $client = $this->clientWith($mock);
        $this->expectException(AuthenticationException::class);
        $client->send(['from' => 'a@b.c', 'to' => ['c@d.e']]);
    }

    public function test_validation_error_exposes_fields(): void
    {
        $mock = new MockClient;
        $mock->addResponse(new Response(422, [], json_encode([
            'message' => 'The given data was invalid.',
            'errors' => ['from' => ['Unauthorized sender']],
        ], JSON_THROW_ON_ERROR)));
        $client = $this->clientWith($mock);

        try {
            $client->send(['from' => 'bad@acme.test', 'to' => ['user@example.com']]);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('from', $e->errors);
        }
    }

    public function test_rejects_non_pm_live_keys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client('sk_test_123', 'https://proxymailer.wxp.app');
    }

    public function test_attachment_helpers(): void
    {
        $att = Client::attachmentFromBytes('note.txt', 'hello', 'text/plain');
        $this->assertSame('note.txt', $att['filename']);
        $this->assertSame('text/plain', $att['content_type']);
        $this->assertSame(base64_encode('hello'), $att['content']);

        $tmp = tempnam(sys_get_temp_dir(), 'pm');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'from-disk');
        try {
            $fromPath = Client::attachmentFromPath($tmp, 'disk.txt', 'text/plain');
            $this->assertSame('disk.txt', $fromPath['filename']);
            $this->assertSame(base64_encode('from-disk'), $fromPath['content']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_sync_failure_502_returns_result_without_retry(): void
    {
        $mock = new MockClient;
        $mock->addResponse(new Response(502, ['Content-Type' => 'application/json'], json_encode([
            'id' => '22222222-2222-2222-2222-222222222222',
            'status' => 'failed',
            'provider_message_id' => null,
            'attachment_count' => 1,
        ], JSON_THROW_ON_ERROR)));
        // Would be consumed if the SDK retried incorrectly.
        $mock->addResponse(new Response(202, [], json_encode([
            'id' => 'should-not-be-used',
            'status' => 'queued',
        ], JSON_THROW_ON_ERROR)));

        $client = $this->clientWith($mock, maxRetries: 3);
        $result = $client->send([
            'from' => 'noreply@acme.test',
            'to' => ['user@example.com'],
            'sync' => true,
        ]);

        $this->assertTrue($result->isFailed());
        $this->assertSame(502, $result->httpStatus);
        $this->assertSame(1, $result->attachmentCount);
        $this->assertCount(1, $mock->getRequests());
    }

    public function test_rate_limit_honors_retry_after_then_succeeds(): void
    {
        $mock = new MockClient;
        $mock->addResponse(new Response(429, [
            'Content-Type' => 'application/json',
            'Retry-After' => '0',
        ], json_encode(['message' => 'Too many requests'], JSON_THROW_ON_ERROR)));
        $mock->addResponse(new Response(202, ['Content-Type' => 'application/json'], json_encode([
            'id' => '33333333-3333-3333-3333-333333333333',
            'status' => 'queued',
            'attachment_count' => 0,
        ], JSON_THROW_ON_ERROR)));

        $client = $this->clientWith($mock, maxRetries: 2);
        $result = $client->send([
            'from' => 'noreply@acme.test',
            'to' => ['user@example.com'],
        ]);

        $this->assertTrue($result->isQueued());
        $this->assertCount(2, $mock->getRequests());
    }

    public function test_rate_limit_exhausted_throws(): void
    {
        $mock = new MockClient;
        $mock->addResponse(new Response(429, [
            'Retry-After' => '5',
        ], json_encode(['message' => 'Slow down'], JSON_THROW_ON_ERROR)));

        $client = $this->clientWith($mock, maxRetries: 0);

        try {
            $client->send(['from' => 'a@b.c', 'to' => ['c@d.e']]);
            $this->fail('expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(5, $e->retryAfterSeconds);
            $this->assertSame(429, $e->statusCode);
        }
    }

    public function test_transient_5xx_retries_then_maps_success(): void
    {
        $mock = new MockClient;
        $mock->addResponse(new Response(503, [], json_encode(['message' => 'Unavailable'], JSON_THROW_ON_ERROR)));
        $mock->addResponse(new Response(202, [], json_encode([
            'id' => '44444444-4444-4444-4444-444444444444',
            'status' => 'queued',
        ], JSON_THROW_ON_ERROR)));

        $client = $this->clientWith($mock, maxRetries: 2);
        $result = $client->send([
            'from' => 'noreply@acme.test',
            'to' => ['user@example.com'],
        ]);

        $this->assertSame('queued', $result->status);
        $this->assertCount(2, $mock->getRequests());
    }

    public function test_transport_failure_throws_api_exception(): void
    {
        $mock = new MockClient;
        $mock->addException(new \RuntimeException('connection reset'));

        $client = $this->clientWith($mock, maxRetries: 0);
        $this->expectException(ApiException::class);
        $client->send(['from' => 'a@b.c', 'to' => ['c@d.e']]);
    }

    public function test_payload_field_names_match_api_contract(): void
    {
        $mock = new MockClient;
        $mock->addResponse(new Response(202, [], json_encode([
            'id' => '55555555-5555-5555-5555-555555555555',
            'status' => 'queued',
            'provider_message_id' => 'prov-1',
            'attachment_count' => 1,
        ], JSON_THROW_ON_ERROR)));

        $client = $this->clientWith($mock);
        $result = $client->send([
            'from' => ['email' => 'noreply@acme.test', 'name' => 'Acme'],
            'to' => ['user@example.com'],
            'cc' => ['cc@example.com'],
            'bcc' => ['bcc@example.com'],
            'subject' => 'Subj',
            'text' => 'Text',
            'html' => '<p>Hi</p>',
            'stream' => 'marketing',
            'meta' => ['campaign' => 'welcome'],
            'sync' => false,
            'attachments' => [
                Client::attachmentFromBytes('a.txt', 'x', 'text/plain'),
            ],
        ]);

        /** @var RequestInterface $request */
        $request = $mock->getLastRequest();
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        foreach (['from', 'to', 'cc', 'bcc', 'subject', 'text', 'html', 'stream', 'meta', 'sync', 'attachments'] as $field) {
            $this->assertArrayHasKey($field, $body);
        }
        $this->assertSame('prov-1', $result->providerMessageId);
        $this->assertSame(1, $result->attachmentCount);
    }

    public function test_default_http_client_applies_timeout_via_guzzle(): void
    {
        $http = new HttpClient(
            'https://proxymailer.wxp.app',
            self::KEY,
            12.5,
            new RetryPolicy(0),
        );

        $ref = new \ReflectionClass($http);
        $prop = $ref->getProperty('client');
        $client = $prop->getValue($http);

        $this->assertInstanceOf(GuzzleClient::class, $client);
        $this->assertSame(12.5, $client->getConfig('timeout'));
    }

    public function test_approved_sender_helpers_unwrap_data(): void
    {
        $mock = new MockClient;
        $mock->addResponse(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => [['id' => 1, 'email' => 'a@acme.test', 'owned_by_application' => true]],
            'meta' => ['count' => 1, 'total' => 1, 'page' => 1, 'per_page' => 50, 'can_manage_approved_senders' => true],
        ], JSON_THROW_ON_ERROR)));
        $mock->addResponse(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => ['id' => 1, 'email' => 'a@acme.test', 'owned_by_application' => true, 'status' => 'active'],
        ], JSON_THROW_ON_ERROR)));
        $mock->addResponse(new Response(201, ['Content-Type' => 'application/json'], json_encode([
            'data' => ['id' => 9, 'email' => 'b@acme.test', 'owned_by_application' => true, 'status' => 'active'],
        ], JSON_THROW_ON_ERROR)));
        $mock->addResponse(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => ['id' => 9, 'email' => 'b@acme.test', 'status' => 'disabled', 'owned_by_application' => true],
        ], JSON_THROW_ON_ERROR)));
        $mock->addResponse(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => ['id' => 9, 'email' => 'b@acme.test', 'deleted' => true],
        ], JSON_THROW_ON_ERROR)));

        $client = $this->clientWith($mock);

        $list = $client->listApprovedSenders(1, 50);
        $this->assertArrayHasKey('data', $list);
        $this->assertSame('/api/v1/approved-senders', $mock->getRequests()[0]->getUri()->getPath());
        $this->assertSame('page=1&per_page=50', $mock->getRequests()[0]->getUri()->getQuery());

        $got = $client->getApprovedSender(1);
        $this->assertSame(1, $got['id']);
        $this->assertSame('/api/v1/approved-senders/1', $mock->getRequests()[1]->getUri()->getPath());

        $created = $client->createApprovedSender(['email' => 'b@acme.test']);
        $this->assertSame(9, $created['id']);
        $this->assertSame('POST', $mock->getRequests()[2]->getMethod());

        $updated = $client->updateApprovedSender(9, ['status' => 'disabled']);
        $this->assertSame('disabled', $updated['status']);
        $this->assertSame('PATCH', $mock->getRequests()[3]->getMethod());
        $this->assertSame('/api/v1/approved-senders/9', $mock->getRequests()[3]->getUri()->getPath());

        $deleted = $client->deleteApprovedSender(9);
        $this->assertTrue($deleted['deleted']);
        $this->assertSame('DELETE', $mock->getRequests()[4]->getMethod());
    }

    public function test_list_approved_senders_clamps_pagination(): void
    {
        $mock = new MockClient;
        $mock->addResponse(new Response(200, [], json_encode([
            'data' => [],
            'meta' => ['count' => 0, 'total' => 0, 'page' => 1, 'per_page' => 100],
        ], JSON_THROW_ON_ERROR)));

        $client = $this->clientWith($mock);
        $client->listApprovedSenders(0, 500);

        $this->assertSame('page=1&per_page=100', $mock->getLastRequest()->getUri()->getQuery());
    }

    public function test_groups_and_mappings_helpers(): void
    {
        $mock = new MockClient;
        $mock->addResponse(new Response(200, [], json_encode([
            'data' => [['id' => 12, 'name' => 'DevOps', 'identifier_email' => 'devops@acme.test']],
            'meta' => ['count' => 1],
        ], JSON_THROW_ON_ERROR)));
        $mock->addResponse(new Response(200, [], json_encode([
            'data' => [
                'id' => 12,
                'name' => 'DevOps',
                'identifier_email' => 'devops@acme.test',
                'resolved_recipients' => ['a@acme.test'],
            ],
        ], JSON_THROW_ON_ERROR)));
        $mock->addResponse(new Response(200, [], json_encode([
            'data' => [
                'nodes' => [['id' => 'group:12', 'type' => 'group', 'label' => 'DevOps']],
                'edges' => [['id' => 'group:12->mailbox:a@acme.test', 'source' => 'group:12', 'target' => 'mailbox:a@acme.test', 'kind' => 'member']],
            ],
            'meta' => ['group_count' => 1, 'alias_count' => 0, 'mailbox_count' => 1, 'edge_count' => 1],
        ], JSON_THROW_ON_ERROR)));

        $client = $this->clientWith($mock);

        $list = $client->listGroups();
        $this->assertSame(1, $list['meta']['count']);
        $this->assertSame('/api/v1/groups', $mock->getRequests()[0]->getUri()->getPath());

        $group = $client->getGroup(12);
        $this->assertSame(12, $group['id']);
        $this->assertSame(['a@acme.test'], $group['resolved_recipients']);
        $this->assertSame('/api/v1/groups/12', $mock->getRequests()[1]->getUri()->getPath());

        $graph = $client->getMappings();
        $this->assertSame('group:12', $graph['data']['nodes'][0]['id']);
        $this->assertSame('/api/v1/mappings', $mock->getRequests()[2]->getUri()->getPath());
    }

    public function test_client_side_validation_requires_from_and_to(): void
    {
        $mock = new MockClient;
        $client = $this->clientWith($mock);

        try {
            $client->send(['to' => ['user@example.com']]);
            $this->fail('expected ValidationException for missing from');
        } catch (ValidationException $e) {
            $this->assertSame('`from` is required.', $e->getMessage());
        }

        try {
            $client->send(['from' => 'a@b.c', 'to' => []]);
            $this->fail('expected ValidationException for empty to');
        } catch (ValidationException $e) {
            $this->assertSame('`to` is required.', $e->getMessage());
        }

        $this->assertCount(0, $mock->getRequests());
    }

    public function test_generic_502_is_retried_then_throws_api_exception(): void
    {
        $mock = new MockClient;
        $mock->addResponse(new Response(502, [], json_encode(['message' => 'bad gateway'], JSON_THROW_ON_ERROR)));
        $mock->addResponse(new Response(502, [], json_encode(['message' => 'bad gateway'], JSON_THROW_ON_ERROR)));

        $client = $this->clientWith($mock, maxRetries: 1);

        try {
            $client->send(['from' => 'a@b.c', 'to' => ['c@d.e']]);
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(502, $e->statusCode);
            $this->assertSame('bad gateway', $e->getMessage());
        }

        $this->assertCount(2, $mock->getRequests());
    }

    public function test_non_json_error_body_still_maps_status_exceptions(): void
    {
        $mock = new MockClient;
        $mock->addResponse(new Response(401, ['Content-Type' => 'text/plain'], 'not-json-auth-failure'));

        $client = $this->clientWith($mock, maxRetries: 0);
        $this->expectException(AuthenticationException::class);
        $client->send(['from' => 'a@b.c', 'to' => ['c@d.e']]);
    }

    public function test_json_array_body_is_treated_as_message_payload(): void
    {
        $mock = new MockClient;
        $mock->addResponse(new Response(500, [], '[1,2,3]'));

        $client = $this->clientWith($mock, maxRetries: 0);

        try {
            $client->send(['from' => 'a@b.c', 'to' => ['c@d.e']]);
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(500, $e->statusCode);
            $this->assertSame('[1,2,3]', $e->getMessage());
        }
    }

    public function test_empty_json_object_body_is_accepted_as_object(): void
    {
        $mock = new MockClient;
        $mock->addResponse(new Response(202, ['Content-Type' => 'application/json'], '{}'));

        $client = $this->clientWith($mock);
        $result = $client->send(['from' => 'a@b.c', 'to' => ['c@d.e']]);

        $this->assertSame('', $result->id);
        $this->assertSame('unknown', $result->status);
        $this->assertSame(202, $result->httpStatus);
    }

    public function test_attachment_from_path_guesses_pdf_mime_like_node(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pm');
        $this->assertNotFalse($tmp);
        $pdf = $tmp.'.pdf';
        rename($tmp, $pdf);
        file_put_contents($pdf, '%PDF-1.4 fake');
        try {
            $att = Client::attachmentFromPath($pdf);
            $this->assertSame(basename($pdf), $att['filename']);
            $this->assertSame('application/pdf', $att['content_type']);
        } finally {
            @unlink($pdf);
        }
    }

    private function clientWith(MockClient $mock, int $maxRetries = 0): Client
    {
        $factory = new Psr17Factory;
        $http = new HttpClient(
            'https://proxymailer.wxp.app',
            self::KEY,
            5.0,
            new RetryPolicy($maxRetries),
            $mock,
            $factory,
            $factory,
        );

        return new Client(self::KEY, 'https://proxymailer.wxp.app', httpClient: $http, maxRetries: $maxRetries);
    }
}
