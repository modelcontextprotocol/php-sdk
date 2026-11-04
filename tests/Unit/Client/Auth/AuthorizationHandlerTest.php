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

use Mcp\Client\Auth\ConsoleAuthorizationHandler;
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

    #[TestDox('the console handler accepts a pasted redirect URL, parameters and all')]
    public function testConsoleHandlerParsesAPastedUrl(): void
    {
        $this->assertSame(
            ['code' => 'abc', 'state' => 'xyz'],
            $this->console("http://127.0.0.1:8765/callback?code=abc&state=xyz\n"),
        );
    }

    // A bare code carries neither state nor iss, so accepting one would silently skip
    // both of the checks that tie the response to this request.
    #[TestDox('the console handler refuses a bare code, which would skip the response checks')]
    public function testConsoleHandlerRefusesABareCode(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Paste the whole URL');

        $this->console("abc\n");
    }

    #[TestDox('the console handler shows the URL it wants opened')]
    public function testConsoleHandlerPrintsTheUrl(): void
    {
        $output = fopen('php://memory', 'r+');
        $this->assertIsResource($output);

        $this->console("http://127.0.0.1:8765/callback?code=abc\n", $output);
        rewind($output);

        $this->assertStringContainsString('https://auth.example.com/authorize', (string) stream_get_contents($output));
        fclose($output);
    }

    #[TestDox('the console handler gives up when nothing is pasted')]
    public function testConsoleHandlerRequiresAnAnswer(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->console("\n");
    }

    /**
     * @param resource|null $output
     *
     * @return array<string, string>
     */
    private function console(string $answer, mixed $output = null): array
    {
        $input = fopen('php://memory', 'r+');
        $this->assertIsResource($input);
        fwrite($input, $answer);
        rewind($input);

        $sink = $output ?? fopen('php://memory', 'r+');
        $this->assertIsResource($sink);

        try {
            return (new ConsoleAuthorizationHandler($input, $sink))
                ->authorize('https://auth.example.com/authorize', 'http://127.0.0.1:8765/callback');
        } finally {
            fclose($input);

            if (null === $output) {
                fclose($sink);
            }
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
