<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Client\Core\Client;
use Naf\Client\Exception\ClientException;
use Naf\Client\Transports\CurlTransport;
use Naf\Client\Transports\StreamingTransportInterface;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

final class StreamingTest extends TestCase
{
    private static mixed $server;
    private static string $url;
    private static string $log;

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $host   = stream_socket_get_name($socket, false);

        fclose($socket);

        self::$url    = 'http://' . $host;
        self::$log    = tempnam(sys_get_temp_dir(), 'naf-http-');
        self::$server = proc_open([PHP_BINARY, '-S', $host, __DIR__ . '/../Fixtures/Http/router.php'], [0 => ['pipe', 'r'], 1 => ['file', self::$log, 'a'], 2 => ['file', self::$log, 'a']], $pipes);

        fclose($pipes[0]);

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $probe = @stream_socket_client('tcp://' . $host, $error, $message, 0.1);

            if (is_resource($probe)) {
                fclose($probe);
                return;
            }

            usleep(10000);
        }

        throw new \RuntimeException('Fixture HTTP server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$server);
        proc_close(self::$server);
        unlink(self::$log);
    }

    public function testLargeTransfersUseBoundedMemoryAndPreserveInputOwnership(): void
    {
        $file = tmpfile();

        fwrite($file, 'skip');

        for ($i = 0; $i < 512; $i++) {
            fwrite($file, str_repeat('x', 65536));
        }

        fseek($file, 4);

        $body   = Stream::create($file);
        $client = (new Client())->withOptions(['streaming' => true, 'retries' => 0]);

        memory_reset_peak_usage();
        $before = memory_get_usage(true);

        try {
            $response = $client->sendRequest(new Request('PUT', self::$url . '/upload', [], $body));
            $result   = json_decode((string) $response->getBody(), true);

            self::assertSame(32 * 1024 * 1024, $result['length']);
            self::assertSame('PUT', $result['method']);
            self::assertTrue(is_resource($file));
            self::assertSame(32 * 1024 * 1024 + 4, ftell($file));

            $response = $client->sendRequest(new Request('GET', self::$url . '/redirect'));

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('', $response->getHeaderLine('X-Old'));
            self::assertSame('yes', $response->getHeaderLine('X-Final'));
            self::assertSame(32 * 1024 * 1024, $response->getBody()->getSize());
            self::assertSame(0, $response->getBody()->tell());
            self::assertSame(str_repeat('x', 16), $response->getBody()->read(16));
            self::assertLessThan(12 * 1024 * 1024, memory_get_peak_usage(true) - $before);

            $response->getBody()->close();

            $head = $client->sendRequest(new Request('HEAD', self::$url . '/large'));

            self::assertSame(0, $head->getBody()->getSize());
            self::assertSame((string) (32 * 1024 * 1024), $head->getHeaderLine('Content-Length'));
        } finally {
            $body->close();
        }
    }

    public function testRetryReplaysFromTheOriginalOffset(): void
    {
        $transport = $this->retryingTransport();
        $client    = (new Client([$transport]))->withOptions(['retries' => 1, 'retry_delay_ms' => 0]);
        $body      = Stream::create('skip payload');

        $body->seek(5);

        $response = $client->sendRequest(new Request('PUT', 'https://example.test', [], $body));

        self::assertSame('ok', (string) $response->getBody());
        self::assertSame(['payload', 'payload'], $transport->bodies);
    }

    public function testNonSeekableBodiesAreNotRetried(): void
    {
        [$writer, $reader] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        fwrite($writer, 'payload');
        fclose($writer);

        $body      = Stream::create($reader);
        $transport = $this->retryingTransport();
        $client    = (new Client([$transport]))->withOptions(['retries' => 1, 'retry_delay_ms' => 0]);

        try {
            $client->sendRequest(new Request('PUT', 'https://example.test', [], $body));
            self::fail('Expected a transport exception.');
        } catch (ClientException $exception) {
            self::assertSame(['payload'], $transport->bodies);
        } finally {
            $body->close();
        }
    }

    public function testEmptySignedHeadersAreSent(): void
    {
        $client   = new Client();
        $response = $client->sendRequest(new Request('PUT', self::$url . '/headers', ['X-Signed-Empty' => '']));
        $headers  = array_change_key_case(json_decode((string) $response->getBody(), true));

        self::assertArrayHasKey('x-signed-empty', $headers);
        self::assertSame('', $headers['x-signed-empty']);
    }

    public function testRawContentEncodingAndNonStreamingTransportRequirement(): void
    {
        $client   = (new Client())->withOptions(['decode_content' => false]);
        $response = $client->sendRequest(new Request('GET', self::$url . '/compressed'));

        self::assertSame('compressed payload', gzdecode((string) $response->getBody()));

        $transport = new \Fixtures\Transports\MockTransport();
        $client    = (new Client([$transport]))->withOptions(['streaming' => true]);

        $this->expectException(ClientException::class);
        $client->sendRequest(new Request('GET', 'https://example.test'));
    }

    private function retryingTransport(): StreamingTransportInterface
    {
        return new class implements StreamingTransportInterface {
            public array $bodies = [];

            public function isAvailable(): bool { return true; }
            public function send(string $url, string $method, array $headerLines, string $body, array $config): array
            {
                throw new \LogicException('The streaming path must be used.');
            }
            public function sendStream(string $url, string $method, array $headerLines, StreamInterface $body, array $config): array
            {
                $this->bodies[] = $body->getContents();

                if (count($this->bodies) === 1) {
                    throw new ClientException('Connection reset');
                }

                return [Stream::create('ok'), ['HTTP/2 200']];
            }
        };
    }
}
