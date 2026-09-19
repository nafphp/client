<?php

declare(strict_types=1);

namespace Naf\Client\Core;

use Naf\Client\Exception\ClientException;
use Naf\Client\Transports\CurlTransport;
use Naf\Client\Transports\StreamingTransportInterface;
use Naf\Client\Transports\StreamTransport;
use Naf\Client\Transports\TransportInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function Naf\config;
use function Naf\response;

class Client implements ClientInterface
{
    /** @var array<int,TransportInterface> */
    private array $transports;

    /** @var array<string,mixed> Per-instance overrides layered over config('client'). */
    private array $options = [];

    public function __construct(?array $transports = null)
    {
        // Prefer cURL, fallback to streams.
        $this->transports = $transports ?? [
            new CurlTransport(),
            new StreamTransport(),
        ];
    }

    /**
     * A copy of this client with options layered over the configuration.
     *
     * The container holds a single shared client, so this hands back a clone: a
     * caller that needs `retries => 0` for something it must not send twice — an
     * OAuth authorization code, a rotating refresh token — never changes what
     * every other caller gets.
     *
     * @param array<string,mixed> $options
     */
    public function withOptions(array $options): self
    {
        $clone          = clone $this;
        $clone->options = [...$this->options, ...$options];

        return $clone;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $configuration = [...(array) config('client', []), ...$this->options];

        $method = strtoupper($request->getMethod());
        $url    = (string) $request->getUri();
        $body   = $request->getBody();

        $retries      = (int) ($configuration['retries'] ?? 1);          // additional attempts
        $retryDelayMs = (int) ($configuration['retry_delay_ms'] ?? 150);

        $headerLines = $this->buildHeaderLines($request);

        // Pre-resolve CA bundle once (transports just consume it).
        $configuration['ca_bundle']    = $this->resolveCaBundlePath($configuration);
        $configuration['http_version'] = (string) ($configuration['http_version'] ?? 'auto'); // auto|1.1|2

        $transport = $this->pickTransport();
        $streaming = $transport instanceof StreamingTransportInterface;

        if (($configuration['streaming'] ?? false) && !$streaming) {
            throw new ClientException('The selected HTTP transport does not support streaming.');
        }

        $offset     = $body->isSeekable() ? $body->tell() : null;
        $legacyBody = $streaming ? null : (string) $body;

        $lastException = null;

        // Attempt 0..retries
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            try {
                if ($attempt > 0 && $streaming) {
                    $body->seek($offset);
                }

                [$responseBody, $rawHeaders] = $streaming
                    ? $transport->sendStream($url, $method, $headerLines, $body, $configuration)
                    : $transport->send($url, $method, $headerLines, $legacyBody, $configuration);
                [$status, $headers] = $this->parseHeaders($rawHeaders);

                return $streaming
                    ? response('', $status, $headers)->withBody($responseBody)
                    : response($responseBody, $status, $headers);
            } catch (Throwable $exception) {
                $lastException = $exception;

                // Retry only for common transient TLS/network issues.
                $message = strtolower($exception->getMessage());
                $transient
                    = str_contains($message, 'failed to enable crypto')
                    || str_contains($message, 'ssl')
                    || str_contains($message, 'tls')
                    || str_contains($message, 'handshake')
                    || str_contains($message, 'timed out')
                    || str_contains($message, 'connection reset');

                $replayable = !$streaming || $offset !== null;

                if ($attempt < $retries && $transient && $replayable) {
                    if ($retryDelayMs > 0) {
                        usleep($retryDelayMs * 1000);
                    }
                    continue;
                }

                throw new ClientException(
                    'HTTP request failed: ' . $exception->getMessage(),
                    (int) $exception->getCode(),
                    $exception,
                );
            }
        }

        throw new ClientException('HTTP request failed', 0, $lastException);
    }

    private function pickTransport(): TransportInterface
    {
        // Default: first available
        foreach ($this->transports as $transport) {
            if ($transport->isAvailable()) {
                return $transport;
            }
        }

        throw new ClientException('No HTTP transport available');
    }

    /**
     * @return array<int,string>
     */
    private function buildHeaderLines(RequestInterface $request): array
    {
        $lines = [];

        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $lines[] = $name . ': ' . $value;
            }
        }

        // Ensure Host header exists (some environments are picky).
        if (!$request->hasHeader('Host')) {
            $host = $request->getUri()->getHost();
            if ($host !== '') {
                $lines[] = 'Host: ' . $host;
            }
        }

        return $lines;
    }

    /**
     * @param array<int,string> $rawHeaders
     * @return array{0:int,1:array<string,array<int,string>>}
     */
    private function parseHeaders(array $rawHeaders): array
    {
        $statusLine = $rawHeaders[0] ?? '';
        if (!preg_match('#HTTP/\d+(?:\.\d+)?\s+(\d+)#i', $statusLine, $matches)) {
            throw new ClientException('Failed to parse HTTP status from response');
        }
        $status = (int) $matches[1];

        $headers = [];
        foreach ($rawHeaders as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $name           = trim($name);
                $value          = trim($value);
                if ($name !== '') {
                    $headers[$name][] = $value;
                }
            }
        }

        return [$status, $headers];
    }

    private function resolveCaBundlePath(array $configuration): ?string
    {
        // 1) Explicitly configured
        $cacert = $configuration['cacert'] ?? null;
        if (is_string($cacert) && $cacert !== '' && is_file($cacert)) {
            return $cacert;
        }

        // 2) Common locations across distros/images
        $candidates = [
            '/etc/ssl/certs/ca-certificates.crt', // Debian/Ubuntu/Alpine often
            '/etc/ssl/cert.pem',                  // Alpine (some builds)
            '/etc/pki/tls/certs/ca-bundle.crt',    // RHEL/CentOS
            '/usr/local/share/certs/ca-root-nss.crt',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
