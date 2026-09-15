<?php

declare(strict_types=1);

namespace Fixtures\Transports;

use Naf\Client\Transports\TransportInterface;
use RuntimeException;
use Throwable;

final class MockTransport implements TransportInterface
{
    /** @var list<array{0:string,1:array<int,string>}> */
    private array $queue = [];

    /** @var list<Throwable> */
    private array $errors = [];

    private int $calls = 0;

    public function __construct(private bool $available = true)
    {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function setAvailable(bool $available): void
    {
        $this->available = $available;
    }

    /**
     * Enqueue a successful response for the next send() call.
     *
     * @param string            $body
     * @param array<int,string> $rawHeaders
     */
    public function pushResponse(string $body, array $rawHeaders): void
    {
        $this->queue[] = [$body, $rawHeaders];
    }

    /**
     * Enqueue an exception for the next send() call.
     */
    public function pushError(Throwable $e): void
    {
        $this->errors[] = $e;
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function send(string $url, string $method, array $headerLines, string $body, array $config): array
    {
        $this->calls++;

        if ($this->errors !== []) {
            throw array_shift($this->errors);
        }

        if ($this->queue === []) {
            throw new RuntimeException('MockTransport has no queued response.');
        }

        return array_shift($this->queue);
    }
}
