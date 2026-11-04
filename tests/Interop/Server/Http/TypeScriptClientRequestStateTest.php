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

use Mcp\Tests\Integration\RequestStateTestCase;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Process\Process;

/**
 * The TypeScript SDK's client against our server: what it re-sends on a retry is not ours to shape, so binding to the
 * request only works if it binds to the same value, and a key rotation only works if it just echoes the state.
 */
final class TypeScriptClientRequestStateTest extends RequestStateTestCase
{
    private const TRANSFER = ['method' => 'tools/call', 'params' => ['name' => 'transfer', 'arguments' => ['to' => 'bob', 'amount' => 10]]];
    private const COMPLETED = 'alice moved 10 to bob, approved by typescript';

    #[TestDox('completes the calls that take several rounds')]
    public function testCompletes(): void
    {
        $outcome = $this->complete([$this->startServer(self::NEW_KEY)]);

        $this->assertSame([self::COMPLETED, self::COMPLETED], $outcome['results'], (string) $outcome['error']);
        $this->assertGreaterThan(0, $outcome['echoedStates']);
    }

    #[TestDox('with a single key, a rolling deployment fails the calls crossing its instances')]
    public function testRollingASingleKeyFails(): void
    {
        $outcome = $this->complete([$this->startServer(self::OLD_KEY), $this->startServer(self::NEW_KEY)]);

        $this->assertStringContainsString('failed verification', (string) $outcome['error']);
    }

    #[TestDox('with a binder, completes the calls that take several rounds')]
    public function testCompletesThroughTheBinder(): void
    {
        $outcome = $this->complete([$this->startServer(self::NEW_KEY, binder: true)]);

        $this->assertSame([self::COMPLETED, self::COMPLETED], $outcome['results'], (string) $outcome['error']);
        $this->assertGreaterThan(0, $outcome['echoedStates']);
    }

    #[TestDox('with a key ring, completes the calls crossing the instances of a rolling rotation')]
    public function testCompletesAcrossARollingRotation(): void
    {
        $outcome = $this->complete([
            $this->startServer([self::OLD_KEY, self::NEW_KEY]),
            $this->startServer([self::NEW_KEY, self::OLD_KEY]),
        ]);

        $this->assertSame([self::COMPLETED, self::COMPLETED], $outcome['results'], (string) $outcome['error']);
        $this->assertGreaterThan(0, $outcome['echoedStates']);
    }

    #[TestDox('with a binder and a key ring, completes the calls crossing the instances of a rolling rotation')]
    public function testCompletesThroughTheBinderAcrossARollingRotation(): void
    {
        $outcome = $this->complete([
            $this->startServer([self::OLD_KEY, self::NEW_KEY], binder: true),
            $this->startServer([self::NEW_KEY, self::OLD_KEY], binder: true),
        ]);

        $this->assertSame([self::COMPLETED, self::COMPLETED], $outcome['results'], (string) $outcome['error']);
        $this->assertGreaterThan(0, $outcome['echoedStates']);
    }

    #[TestDox('with a binder, completes prompts and resource reads that take several rounds')]
    public function testCompletesPromptsAndResourcesThroughTheBinder(): void
    {
        $outcome = $this->complete([$this->startServer(self::NEW_KEY, binder: true)], [
            ['method' => 'prompts/get', 'params' => ['name' => 'brief', 'arguments' => ['topic' => 'roadmap']]],
            ['method' => 'resources/read', 'params' => ['uri' => 'doc://public']],
        ]);

        $this->assertSame([
            'brief on roadmap, approved by typescript',
            'contents of public, approved by typescript',
        ], $outcome['results'], (string) $outcome['error']);
        $this->assertGreaterThan(0, $outcome['echoedStates']);
    }

    #[TestDox('with a binder, completes a call answering as a stream, with a progress token of its own per round')]
    public function testCompletesAStreamedCallThroughTheBinder(): void
    {
        $outcome = $this->complete([$this->startServer(self::NEW_KEY, binder: true)], [
            ['method' => 'tools/call', 'params' => ['name' => 'report', 'arguments' => ['amount' => 10]]],
        ]);

        $this->assertSame(['reported 10, approved by typescript'], $outcome['results'], (string) $outcome['error']);
        $this->assertGreaterThan(0, $outcome['echoedStates']);
        $this->assertGreaterThan(0, $outcome['progressed']);
    }

    /**
     * @param non-empty-list<string>                                    $endpoints
     * @param list<array{method: string, params: array<string, mixed>}> $calls
     *
     * @return array{results: list<string>, error: ?string, echoedStates: int, progressed: int}
     */
    private function complete(array $endpoints, array $calls = [self::TRANSFER, self::TRANSFER]): array
    {
        $client = new Process(['node', __DIR__.'/typescript-client.mjs', json_encode([
            'endpoints' => $endpoints,
            'token' => 'alice',
            'calls' => $calls,
        ], \JSON_THROW_ON_ERROR)], timeout: 30);
        $client->run();

        /** @var array{results: list<string>, error: ?string, echoedStates: int, progressed: int} $outcome */
        $outcome = json_decode($client->getOutput(), true);
        $this->assertIsArray($outcome, $client->getOutput().$client->getErrorOutput());

        return $outcome;
    }
}
