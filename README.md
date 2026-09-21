# ProxyMailer PHP SDK

Official PHP client for the ProxyMailer application send API.

- **Package:** [`proxymailer/sdk`](https://packagist.org/packages/proxymailer/sdk)
- **Repository:** [webxspark-devs/proxymailer-sdk-php](https://github.com/webxspark-devs/proxymailer-sdk-php)
- **Production API origin:** `https://proxymailer.wxp.app`
- **Contract (OpenAPI):** published alongside the control plane at [`proxy-mailer/sdks/openapi.yaml`](https://github.com/webxspark-devs/proxy-mailer/blob/main/sdks/openapi.yaml)
- **Full developer guide:** [docs/developers](https://github.com/webxspark-devs/proxy-mailer/tree/main/docs/developers) (especially [sdks.md](https://github.com/webxspark-devs/proxy-mailer/blob/main/docs/developers/sdks.md))

ProxyMailer is a multi-tenant **BYOK email control plane**. Your application sends to ProxyMailer over HTTP; ProxyMailer delivers through organization-owned Generic SMTP / ESP credentials. You never put ESP secrets in every microservice.

---

## Requirements

| Item | Version / note |
| --- | --- |
| PHP | **^8.2** |
| Extensions | `json` (Guzzle typically needs `curl`) |
| HTTP | PSR-18 client — **install Guzzle** so the configured timeout is enforced |

---

## Install

```bash
composer require proxymailer/sdk
# Recommended so request timeouts actually apply:
composer require guzzlehttp/guzzle nyholm/psr7
```

When Guzzle is present, the SDK applies the configured timeout (default **30 seconds**).
If you inject a custom PSR-18 client, configure timeouts on that client yourself.

---

## Configuration

### Production (always)

```bash
export PROXYMAILER_API_KEY=pm_live_…
export PROXYMAILER_BASE_URL=https://proxymailer.wxp.app
```

| Variable | Required | Rules |
| --- | --- | --- |
| `PROXYMAILER_API_KEY` | yes | Must start with `pm_live_`. Create keys in the ProxyMailer dashboard → Applications. |
| `PROXYMAILER_BASE_URL` | strongly recommended | Origin only — **no** trailing slash, **no** `/api/v1` suffix. Production is always `https://proxymailer.wxp.app`. |

Local Docker Compose override only:

```bash
export PROXYMAILER_BASE_URL=http://localhost:8080
```

Never commit real API keys. Log key **prefix** only.

---

## Quick start

```php
<?php

use ProxyMailer\Client;
use ProxyMailer\Exception\AuthenticationException;
use ProxyMailer\Exception\ApiException;
use ProxyMailer\Exception\RateLimitException;
use ProxyMailer\Exception\ValidationException;

$client = new Client(
    apiKey: getenv('PROXYMAILER_API_KEY') ?: '',
    baseUrl: getenv('PROXYMAILER_BASE_URL') ?: 'https://proxymailer.wxp.app',
    timeout: 30.0,
    maxRetries: 3,
);

try {
    $result = $client->send([
        'from' => 'Acme <noreply@acme.com>',
        'to' => ['user@example.com'],
        'subject' => 'Welcome',
        'html' => '<p>Hello</p>',
        'text' => 'Hello',
        'stream' => 'transactional',
        'meta' => ['user_id' => '42'],
        'attachments' => [
            Client::attachmentFromPath('/tmp/invoice.pdf'),
        ],
    ]);
} catch (ValidationException $e) {
    // $e->errors → Laravel-style map ['field' => ['message', …]]
    throw $e;
} catch (AuthenticationException $e) {
    // 401 / 403 — fix credentials; do not blind-retry
    throw $e;
} catch (RateLimitException $e) {
    // SDK already retried using Retry-After when present
    throw $e;
} catch (ApiException $e) {
    throw $e;
}

// Always persist the message UUID for support / webhooks
echo $result->id;                 // UUID
echo $result->status;             // queued | sent | failed
echo $result->isQueued();         // bool
echo $result->providerMessageId;  // may be null on async accept
echo $result->attachmentCount;
echo $result->httpStatus;         // 202, or 502 on sync-failure body
```

---

## Client constructor

```php
new Client(
    string $apiKey,
    string $baseUrl = 'https://proxymailer.wxp.app',
    float $timeout = 30.0,
    int $maxRetries = 3,
    ?HttpClient $httpClient = null,
    ?string $userAgent = null,
);
```

| Parameter | Default | Behavior |
| --- | --- | --- |
| `apiKey` | — | Trimmed; must start with `pm_live_` or `\InvalidArgumentException` is thrown **before** any HTTP |
| `baseUrl` | `https://proxymailer.wxp.app` | Trailing `/` stripped; paths like `/api/v1/send` are appended by the SDK |
| `timeout` | `30.0` | Seconds (enforced when using the default Guzzle discovery path) |
| `maxRetries` | `3` | Retries for `408`/`425`/`429`/`5xx` and transport failures |
| `httpClient` | auto | Inject in unit tests |
| `userAgent` | `proxymailer-php/{VERSION}` | Also sent as `X-ProxyMailer-Client` |

Every request sets:

- `Authorization: Bearer {apiKey}`
- `Accept: application/json`
- `Content-Type: application/json` on bodies
- `User-Agent` / `X-ProxyMailer-Client`
- `X-Request-Id` (16 hex chars) per attempt

---

## `send()` payload

| Field | Required | Description |
| --- | --- | --- |
| `from` | **yes** | `email`, `Name <email>`, or `{email\|address, name?}` — must be permitted for the app |
| `to` | **yes** | string, CSV, or list of addresses (may include group/alias identifiers) |
| `cc` / `bcc` | no | Same shapes as `to` |
| `subject` | no | Max 998 characters; group fan-out may append ` via {Group Name}` |
| `text` / `html` | no | Bodies (prefer at least one) |
| `stream` | no | Defaults to **`transactional`** inside the SDK if omitted |
| `meta` | no | Arbitrary JSON object for your correlation IDs |
| `sync` | no | `true` waits for a provider delivery attempt on this HTTP call |
| `attachments` | no | Max **10**; use helpers below |

### Streams

| Examples | Class | Queue | Weight |
| --- | --- | --- | --- |
| `transactional`, `otp`, `auth`, `alert`, `notification`, `receipt` | high | `mail-high` | 4 |
| `invoice`, `lifecycle`, `onboarding`, unmapped | normal | `mail-normal` | 2 |
| `marketing`, `newsletter`, `bulk`, `campaign`, `blast` | bulk | `mail-bulk` | 1 |

### Response handling

- Async default → HTTP **202**, `status: queued` → `SendResult` with `isQueued() === true`
- Sync success → **202**, `status: sent`
- Sync provider failure after accept → HTTP **502** with `{id,status:failed,…}` → still a `SendResult` with `isFailed() === true` (**not** retried as transport)
- There is **no** public `GET /messages/{id}` — use dashboard / webhooks / `sync`

---

## Attachments

```php
Client::attachmentFromBytes('note.txt', 'hello', 'text/plain');
Client::attachmentFromPath('/tmp/invoice.pdf'); // filename + MIME guessed when possible
```

Helpers always emit Base64 in `content` (no `data:` prefix). Limits and wire format:
see control-plane [attachments docs](https://github.com/webxspark-devs/proxy-mailer/blob/main/docs/developers/attachments.md).

---

## Groups & mapping graph (read-only)

Scoped to the organization bound to the API key. Cross-tenant IDs → **404**.

```php
$groups = $client->listGroups();  // ['data' => [...], 'meta' => ['count' => N]]
$group  = $client->getGroup(12);  // includes resolved_recipients when available
$graph  = $client->getMappings(); // ['data' => ['nodes' => ..., 'edges' => ...], 'meta' => ...]
```

Edges use `source` / `target` node ids (`group:…`, `alias:…`, `mailbox:…`) for React Flow / Cytoscape / D3 with no remapping.

Application keys **cannot** create or mutate groups/aliases (dashboard only).

---

## Approved senders (opt-in)

Requires **Applications → Allow API to manage approved senders**. Without the flag → **403**.
Apps may only manage senders they created.

```php
$client->listApprovedSenders(page: 1, perPage: 50);
$client->getApprovedSender(9);
$client->createApprovedSender([
    'email' => 'user@acme.com',
    'display_name' => 'User',
    // 'sending_domain_id' => 12,
]);
$client->updateApprovedSender(9, ['status' => 'disabled']); // email is immutable
$client->deleteApprovedSender(9);
```

---

## Errors & retries

| HTTP | Exception | Retried? |
| --- | --- | --- |
| `401` / `403` | `AuthenticationException` | No |
| `422` | `ValidationException` (`$errors`) | No |
| `429` | `RateLimitException` | Yes (`Retry-After`) |
| `408` / `425` / `500` / `503` / `504` | `ApiException` | Yes |
| `502` with send body `{id,status}` | *(SendResult failed)* | **No** |
| Other `502` / network / timeout | `ApiException` | Yes |

Backoff: ~250ms × 2^attempt + up to 25% jitter, capped ~8s. Default max retries: **3**.

Base class: `ProxyMailer\Exception\ProxyMailerException`.

---

## Testing this repository

```bash
composer install
composer test
```

---

## Versioning & publishing

SemVer. Breaking HTTP contract changes should bump major together with the Node and Python SDKs and the OpenAPI document in the control-plane repo.

Packagist updates are driven by tags / CI on **this** repository (not a monorepo subtree split). Maintainer checklist:
[publishing.md](https://github.com/webxspark-devs/proxy-mailer/blob/main/docs/developers/publishing.md).

---

## Related control-plane docs

- [Authentication](https://github.com/webxspark-devs/proxy-mailer/blob/main/docs/developers/authentication.md)
- [Sending](https://github.com/webxspark-devs/proxy-mailer/blob/main/docs/developers/sending.md)
- [Groups & mappings](https://github.com/webxspark-devs/proxy-mailer/blob/main/docs/developers/groups.md)
- [Approved senders](https://github.com/webxspark-devs/proxy-mailer/blob/main/docs/developers/approved-senders.md)
- [Errors](https://github.com/webxspark-devs/proxy-mailer/blob/main/docs/developers/errors.md)
- [MCP](https://github.com/webxspark-devs/proxy-mailer/blob/main/docs/developers/mcp.md)
