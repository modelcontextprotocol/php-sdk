<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Integration;

use Http\Discovery\Psr18ClientDiscovery;
use Mcp\Client\Client;
use Mcp\Client\Exception\RequestException;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Tests\Support\ServerProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\Process\Process;

/**
 * Redirects answered by real servers to a real `symfony/http-client`.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class HttpRedirectTest extends TestCase
{
    /** @var list<Process> */
    private array $servers = [];
    private string $log;
    private int $port;
    private int $otherPort;

    protected function setUp(): void
    {
        $this->log = sys_get_temp_dir().'/mcp-integration-redirects-'.getmypid();
        $this->port = ServerProcess::findFreePort();

        do {
            $this->otherPort = ServerProcess::findFreePort();
        } while ($this->otherPort === $this->port);
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            ServerProcess::stop($server);
        }

        @unlink($this->log);
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function provideRedirects(): iterable
    {
        yield 'a 307 to another origin' => [307, true];
        yield 'a 308 to another origin' => [308, true];
        yield 'a 302 to another origin' => [302, true];
        yield 'a 307 on the same origin' => [307, false];
    }

    #[DataProvider('provideRedirects')]
    #[TestDox('$_dataName fails the call of a discovered client without following it')]
    public function testRedirectIsNotFollowed(int $status, bool $otherOrigin): void
    {
        $this->assertInstanceOf(Psr18Client::class, Psr18ClientDiscovery::find());

        $this->start($this->port);
        if ($otherOrigin) {
            $this->start($this->otherPort);
        }

        $target = \sprintf('http://127.0.0.1:%d/moved', $otherOrigin ? $this->otherPort : $this->port);
        $endpoint = \sprintf('http://127.0.0.1:%d/?%s', $this->port, http_build_query(['status' => $status, 'location' => $target]));

        $client = Client::builder()->setClientInfo('integration-client', '1.0.0')->setInitTimeout(2)->setRequestTimeout(2)->setMaxRetries(0)->build();
        $client->connect(new HttpTransport($endpoint, ['X-API-Key' => 'key']));

        $failure = null;
        try {
            $client->callTool('echo');
        } catch (RequestException $e) {
            $failure = $e;
        }

        $this->assertFileDoesNotExist($this->log);
        $this->assertNotNull($failure);
        $this->assertStringContainsString(\sprintf('HTTP %d', $status), $failure->getMessage());
        $this->assertStringContainsString($target, $failure->getMessage());

        $client->disconnect();
    }

    private function start(int $port): void
    {
        $server = new Process(
            [\PHP_BINARY, '-S', \sprintf('127.0.0.1:%d', $port), __DIR__.'/Fixture/redirect.php'],
            env: ['MCP_REDIRECT_LOG' => $this->log],
        );
        $server->start();
        $this->servers[] = $server;

        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            if (@fsockopen('127.0.0.1', $port, $errno, $error, 0.1)) {
                return;
            }

            usleep(50_000);
        }

        $this->fail(\sprintf('The fixture server did not start: %s', $server->getErrorOutput()));
    }
}
