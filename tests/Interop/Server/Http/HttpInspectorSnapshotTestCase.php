<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Interop\Server\Http;

use Mcp\Tests\Interop\Server\InspectorSnapshotTestCase;
use Mcp\Tests\Support\ServerProcess;
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

    /**
     * @return list<string>
     */
    protected function getServerCommand(string $address): array
    {
        return ['php', '-S', $address, $this->getServerScript()];
    }

    protected function getServerConnectionArgs(): array
    {
        return [\sprintf('http://127.0.0.1:%d', $this->serverPort)];
    }

    protected function getTransport(): string
    {
        return 'http';
    }

    /**
     * What the server writes for $message within $seconds, headers and body.
     *
     * @param array<string, mixed>  $message
     * @param array<string, string> $headers
     */
    protected function readFor(float $seconds, array $message, array $headers): string
    {
        $body = json_encode($message, \JSON_THROW_ON_ERROR);
        $socket = fsockopen('127.0.0.1', $this->serverPort, $errorCode, $errorMessage, 1.0);
        $this->assertIsResource($socket, $errorMessage);

        $request = "POST / HTTP/1.1\r\nHost: 127.0.0.1\r\nContent-Type: application/json\r\nAccept: application/json, text/event-stream\r\nContent-Length: ".\strlen($body)."\r\nConnection: close\r\n";
        foreach ($headers as $name => $value) {
            $request .= $name.': '.$value."\r\n";
        }
        fwrite($socket, $request."\r\n".$body);

        $read = '';
        $deadline = microtime(true) + $seconds;
        stream_set_timeout($socket, 0, 100_000);
        while (!feof($socket) && microtime(true) < $deadline) {
            $read .= (string) fread($socket, 8192);
        }
        fclose($socket);

        return $read;
    }

    private function startServer(): void
    {
        $this->serverPort = 8000 + (getmypid() % 1000);

        $this->serverProcess = new Process($this->getServerCommand(\sprintf('127.0.0.1:%d', $this->serverPort)));

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
            ServerProcess::stop($this->serverProcess);
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
