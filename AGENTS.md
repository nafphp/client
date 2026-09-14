# Working on naf/client

NAF is a small PHP framework with optional Composer plugins. Its core owns boot,
configuration, the service container, routing, events and PSR-7 responses. Prefer existing
NAF helpers, services and extension interfaces; keep application business rules in the host.
This package declares `type: naf-plugin` and is discovered after installation in a NAF host.
The plugin repository itself is not the application's web root.

Before changing code, read the [shared contribution workflow](https://github.com/nafphp/docs/blob/main/AGENT_WORKFLOW.md)
and [release procedure](https://github.com/nafphp/docs/blob/main/RELEASING.md).
In the multi-repository workspace, the same documents are in the sibling `docs/` checkout;
use the linked copies when working from a standalone clone. Preserve other contributors' work.
Review and update user documentation with every behavior change. Source fixes use an RC branch;
verified documentation-only changes can be merged and published by the agent.

## What this plugin does

`naf/client` is a PSR-18 HTTP client using cURL or PHP streams. Install with
`composer require naf/client`; its manifest requires `ext-curl`. Import `Naf\Client\client`.
The helper retrieves the registered `Naf\Client\Core\Client`; applications using constructor
injection of PSR-18's `ClientInterface` must bind that interface explicitly.

## Use it

With the NAF host bootstrapped, build a PSR-7 request and retain per-call option overrides:

```php
<?php
use Nyholm\Psr7\Request;
use function Naf\Client\client;

$http = client()->withOptions(['retries' => 0]);
$response = $http->sendRequest(new Request('GET', 'https://example.com/'));
$status = $response->getStatusCode();
```

`withOptions()` returns a clone, preserving the shared client's defaults. A received HTTP
4xx/5xx response is not itself a transport failure. Disable retries for requests that must
not be replayed, such as authorization-code exchange or rotating refresh tokens.

## Change it here

Start at [Client](src/Core/Client.php), [bootstrap](bootstrap.php)
and [TransportInterface](src/Transports/TransportInterface.php). Client defaults and
configuration reads live in `Client`, not a separate shipped config file. Implement or replace a
transport for custom I/O; do not build a second HTTP stack in a consumer. Keep TLS verification,
header/status parsing and retry behavior explicit. The current client chooses the first
available transport; it does not automatically try the next one after a send failure.

## Verify

Run `composer test` and `composer validate --strict`. Use [tests](tests/) and fake transports
for deterministic request/response, retry and option-isolation checks. Test real transport
changes against a local fixture server; do not rely on live third-party APIs. No `analyse`
script is declared.

User docs: [HTTP client](https://nafphp.github.io/docs/http-client/).

The cURL transport additionally implements `StreamingTransportInterface`; legacy custom
transports still use `TransportInterface`. Require the capability with `streaming => true`
for bounded-memory consumers. Responses spool to temporary disk; request bodies are
consumed from their current position and remain open. Preserve retry offset/ownership,
non-seekable no-retry behavior and the real HTTP stream regression tests.
