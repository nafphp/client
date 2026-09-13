<?php

declare(strict_types=1);

namespace Naf\Client\Transports;

interface TransportInterface
{
    /**
     * Send an HTTP request and return [body, rawHeaders].
     *
     * @param string               $url
     * @param string               $method
     * @param array<int,string>    $headerLines
     * @param string               $body
     * @param array<string,mixed>  $config
     *
     * @return array{0:string,1:array<int,string>}
     */
    public function send(string $url, string $method, array $headerLines, string $body, array $config): array;

    /**
     * Whether this transport can be used in the current environment.
     */
    public function isAvailable(): bool;
}
