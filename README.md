````md
<div align="center" style="text-align: center;">

![NAF](assets/naf-logo-small-square.png)

[![NAF Client Plugin](https://github.com/nafphp/client/actions/workflows/php.yml/badge.svg)](https://github.com/nafphp/client/actions/workflows/php.yml)

</div>

[← Back to NAF](https://github.com/nafphp/framework)

---

# naf/client

> **Lightweight PSR-18 HTTP client — pragmatic, robust, and framework-friendly.**

This plugin provides a small and dependency-free implementation of  
`Psr\Http\Client\ClientInterface`, designed for **internal APIs, integrations,
and infrastructure code** inside NAF applications.

It focuses on **correctness, stability, and testability** rather than feature bloat.

> 🧩 Official NAF plugin  
> Minimal surface area, explicit behavior, no hidden magic.

---

## Features

* Implements `Psr\Http\Client\ClientInterface`
* Transport-based architecture (cURL + Streams)
* Automatic fallback when a transport is unavailable
* Robust TLS handling (CA bundle detection, retries on transient errors)
* Configurable HTTP protocol version

---

## Transport Architecture

The client delegates all network I/O to **transports**.

### Included transports

| Transport | Purpose |
|---------|--------|
| `CurlTransport` | Preferred, robust, supports HTTP/2 and modern TLS |
| `StreamTransport` | Fallback using native PHP streams |

The client automatically selects the **first available transport** at runtime.
No configuration required.

This design keeps the client:
- simple
- testable
- extensible (custom transports can be injected)

---

## Installation

```bash
composer require naf/client
````

The plugin is autoloaded automatically.

---

## Usage

###  Send a PSR-7 request

```php
use Nyholm\Psr7\Request;

$request  = new Request('GET', 'https://example.com/api');
$response = client()->sendRequest($request);

echo $response->getStatusCode();
echo (string) $response->getBody();
```

The client always returns a standard PSR-7 `ResponseInterface`.
No automatic JSON parsing or response mutation is performed.

---

##  Configuration

All options live under the `client` key.

```php
// app/config.php
return [
    'client' => [

        // TLS verification
        'ssl_verify' => true,

        // Retry behavior for transient network/TLS errors
        'retries' => 1,
        'retry_delay_ms' => 150,

        // Timeouts (seconds)
        'timeout' => 20,
        'connect_timeout' => 8,

        // HTTP protocol version
        // auto | 1.1 | 2
        'http_version' => 'auto',

        // Optional explicit CA bundle path
        // 'cacert' => '/path/to/ca-bundle.crt',
    ],
];
```

### Notes

* `http_version = auto` lets cURL negotiate the best protocol (usually HTTP/2).
* PHP streams only support HTTP/1.0 and HTTP/1.1 — HTTP/2 requires cURL.
* CA bundles are auto-detected on common Linux distributions.

---

## Testing

The transport abstraction allows **pure unit tests** without real HTTP calls.

```php
$transport = new MockTransport();
$transport->pushResponse('ok', ['HTTP/1.1 200 OK']);

$client = new Client([$transport]);
$response = $client->sendRequest($request);
```

This makes the client:

* fast to test
* deterministic
* independent of network state

---

## Design Principles

* The client does **not** parse or decode JSON automatically
* Response bodies are returned exactly as received
* Protocol and transport decisions are explicit
* No global state, no side effects, no surprises

This keeps the client predictable and PSR-18-aligned.

---

## Requirements

* PHP ≥ 8.2
* `naf/framework` >= 0.1.0
* `nyholm/psr7`  >= 1.0 (used for PSR-7 implementation)

---

## 📄 License

MIT License
