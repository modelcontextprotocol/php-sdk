<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Integration\ClientExamples\Http;

use Mcp\Tests\Integration\ClientExamples\ClientExampleTestCase;
use Mcp\Tests\Support\ServerProcess;
use Symfony\Component\Process\Process;

/**
 * An example that connects to a server over HTTP.
 *
 * The server is started with `php -S` on a free port before each run, and the
 * example pointed at it through `MCP_SERVER_URL`.
 */
abstract class HttpClientExampleTestCase extends ClientExampleTestCase
{
    private ?Process $server = null;
    private string $endpoint;

    /** Directory name of the server in `examples/server`. */
    abstract protected function getServerExample(): string;

    /**
     * Workers `php -S` forks. A tool that asks the client mid-call holds its
     * SSE response open while the client POSTs the answer on a second
     * connection, which needs more than one.
     */
    protected function getServerWorkers(): int
    {
        return 1;
    }

    protected function setUp(): void
    {
        if ($this->getServerWorkers() > 1 && \PHP_VERSION_ID < 80200) {
            $this->markTestSkipped('php -S does not reliably fork multiple workers on PHP 8.1 (PHP_CLI_SERVER_WORKERS); see php/php-src#9400.');
        }

        $port = ServerProcess::findFreePort();

        $this->server = new Process(
            [\PHP_BINARY, '-S', \sprintf('127.0.0.1:%d', $port), \dirname(__DIR__, 4).'/examples/server/'.$this->getServerExample().'/server.php'],
            env: ['PHP_CLI_SERVER_WORKERS' => (string) $this->getServerWorkers()],
        );
        $this->server->start();

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            if (@fsockopen('127.0.0.1', $port, $errno, $error, 0.1)) {
                $this->endpoint = \sprintf('http://127.0.0.1:%d/', $port);

                return;
            }

            usleep(50_000);
        }

        $this->fail(\sprintf('The example server "%s" did not start: %s', $this->getServerExample(), $this->server->getErrorOutput()));
    }

    protected function tearDown(): void
    {
        ServerProcess::stop($this->server);
        $this->server = null;
    }

    protected function getEnv(): array
    {
        return ['MCP_SERVER_URL' => $this->endpoint];
    }

    protected function normalizeOutput(string $output): string
    {
        return str_replace($this->endpoint, 'http://127.0.0.1:<port>/', $output);
    }

    protected function getSnapshotDirectory(): string
    {
        return __DIR__;
    }
}
