# ProxyMailer PHP SDK

Official PHP client for the ProxyMailer application send API.

## Install

```bash
composer require proxymailer/sdk
# PSR-18 client for timeouts (recommended):
composer require guzzlehttp/guzzle nyholm/psr7
```

When Guzzle is installed, the SDK applies the configured request timeout (default 30s).
Injected PSR-18 clients must configure their own timeouts.

## Usage

```php
use ProxyMailer\Client;

$client = new Client(
    apiKey: getenv('PROXYMAILER_API_KEY'),
    baseUrl: getenv('PROXYMAILER_BASE_URL') ?: 'https://mail.example.com',
    timeout: 30.0,
    maxRetries: 3,
);

$result = $client->send([
    'from' => 'Acme <noreply@acme.com>',
    'to' => ['user@example.com'],
    'subject' => 'Welcome',
    'html' => '<p>Hello</p>',
    'text' => 'Hello',
    'attachments' => [
        Client::attachmentFromPath('/tmp/invoice.pdf'),
    ],
]);

echo $result->id;      // message UUID
echo $result->status;  // queued | sent | failed

// Read-only groups + visualization graph (tenant-scoped)
$groups = $client->listGroups();
$graph = $client->getMappings(); // data.nodes / data.edges with source+target
```

See [`docs/developers`](../../docs/developers/README.md) for the full guide, including [groups & mappings](../../docs/developers/groups.md).

## Publishing (maintainers)

To release this package on **Packagist** (`composer require proxymailer/sdk`), follow
[`docs/developers/publishing.md`](../../docs/developers/publishing.md#1-php--packagist-composer).

Because ProxyMailer is a monorepo, Packagist usually needs a **subtree-split** repo
(or Private Packagist/Satis) whose root is `sdks/php`.
