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

    private function dumpServerOutputForDiagnosis(): void
    {
        if (!isset($this->serverProcess)) {
            return;
        }

        $out = $this->serverProcess->getOutput();
        $err = $this->serverProcess->getErrorOutput();

        // Both throw unless the process has actually terminated, and losing
        // the dump to an exception in tearDown is the one outcome that would
        // make this pointless.
        try {
            $exit = var_export($this->serverProcess->getExitCode(), true);
            $signal = var_export($this->serverProcess->getTermSignal(), true);
        } catch (\Throwable $e) {
            $exit = $signal = 'unavailable ('.$e->getMessage().')';
        }

        // Unconditional: a server killed by a signal (a stack overflow, say)
        // exits without writing anything, and the exit code is then the only
        // thing that says so.
        fwrite(\STDERR, \sprintf(
            "\n[DIAG] server on port %d (pid target %s), running %s, exit code %s, signal %s\n--- stdout ---\n%s\n--- stderr ---\n%s\n[/DIAG]\n",
            $this->serverPort,
            (string) getmypid(),
            var_export($this->serverProcess->isRunning(), true),
            $exit,
            $signal,
            $out,
            $err,
        ));
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
            $this->dumpServerOutputForDiagnosis();
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
