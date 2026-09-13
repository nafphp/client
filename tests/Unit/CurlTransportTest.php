<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Client\Exception\ClientException;
use Naf\Client\Transports\CurlTransport;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The transport must not raise a deprecation of its own.
 *
 * NAF turns a deprecation into an exception, so one raised inside a request
 * does not end up in a log where somebody might get round to it: it replaces the
 * response. A single deprecated call therefore breaks every outbound request on
 * the PHP version that introduces it, and it breaks them in a way that looks
 * like the remote side is at fault.
 *
 * These tests install the same kind of handler and make requests that need no
 * network, one down each path out of curl_exec().
 */
final class CurlTransportTest extends TestCase
{
    protected function tearDown(): void
    {
        restore_error_handler();
    }

    public function testAFailedRequestReportsTheFailureAndNothingElse(): void
    {
        $this->treatDeprecationsAsErrors();

        try {
            (new CurlTransport())->send('http://127.0.0.1:1/', 'GET', [], '', ['connect_timeout' => 1]);
            self::fail('Connecting to a closed port should have failed.');
        } catch (Throwable $e) {
            // The point: a cURL error, not "Function curl_close() is deprecated".
            self::assertInstanceOf(ClientException::class, $e);
            self::assertStringContainsString('cURL error', $e->getMessage());
        }
    }

    public function testASuccessfulRequestRaisesNothing(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'naf-client-') ?: '';
        file_put_contents($file, 'hello');

        $this->treatDeprecationsAsErrors();

        try {
            [$body] = (new CurlTransport())->send('file://' . $file, 'GET', [], '', []);

            self::assertSame('hello', $body);
        } finally {
            @unlink($file);
        }
    }

    /** What the framework does, and what turns a deprecation into a failed request. */
    private function treatDeprecationsAsErrors(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        }, E_DEPRECATED | E_USER_DEPRECATED);
    }
}
