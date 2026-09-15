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

## Documentation

**[HTTP client →](https://nafphp.github.io/docs/http-client/)**

Everything about this package — what it does, how it is configured and what it needs — lives
in the [NAF documentation](https://nafphp.github.io/docs/). Not sure which packages you need?
[Start here](https://nafphp.github.io/docs/choosing-packages/).

## Install

```bash
composer require naf/client
```

## Redirects in the 0.2.2 candidate

The 0.2.2 candidate replays seekable uploads from their original stream position on
307/308 redirects. POST redirects with 301/302, and 303 redirects, continue with GET
without a body. Redirects to a different origin drop Authorization and Cookie headers.
Non-seekable uploads cannot be replayed; disable following with `max_redirects => 0`
when the caller needs to handle the redirect response itself.

## License

MIT. Part of [NAF](https://github.com/nafphp/framework).

## Streaming changes in 0.2.2 (unreleased)

The default cURL transport transfers PSR-7 request bodies in chunks from their current
position and spools responses to automatically deleted temporary files. Caller-owned
request streams stay open. Close the returned PSR-7 body when finished. The temporary
filesystem must have enough space for the response; network I/O completes before
`sendRequest()` returns. Casting the response body to a string still loads it into memory.

Existing `TransportInterface` implementations and string-based `send()` calls continue
to work. Custom transports can additionally implement `StreamingTransportInterface`.
Set `withOptions(['streaming' => true])` to require that capability rather than allowing
the buffering fallback. The PHP stream-wrapper fallback remains string-based.

Retries seek to the request's original position. A non-seekable streaming request is
never automatically retried. `decode_content => false` preserves encoded response bytes
for object/file storage. Redirects retain only the final response headers and body;
`max_redirects => 0` disables following redirects. No new runtime dependency is added.

## PHP code style

Source, tests and PHP templates follow the shared [NAF code style](https://github.com/nafphp/docs/blob/main/CODE_STYLE.md)
(PER Coding Style 3.0 with the Nafinity readability rules). After `composer install`, run
`composer style:check` to verify formatting or `composer style:fix` to apply it. The formatter
is a development dependency. Review template output and run the package checks after changes.
