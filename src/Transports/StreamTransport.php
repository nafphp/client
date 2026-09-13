<?php

declare(strict_types=1);

namespace Naf\Client\Transports;

use Naf\Client\Exception\ClientException;

class StreamTransport implements TransportInterface
{
    public function isAvailable(): bool
    {
        return true; // Streams are always available in PHP.
    }

    public function send(string $url, string $method, array $headerLines, string $body, array $config): array
    {
        $timeout   = (float)($config['timeout'] ?? 20);
        $verifySsl = (bool) ($config['ssl_verify'] ?? true);
        $caBundle  = $config['ca_bundle'] ?? null;

        $host = parse_url($url, PHP_URL_HOST) ?: '';

        // Stream wrapper TLS can be picky; set SNI + peer_name explicitly.
        $opts = [
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headerLines),
                'content'       => $body,
                'ignore_errors' => true,
                'timeout'       => $timeout,
            ],
            'ssl' => [
                'SNI_enabled'       => true,
                'peer_name'         => $host,
                'verify_peer'       => $verifySsl,
                'verify_peer_name'  => $verifySsl,
                'allow_self_signed' => !$verifySsl,
            ],
        ];

        if ($verifySsl && is_string($caBundle) && $caBundle !== '' && is_file($caBundle)) {
            $opts['ssl']['cafile'] = $caBundle;
        }

        $ctx = stream_context_create($opts);

        $http_response_header = null; // populated by PHP
        $respBody = @file_get_contents($url, false, $ctx);

        if ($respBody === false) {
            $err = error_get_last();
            throw new ClientException($err['message'] ?? 'HTTP request failed');
        }

        /** @var array<int,string> $headers */
        $headers = $http_response_header ?? [];

        // Streams may return body without headers in edge cases; normalize.
        if (count($headers) < 1) {
            throw new ClientException('HTTP response headers missing');
        }

        return [(string) $respBody, $headers];
    }
}
