<?php

declare(strict_types=1);

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Application;

use Mcp\Schema\JsonRpc\MessageInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

abstract class ApplicationTestCase extends TestCase
{
    /**
     * @param list<string>         $messages
     * @param array<string,string> $env
     *
     * @return array<string, array<string, array<string,mixed>>>
     */
    protected function runServer(array $messages, float $timeout = 5.0, array $env = []): array
    {
        if (0 === \count($messages)) {
            return [];
        }

        $process = new Process([
            'php',
            $this->getServerScript(),
        ], \dirname(__DIR__, 2), [] === $env ? null : $env, null, $timeout);

        $process->setInput($this->formatInput($messages));
        $process->mustRun();

        return $this->decodeJsonLines($process->getOutput());
    }

    abstract protected function getServerScript(): string;

    /**
     * @param mixed[] $params
     *
     * @throws \JsonException
     */
    protected function jsonRequest(string $method, ?array $params = null, ?string $id = null): string
    {
        $payload = [
            'jsonrpc' => MessageInterface::JSONRPC_VERSION,
            'id' => $id,
            'method' => $method,
        ];

        if (null !== $params) {
            $payload['params'] = $params;
        }

        return (string) json_encode($payload, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $messages
     */
    private function formatInput(array $messages): string
    {
        return implode("\n", $messages)."\n";
    }

    /**
     * @return array<string, array<string, array<string,mixed>>>
     */
    private function decodeJsonLines(string $output): array
    {
        $output = trim($output);
        $responses = [];

        if ('' === $output) {
            return $responses;
        }

        foreach (preg_split('/\R+/', $output) as $line) {
            if ('' === $line) {
                continue;
            }

            try {
                $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (!\is_array($decoded)) {
                continue;
            }

            $id = $decoded['id'] ?? null;

            if (\is_string($id) || \is_int($id)) {
                $responses[(string) $id] = $decoded;
            }
        }

        return $responses;
    }

    protected function getSnapshotFilePath(string $method): string
    {
        $className = substr(static::class, strrpos(static::class, '\\') + 1);

        return __DIR__.'/snapshots/'.$className.'-'.str_replace('/', '_', $method).'.json';
    }

    /**
     * @return array<string,mixed>
     *
     * @throws \JsonException
     */
    protected function loadSnapshot(string $method): array
    {
        $path = $this->getSnapshotFilePath($method);

        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, 'Failed to read snapshot: '.$path);

        return json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, array<string, mixed>> $response
     *
     * @throws \JsonException
     */
    protected function assertResponseMatchesSnapshot(array $response, string $method): void
    {
        $this->assertArrayHasKey('result', $response);
        $actual = $response['result'];

        $expected = $this->loadSnapshot($method);

        $this->assertEquals($expected, $actual, 'Response payload does not match snapshot '.$this->getSnapshotFilePath($method));
    }

    /**
     * @param array<string, array<string, mixed>> $capabilities
     * @param string[]                            $clientInfo
     *
     * @throws \JsonException
     */
    protected function initializeMessage(
        ?string $id = null,
        string $protocolVersion = MessageInterface::PROTOCOL_VERSION,
        array $capabilities = [],
        array $clientInfo = [
            'name' => 'test-suite',
            'version' => '1.0.0',
        ],
    ): string {
        $id ??= uniqid();

        return $this->jsonRequest('initialize', [
            'protocolVersion' => $protocolVersion,
            'capabilities' => $capabilities,
            'clientInfo' => $clientInfo,
        ], $id);
    }
}
