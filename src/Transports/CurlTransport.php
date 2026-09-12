<?php

declare(strict_types=1);

namespace NixPHP\Client\Transports;

use NixPHP\Client\Exception\ClientException;

final class CurlTransport implements TransportInterface
{
    public function isAvailable(): bool
    {
        return \function_exists('curl_init');
    }

    public function send(string $url, string $method, array $headerLines, string $body, array $config): array
    {
        $ch = \curl_init($url);
        if ($ch === false) {
            throw new ClientException('Unable to init cURL');
        }

        $timeout        = (float) ($config['timeout'] ?? 20);
        $connectTimeout = (float) ($config['connect_timeout'] ?? 8);
        $maxRedirects   = (int)   ($config['max_redirects'] ?? 5);
        $verifySsl      = (bool)  ($config['ssl_verify'] ?? true);
        $userAgent      = (string)($config['user_agent'] ?? 'NixPHP-Client/1.0');
        $caBundle       = $config['ca_bundle'] ?? null;

        $respHeaders = [];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_TIMEOUT        => (int) ceil($timeout),
            CURLOPT_CONNECTTIMEOUT => (int) ceil($connectTimeout),
            CURLOPT_FOLLOWLOCATION => $maxRedirects > 0,
            CURLOPT_MAXREDIRS      => max(0, $maxRedirects),
            CURLOPT_USERAGENT      => $userAgent,
            CURLOPT_ENCODING       => '',

            // Collect headers line-by-line.
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $trim = trim($line);
                if ($trim !== '') {
                    $respHeaders[] = $trim;
                }
                return strlen($line);
            },
        ];

        // Configure HTTP version: auto|1.1|2
        $hv = (string)($config['http_version'] ?? 'auto');
        $hv = strtolower(trim($hv));

        if ($hv === '1.1' || $hv === 'http/1.1') {
            $opts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
        } elseif ($hv === '2' || $hv === '2.0' || $hv === 'http/2') {
            // cURL will negotiate h2 over TLS (ALPN). If not possible, it may fail or fallback depending on build.
            $opts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2_0;
        } else {
            // auto: let cURL negotiate best protocol
            $opts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_NONE;
        }

        // Only attach a body for methods that typically carry one.
        if ($body !== '' && $method !== 'GET' && $method !== 'HEAD') {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }

        if ($verifySsl) {
            $opts[CURLOPT_SSL_VERIFYPEER] = true;
            $opts[CURLOPT_SSL_VERIFYHOST] = 2;

            // Pin CA bundle if available (stabilizes container setups).
            if (is_string($caBundle) && $caBundle !== '' && is_file($caBundle)) {
                $opts[CURLOPT_CAINFO] = $caBundle;
            }
        } else {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        \curl_setopt_array($ch, $opts);

        $respBody = \curl_exec($ch);
        if ($respBody === false) {
            $err  = \curl_error($ch);
            $code = \curl_errno($ch);

            // No curl_close(): the handle is an object since PHP 8.0 and is
            // released with the last reference to it. PHP 8.5 deprecates the
            // call, and an application that turns deprecations into exceptions —
            // as the framework does — would get that one instead of this error,
            // on every single request.
            throw new ClientException('cURL error (' . $code . '): ' . $err);
        }

        $status = (int) \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $httpVersion = \curl_getinfo($ch, CURLINFO_HTTP_VERSION);
        $versionString = match ($httpVersion) {
            CURL_HTTP_VERSION_1_0 => '1.0',
            CURL_HTTP_VERSION_1_1 => '1.1',
            CURL_HTTP_VERSION_2_0, CURL_HTTP_VERSION_2 => '2.0',
            //CURL_HTTP_VERSION_3 => '3',
            default => '1.1',
        };

        // Provide a raw header array similar to $http_response_header.
        $rawHeaders = array_merge(['HTTP/' . $versionString . ' ' . $status], $respHeaders);

        return [(string) $respBody, $rawHeaders];
    }
}
