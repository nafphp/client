<?php

declare(strict_types=1);

namespace Tests\Unit;

use Fixtures\Transports\MockTransport;
use Naf\Client\Core\Client;
use Naf\Client\Exception\ClientException;
use Naf\Client\Transports\CurlTransport;
use Naf\Client\Transports\StreamTransport;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlPolicyTest extends TestCase
{
    public static function unsupportedUrls(): iterable
    {
        foreach (['file://' . __FILE__, 'gopher://127.0.0.1:1/_hello', 'dict://127.0.0.1:1/', 'ftp://127.0.0.1:1/', 'php://memory', 'data:text/plain,hello', '/relative', '//example.test/path'] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('unsupportedUrls')]
    public function testClientRejectsUnsupportedUrlsBeforeInvokingAnyTransport(string $url): void
    {
        $transport = new MockTransport();
        $transport->pushResponse('unexpected access', ['HTTP/1.1 200 OK']);

        try {
            (new Client([$transport]))->sendRequest(new Request('GET', $url));
            self::fail('Unsupported URL was accepted.');
        } catch (ClientException $exception) {
            self::assertStringContainsString('HTTP(S)', $exception->getMessage());
            self::assertSame(0, $transport->calls());
        }
    }

    #[DataProvider('unsupportedUrls')]
    public function testDirectTransportCallsRejectUnsupportedUrlsBeforeIo(string $url): void
    {
        foreach ([new CurlTransport(), new StreamTransport()] as $transport) {
            try {
                $transport->send($url, 'GET', [], '', []);
                self::fail('Unsupported transport URL was accepted.');
            } catch (ClientException $exception) {
                self::assertStringContainsString('HTTP(S)', $exception->getMessage());
            }
        }
    }
}
