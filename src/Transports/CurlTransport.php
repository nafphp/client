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
        $limit  = max(0, (int) ($config['max_redirects'] ?? 5));
        $offset = $body->isSeekable() ? $body->tell() : null;

        for ($redirects = 0; ; $redirects++) {
            [$response, $headers, $target, $status] = $this->transfer($url, $method, $headerLines, $body, $config);

            if ($limit === 0 || $target === '') {
                return [$response, $headers];
            }

            $response->close();

            if ($redirects >= $limit) {
                throw new ClientException('Maximum HTTP redirects exceeded.');
            }
            if (!in_array(strtolower((string) parse_url($target, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                throw new ClientException('HTTP redirects must target HTTP(S) URLs.');
            }

            // PHP 8.3–8.5 cannot rewind a CURLOPT_READFUNCTION callback for cURL.
            // Resolve Location through cURL, then replay explicitly from the caller's offset.
            $dropBody = ($status === 303 && $method !== 'HEAD')
                || (in_array($status, [301, 302], true) && $method === 'POST');
            if ($dropBody) {
                $method = 'GET';
                $body   = Stream::create('');
                $offset = 0;
            } elseif ($method !== 'HEAD') {
                if ($offset === null) {
                    throw new ClientException('Cannot replay a non-seekable HTTP request body after a redirect.');
                }
                $body->seek($offset);
            }

            $crossOrigin = $this->origin($url) !== $this->origin($target);
            $headerLines = array_values(array_filter($headerLines, static function (string $line) use ($crossOrigin, $dropBody): bool {
                $name = strtolower(trim(explode(':', $line, 2)[0]));

                return $name !== 'host'
                    && (!$crossOrigin || !in_array($name, ['authorization', 'cookie'], true))
                    && (!$dropBody || !in_array($name, ['content-length', 'content-type', 'transfer-encoding', 'expect'], true));
            }));
            $url = $target;
        }
    }

    private function origin(string $url): array
    {
        $parts  = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');

        return [$scheme, strtolower($parts['host'] ?? ''), $parts['port'] ?? ($scheme === 'https' ? 443 : 80)];
    }

    private function transfer(string $url, string $method, array $headerLines, StreamInterface $body, array $config): array
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
            CURLOPT_FOLLOWLOCATION        => false,
            CURLOPT_USERAGENT             => (string) ($config['user_agent'] ?? 'NAF-Client/1.0'),
            CURLOPT_ENCODING              => '',
            CURLOPT_HTTP_CONTENT_DECODING => (bool) ($config['decode_content'] ?? true),
            CURLOPT_SSL_VERIFYPEER        => $verifySsl,
            CURLOPT_SSL_VERIFYHOST        => $verifySsl ? 2 : 0,
            CURLOPT_HTTP_VERSION          => match ($version) {
                '1.1', 'http/1.1'    => CURL_HTTP_VERSION_1_1,
                '2', '2.0', 'http/2' => CURL_HTTP_VERSION_2_0,
                default              => CURL_HTTP_VERSION_NONE,
            },
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers, $file, &$failure): int {
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
            CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use ($response, &$failure): int {
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

            return [
                $response,
                $headers,
                (string) curl_getinfo($handle, CURLINFO_REDIRECT_URL),
                (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            ];
        } catch (Throwable $exception) {
            $response->close();

            throw $exception;
        }
        // CurlHandle is released automatically; curl_close() is deprecated in PHP 8.5.
    }
}
