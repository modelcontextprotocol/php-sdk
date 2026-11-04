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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Changing the signing key while calls are in flight, with each round served by another instance of the deployment.
 */
final class RequestStateRotationTest extends RequestStateTestCase
{
    #[TestDox('with a single key, replacing it fails the call in flight')]
    public function testReplacingASingleKeyFailsTheCallInFlight(): void
    {
        $call = self::tool('transfer', ['to' => 'bob', 'amount' => 10]);
        $pending = $this->pendingState($this->startServer(self::OLD_KEY), 'alice', $call);

        $this->assertRefused($this->finish($this->startServer(self::NEW_KEY), 'alice', $call, $pending));
    }

    #[TestDox('with a single key, a rolling deployment fails the calls crossing its instances')]
    public function testRollingASingleKeyFailsAcrossInstances(): void
    {
        $this->assertCallsFailAcross($this->startServer(self::OLD_KEY), $this->startServer(self::NEW_KEY));
    }

    /**
     * @return iterable<string, array{string|list<string>, string|list<string>}>
     */
    public static function provideNeighbouringSteps(): iterable
    {
        yield 'adding the new key behind the current one' => [self::OLD_KEY, [self::OLD_KEY, self::NEW_KEY]];
        yield 'moving the new key to the front' => [[self::OLD_KEY, self::NEW_KEY], [self::NEW_KEY, self::OLD_KEY]];
        yield 'removing the old key' => [[self::NEW_KEY, self::OLD_KEY], [self::NEW_KEY]];
    }

    /**
     * @param string|list<string> $before
     * @param string|list<string> $after
     */
    #[DataProvider('provideNeighbouringSteps')]
    #[TestDox('with a key ring, the calls crossing instances complete while $_dataName')]
    public function testRollingAKeyRingKeepsCallsWorking(string|array $before, string|array $after): void
    {
        $client = $this->client([$this->startServer($before), $this->startServer($after)], 'alice');

        // Two calls of an odd number of requests: the state crosses from one instance to the other in both directions.
        for ($i = 0; $i < 2; ++$i) {
            $this->assertSame('alice moved 10 to bob, approved by client', self::text($client->callTool('transfer', ['to' => 'bob', 'amount' => 10])));
        }

        $client->disconnect();
    }

    #[TestDox('with a key ring, a call in flight survives the rotation')]
    public function testCallInFlightSurvivesTheRotation(): void
    {
        $call = self::tool('transfer', ['to' => 'bob', 'amount' => 10]);
        $pending = $this->pendingState($this->startServer(self::OLD_KEY), 'alice', $call);

        $this->assertCompleted(
            $this->finish($this->startServer([self::NEW_KEY, self::OLD_KEY]), 'alice', $call, $pending),
            'alice moved 10 to bob, approved by alice',
        );
    }

    #[TestDox('with a key ring, skipping the first step fails the calls crossing to an instance without the new key')]
    public function testSkippingTheFirstStepFailsAcrossInstances(): void
    {
        $this->assertCallsFailAcross($this->startServer(self::OLD_KEY), $this->startServer([self::NEW_KEY, self::OLD_KEY]));
    }

    #[TestDox('with a key ring, a state of a retired key is refused')]
    public function testRetiredKeyIsRefused(): void
    {
        $call = self::tool('transfer', ['to' => 'bob', 'amount' => 10]);
        $pending = $this->pendingState($this->startServer(self::OLD_KEY), 'alice', $call);

        $this->assertRefused($this->finish($this->startServer([self::NEW_KEY]), 'alice', $call, $pending));
    }

    private function assertCallsFailAcross(string $first, string $second): void
    {
        $client = $this->client([$first, $second], 'alice');
        $failure = null;

        // One of the two directions works, so only both calls together prove the failure.
        for ($i = 0; $i < 2; ++$i) {
            try {
                $client->callTool('transfer', ['to' => 'bob', 'amount' => 10]);
            } catch (\Throwable $e) {
                $failure = $e;
            }
        }

        $client->disconnect();

        $this->assertInstanceOf(\Throwable::class, $failure);
        $this->assertStringContainsString('failed verification', $failure->getMessage());
    }
}
