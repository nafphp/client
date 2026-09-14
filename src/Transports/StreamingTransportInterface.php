<?php

declare(strict_types=1);

namespace Naf\Client\Transports;

use Psr\Http\Message\StreamInterface;

/** Optional capability; existing string-based transports remain supported. */
interface StreamingTransportInterface extends TransportInterface
{
    /**
     * Consume the body from its current position without closing it.
     * The returned response stream is positioned at its beginning.
     *
     * @param list<string> $headerLines
     * @param array<string, mixed> $config
     * @return array{StreamInterface, list<string>}
     */
    public function sendStream(string $url, string $method, array $headerLines, StreamInterface $body, array $config): array;
}
