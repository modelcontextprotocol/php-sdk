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
use Mcp\Client\Auth\HeadlessAuthorizationHandler;
use Mcp\Client\Auth\LoopbackAuthorizationHandler;
use Mcp\Exception\AuthorizationException;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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

    #[TestDox('the loopback handler only takes the callback of its own request')]
    public function testLoopbackHandlerOnlyAcceptsItsOwnCallback(): void
    {
        $port = self::freePort();
        $answers = [];

        $browser = static function () use ($port, &$answers): void {
            foreach (['/other?code=forged&state=xyz', '/callback?error=access_denied', '/callback?code=forged&state=wrong', '/callback?code=abc&state=xyz'] as $target) {
                $socket = stream_socket_client(\sprintf('tcp://127.0.0.1:%d', $port), $code, $message, 2);
                self::assertIsResource($socket);
                fwrite($socket, \sprintf("GET %s HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n", $target));
                $answers[] = $socket;
            }
        };

        $parameters = (new LoopbackAuthorizationHandler($browser, 5))
            ->authorize('https://auth.example.com/authorize?state=xyz', \sprintf('http://127.0.0.1:%d/callback', $port));

        $this->assertSame(['code' => 'abc', 'state' => 'xyz'], $parameters);

        $statuses = array_map(static fn ($socket): string => trim((string) fgets($socket)), $answers);
        $this->assertSame(['HTTP/1.1 404 Not Found', 'HTTP/1.1 404 Not Found', 'HTTP/1.1 404 Not Found', 'HTTP/1.1 200 OK'], $statuses);
    }

    #[TestDox('the loopback handler does not say the sign-in worked before it is checked')]
    public function testLoopbackHandlerDoesNotClaimSuccess(): void
    {
        $port = self::freePort();
        $socket = null;

        $browser = static function () use ($port, &$socket): void {
            $socket = stream_socket_client(\sprintf('tcp://127.0.0.1:%d', $port), $code, $message, 2);
            self::assertIsResource($socket);
            fwrite($socket, "GET /callback?error=access_denied&state=xyz HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        };

        (new LoopbackAuthorizationHandler($browser, 5))
            ->authorize('https://auth.example.com/authorize?state=xyz', \sprintf('http://127.0.0.1:%d/callback', $port));

        $this->assertIsResource($socket);
        $page = (string) stream_get_contents($socket);
        $this->assertStringContainsString('close this tab', $page);
        $this->assertStringNotContainsString('signed in', $page);
    }

    #[TestDox('the loopback handler gives up rather than waiting forever')]
    public function testLoopbackHandlerTimesOut(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('No authorization redirect arrived');

        (new LoopbackAuthorizationHandler(static fn () => null, 1))
            ->authorize('https://auth.example.com/authorize', \sprintf('http://127.0.0.1:%d/callback', self::freePort()));
    }

    #[TestDox('the headless handler sends its request with the given HTTP client')]
    public function testHeadlessHandlerUsesTheGivenClient(): void
    {
        $client = new class implements ClientInterface {
            /** @var string[] */
            public array $requested = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requested[] = $request->getMethod().' '.$request->getUri();

                return new Response(302, ['Location' => 'http://127.0.0.1:8765/callback?code=abc&state=xyz']);
            }
        };

        $parameters = (new HeadlessAuthorizationHandler($client))->authorize('https://auth.example.com/authorize?state=xyz', 'http://127.0.0.1:8765/callback');

        $this->assertSame(['code' => 'abc', 'state' => 'xyz'], $parameters);
        $this->assertSame(['GET https://auth.example.com/authorize?state=xyz'], $client->requested);
    }

    #[TestDox('the headless handler reports an endpoint it cannot reach')]
    public function testHeadlessHandlerReportsAnUnreachableEndpoint(): void
    {
        $client = $this->createStub(ClientInterface::class);
        $client->method('sendRequest')->willThrowException(new class('Connection refused') extends \RuntimeException implements ClientExceptionInterface {});

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Could not reach');

        (new HeadlessAuthorizationHandler($client))->authorize('https://auth.example.com/authorize', 'http://127.0.0.1:8765/callback');
    }

    #[TestWith([200, []])]
    #[TestWith([302, []])]
    #[TestDox('the headless handler needs a redirect')]
    public function testHeadlessHandlerNeedsARedirectWithALocation(int $status, array $headers): void
    {
        $client = $this->createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturn(new Response($status, $headers));

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('did not answer with a redirect');

        (new HeadlessAuthorizationHandler($client))->authorize('https://auth.example.com/authorize', 'http://127.0.0.1:8765/callback');
    }

    #[TestDox('the console handler accepts a pasted redirect URL, parameters and all')]
    public function testConsoleHandlerParsesAPastedUrl(): void
    {
        $this->assertSame(
            ['code' => 'abc', 'state' => 'xyz'],
            $this->console("http://127.0.0.1:8765/callback?code=abc&state=xyz\n"),
        );
    }

    #[TestDox('the console handler refuses a bare code, which would skip the response checks')]
    public function testConsoleHandlerRefusesABareCode(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Paste the whole URL');

        $this->console("abc\n");
    }

    #[TestDox('the console handler refuses the authorization URL pasted back instead of the redirect')]
    public function testConsoleHandlerRefusesAUrlWithoutCodeOrError(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('neither a code nor an error');

        $this->console("https://auth.example.com/authorize?response_type=code&state=xyz\n");
    }

    #[TestDox('the console handler passes an error response on')]
    public function testConsoleHandlerPassesAnErrorOn(): void
    {
        $this->assertSame(
            ['error' => 'access_denied', 'state' => 'xyz'],
            $this->console("http://127.0.0.1:8765/callback?error=access_denied&state=xyz\n"),
        );
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
    #[TestWith(['https://127.0.0.1:8765/callback'])]
    #[TestWith(['https://app.example.com/callback'])]
    #[TestWith(['http://0.0.0.0:8765/callback'])]
    #[TestDox('the loopback handler refuses a redirect URI it cannot serve, before opening the browser')]
    public function testLoopbackHandlerRefusesARedirectUriItCannotServe(string $redirectUri): void
    {
        $opened = false;
        $handler = new LoopbackAuthorizationHandler(static function () use (&$opened): void {
            $opened = true;
        }, 1);

        try {
            $handler->authorize('https://auth.example.com/authorize?state=abc', $redirectUri);
            $this->fail('The redirect URI should have been refused.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('loopback handler', $e->getMessage());
        }

        $this->assertFalse($opened);
    }

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
