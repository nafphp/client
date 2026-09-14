<?php

declare(strict_types=1);

namespace Naf\Client\Transports;

use Naf\Client\Exception\ClientException;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\StreamInterface;
use Throwable;

final class CurlTransport implements StreamingTransportInterface
{
    public function isAvailable(): bool
    {
        return function_exists('curl_init');
    }

    public function send(string $url, string $method, array $headerLines, string $body, array $config): array
    {
        [$stream, $headers] = $this->sendStream($url, $method, $headerLines, Stream::create($body), $config);

        try {
            return [$stream->getContents(), $headers];
        } finally {
            $stream->close();
        }
    }

    public function sendStream(string $url, string $method, array $headerLines, StreamInterface $body, array $config): array
    {
        $handle = curl_init($url);
        $file   = @tmpfile();

        if ($handle === false || $file === false) {
            if (is_resource($file)) {
                fclose($file);
            }

            throw new ClientException('Unable to initialize the HTTP transfer.');
        }

        $response = Stream::create($file);
        $headers  = [];
        $failure  = null;

        $timeout        = (float) ($config['timeout'] ?? 20);
        $connectTimeout = (float) ($config['connect_timeout'] ?? 8);
        $maxRedirects   = (int) ($config['max_redirects'] ?? 5);
        $verifySsl      = (bool) ($config['ssl_verify'] ?? true);
        $caBundle       = $config['ca_bundle'] ?? null;
        $version        = strtolower((string) ($config['http_version'] ?? 'auto'));

        // In cURL, "Name:" removes a header; "Name;" sends an empty value.
        // Empty PSR-7 headers can be part of an HTTP signature and must survive.
        $headerLines = array_map(static function (string $line): string {
            return preg_match('/^([^:]+):\s*$/', $line, $match) ? $match[1] . ';' : $line;
        }, $headerLines);

        $options = [
            CURLOPT_CUSTOMREQUEST         => $method,
            CURLOPT_HTTPHEADER            => $headerLines,
            CURLOPT_TIMEOUT_MS            => (int) ceil($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS     => (int) ceil($connectTimeout * 1000),
            CURLOPT_FOLLOWLOCATION        => $maxRedirects > 0,
            CURLOPT_MAXREDIRS             => max(0, $maxRedirects),
            CURLOPT_USERAGENT             => (string) ($config['user_agent'] ?? 'NAF-Client/1.0'),
            CURLOPT_ENCODING              => '',
            CURLOPT_HTTP_CONTENT_DECODING => (bool) ($config['decode_content'] ?? true),
            CURLOPT_SSL_VERIFYPEER        => $verifySsl,
            CURLOPT_SSL_VERIFYHOST        => $verifySsl ? 2 : 0,
            CURLOPT_HTTP_VERSION          => match ($version) {
                '1.1', 'http/1.1'     => CURL_HTTP_VERSION_1_1,
                '2', '2.0', 'http/2' => CURL_HTTP_VERSION_2_0,
                default              => CURL_HTTP_VERSION_NONE,
            },
            CURLOPT_HEADERFUNCTION        => static function ($handle, string $line) use (&$headers, $file, &$failure): int {
                $value = trim($line);

                // A redirect or informational response starts a new header block.
                if (str_starts_with($value, 'HTTP/')) {
                    $headers = [];

                    if (!ftruncate($file, 0) || !rewind($file)) {
                        $failure = new ClientException('Unable to reset the HTTP response stream.');
                        return 0;
                    }
                }

                if ($value !== '') {
                    $headers[] = $value;
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION         => static function ($handle, string $data) use ($response, &$failure): int {
                try {
                    return $response->write($data);
                } catch (Throwable $exception) {
                    $failure = $exception;
                    return 0;
                }
            },
        ];

        if ($verifySsl && is_string($caBundle) && $caBundle !== '' && is_file($caBundle)) {
            $options[CURLOPT_CAINFO] = $caBundle;
        }

        try {
            $size      = $body->getSize();
            $remaining = $size !== null && $body->isSeekable() ? max(0, $size - $body->tell()) : null;

            if ($method === 'HEAD') {
                $options[CURLOPT_NOBODY] = true;
            } elseif ($remaining !== 0 || !in_array($method, ['GET', 'DELETE', 'OPTIONS'], true)) {
                $options[CURLOPT_UPLOAD] = true;

                if ($remaining !== null) {
                    $options[CURLOPT_INFILESIZE] = $remaining;
                }

                $options[CURLOPT_READFUNCTION] = static function ($handle, $file, int $length) use ($body, &$failure): string|int {
                    try {
                        $data = $body->read($length);

                        if ($data === '' && !$body->eof()) {
                            throw new ClientException('The HTTP request stream stalled before EOF.');
                        }

                        return $data;
                    } catch (Throwable $exception) {
                        $failure = $exception;
                        return CURL_READFUNC_ABORT;
                    }
                };
            }

            curl_setopt_array($handle, $options);

            if (curl_exec($handle) === false || $failure !== null) {
                throw new ClientException('cURL error (' . curl_errno($handle) . '): ' . curl_error($handle), 0, $failure);
            }

            // file:// has no HTTP status line; retain the legacy transport behavior.
            if ($headers === [] || !str_starts_with($headers[0], 'HTTP/')) {
                array_unshift($headers, 'HTTP/1.1 ' . curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
            }

            $response->rewind();

            return [$response, $headers];
        } catch (Throwable $exception) {
            $response->close();

            throw $exception;
        }
        // CurlHandle is released automatically; curl_close() is deprecated in PHP 8.5.
    }
}
