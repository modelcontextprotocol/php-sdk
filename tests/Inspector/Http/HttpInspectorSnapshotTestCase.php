<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Inspector\Http;

use Mcp\Tests\Inspector\InspectorSnapshotTestCase;
use Symfony\Component\Process\Process;

abstract class HttpInspectorSnapshotTestCase extends InspectorSnapshotTestCase
{
    private Process $serverProcess;
    private int $serverPort;

    protected function setUp(): void
    {
        $this->startServer();
    }

    protected function tearDown(): void
    {
        $this->stopServer();
    }

    abstract protected function getServerScript(): string;

    protected function getServerConnectionArgs(): array
    {
        return [\sprintf('http://127.0.0.1:%d', $this->serverPort)];
    }

    protected function getTransport(): string
    {
        return 'http';
    }

    private function startServer(): void
    {
        $this->serverPort = 8000 + (getmypid() % 1000);

        $this->serverProcess = new Process([
            'php',
            '-S',
            \sprintf('127.0.0.1:%d', $this->serverPort),
            $this->getServerScript(),
        ]);

        $this->serverProcess->start();

        $timeout = 5; // seconds
        $startTime = time();

        while (time() - $startTime < $timeout) {
            if ($this->serverProcess->isRunning() && $this->isServerReady()) {
                return;
            }
            usleep(100000); // 100ms
        }

        $this->fail(\sprintf('Server failed to start on port %d within %d seconds', $this->serverPort, $timeout));
    }

    private function stopServer(): void
    {
        if (isset($this->serverProcess)) {
            $this->serverProcess->stop(1, \SIGTERM);
        }
    }

    private function isServerReady(): bool
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 1,
                'method' => 'GET',
            ],
        ]);

        // Try a simple health check - this will likely fail with MCP but should respond
        $response = @file_get_contents(\sprintf('http://127.0.0.1:%d', $this->serverPort), false, $context);

        // We don't care about the response content, just that the server is accepting connections
        return false !== $response || false === str_contains(error_get_last()['message'] ?? '', 'Connection refused');
    }

    protected function getSnapshotFilePath(string $method, ?string $testName = null): string
    {
        $className = substr(static::class, strrpos(static::class, '\\') + 1);
        $suffix = $testName ? '-'.preg_replace('/[^a-zA-Z0-9_]/', '_', $testName) : '';

        return __DIR__.'/snapshots/'.$className.'-'.str_replace('/', '_', $method).$suffix.'.json';
    }
}
