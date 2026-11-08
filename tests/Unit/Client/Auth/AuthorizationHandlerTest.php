<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Client\Auth;

use Mcp\Client\Auth\HeadlessAuthorizationHandler;
use Mcp\Client\Auth\LoopbackAuthorizationHandler;
use Mcp\Exception\AuthorizationException;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * The interactive handlers, exercised over a real socket and a real stream rather than a
 * double: what they do is speak to the outside world, so a double would test nothing.
 */
final class AuthorizationHandlerTest extends TestCase
{
    /** @var string[] */
    private array $directories = [];

    #[TestDox('the loopback handler answers the browser and returns what it landed with')]
    public function testLoopbackHandlerCatchesTheCallback(): void
    {
        $port = self::freePort();

        // Stands in for the browser after the authorization server has redirected it.
        // Fire and forget: the listener cannot answer until it has accepted, which
        // happens only once this returns.
        $browser = static function () use ($port): void {
            $socket = stream_socket_client(\sprintf('tcp://127.0.0.1:%d', $port), $code, $message, 2);
            self::assertIsResource($socket, \sprintf('The handler was not listening: %s (%d).', $message, $code));
            fwrite($socket, "GET /callback?code=abc&state=xyz HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        };

        $parameters = (new LoopbackAuthorizationHandler($browser, 5))
            ->authorize('https://auth.example.com/authorize', \sprintf('http://127.0.0.1:%d/callback', $port));

        $this->assertSame(['code' => 'abc', 'state' => 'xyz'], $parameters);
    }

    #[TestDox('the loopback handler ignores the browser also asking for a favicon')]
    public function testLoopbackHandlerIgnoresUnrelatedRequests(): void
    {
        $port = self::freePort();

        $browser = static function () use ($port): void {
            foreach (['/favicon.ico', '/callback?code=abc'] as $target) {
                $socket = stream_socket_client(\sprintf('tcp://127.0.0.1:%d', $port), $code, $message, 2);
                self::assertIsResource($socket);
                fwrite($socket, \sprintf("GET %s HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n", $target));
            }
        };

        $this->assertSame(['code' => 'abc'], (new LoopbackAuthorizationHandler($browser, 5))
            ->authorize('https://auth.example.com/authorize', \sprintf('http://127.0.0.1:%d/callback', $port)));
    }

    #[TestDox('a port that is already taken is reported instead of waited on')]
    public function testLoopbackHandlerReportsABusyPort(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($server);
        $port = (int) explode(':', (string) stream_socket_get_name($server, false))[1];

        try {
            $this->expectException(AuthorizationException::class);
            $this->expectExceptionMessage('Could not listen');

            (new LoopbackAuthorizationHandler(static fn () => null, 1))
                ->authorize('https://auth.example.com/authorize', \sprintf('http://127.0.0.1:%d/callback', $port));
        } finally {
            fclose($server);
        }
    }

    #[TestDox('the loopback handler gives up rather than waiting forever')]
    public function testLoopbackHandlerTimesOut(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('No authorization redirect arrived');

        (new LoopbackAuthorizationHandler(static fn () => null, 1))
            ->authorize('https://auth.example.com/authorize', \sprintf('http://127.0.0.1:%d/callback', self::freePort()));
    }

    // A bare code carries neither state nor iss, so accepting one would silently skip
    // both of the checks that tie the response to this request.
    // The listener speaks no TLS and never accepts, so only a plain socket can connect and write.
    #[TestDox('the headless handler uses TLS for an uppercase HTTPS scheme')]
    public function testHeadlessHandlerUsesTlsForAnUppercaseScheme(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($listener);
        $port = (int) explode(':', (string) stream_socket_get_name($listener, false))[1];

        try {
            (new HeadlessAuthorizationHandler(1))->authorize(\sprintf('HTTPS://127.0.0.1:%d/authorize', $port), 'http://127.0.0.1:8765/callback');
            $this->fail('The authorization should have failed.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('Could not reach', $e->getMessage());
        } finally {
            fclose($listener);
        }
    }

    #[TestDox('the loopback handler shows the URL when no browser can be opened')]
    public function testLoopbackHandlerShowsTheUrlWithoutABrowser(): void
    {
        $url = 'https://auth.example.com/authorize?client_id=abc';

        $this->assertStringContainsString($url, $this->runDefaultHandler($url, $this->emptyDirectory()));
    }

    #[TestDox('the loopback handler passes the URL to the opener unchanged')]
    public function testLoopbackHandlerPassesTheUrlUnchanged(): void
    {
        $directory = $this->emptyDirectory();

        foreach (['xdg-open', 'open'] as $opener) {
            file_put_contents($directory.'/'.$opener, "#!/bin/sh\nprintf '%s' \"\$1\" > \"\${0%/*}/opened\"\n");
            chmod($directory.'/'.$opener, 0755);
        }

        $url = "https://auth.example.com/authorize?redirect_uri=http%3A%2F%2F127.0.0.1%3A8765&scope=a%20b&state=it's";

        $this->runDefaultHandler($url, $directory);

        $this->assertSame($url, file_get_contents($directory.'/opened'));
    }

    #[TestDox('the loopback handler keeps percent-encoding and shell variables out of reach of cmd.exe')]
    public function testLoopbackHandlerOpensTheUrlWithoutAShellOnWindows(): void
    {
        $url = 'https://auth.example.com/authorize?redirect_uri=http%3A%2F%2F127.0.0.1&x=%PATH%';

        $this->assertSame(
            ['rundll32', 'url.dll,FileProtocolHandler', $url],
            LoopbackAuthorizationHandler::browserCommand($url, 'Windows'),
        );
    }

    /**
     * Runs in its own process, as only the environment decides which opener the handler finds.
     */
    private function runDefaultHandler(string $url, string $path): string
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            $this->markTestSkipped('Windows finds its opener in the system directory, whatever the PATH is.');
        }

        $code = \sprintf(
            'require %s; try { (new %s(null, 1))->authorize(%s, %s); } catch (%s) {}',
            var_export(\dirname(__DIR__, 4).'/vendor/autoload.php', true),
            LoopbackAuthorizationHandler::class,
            var_export($url, true),
            var_export(\sprintf('http://127.0.0.1:%d/callback', self::freePort()), true),
            AuthorizationException::class,
        );

        $process = proc_open([\PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => $path]);
        $this->assertIsResource($process);

        $errorOutput = (string) stream_get_contents($pipes[2]);
        proc_close($process);

        return $errorOutput;
    }

    private function emptyDirectory(): string
    {
        $directory = sys_get_temp_dir().'/mcp-loopback-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $this->directories[] = $directory;

        return $directory;
    }

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            array_map('unlink', glob($directory.'/*') ?: []);
            rmdir($directory);
        }
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $port = (int) explode(':', (string) stream_socket_get_name($socket, false))[1];
        fclose($socket);

        return $port;
    }
}
