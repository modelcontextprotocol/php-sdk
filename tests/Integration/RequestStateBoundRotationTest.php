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

use PHPUnit\Framework\Attributes\TestDox;

/**
 * The binder and the key ring together: rotating must not lose the binding, and binding must not break rotating.
 */
final class RequestStateBoundRotationTest extends RequestStateTestCase
{
    #[TestDox('a bound state survives the rotation, for the call it was minted for only')]
    public function testBoundStateSurvivesTheRotation(): void
    {
        $call = self::tool('transfer', ['to' => 'bob', 'amount' => 10]);
        $pending = $this->pendingState($this->startServer(self::OLD_KEY, binder: true), 'alice', $call);
        $rotated = $this->startServer([self::NEW_KEY, self::OLD_KEY], binder: true);

        $this->assertRefused($this->finish($rotated, 'mallory', $call, $pending));
        $this->assertRefused($this->finish($rotated, 'alice', self::tool('transfer', ['to' => 'mallory', 'amount' => 10]), $pending));
        $this->assertCompleted($this->finish($rotated, 'alice', $call, $pending), 'alice moved 10 to bob, approved by alice');
    }

    #[TestDox('bound calls complete across the instances of a rolling rotation')]
    public function testBoundCallsCompleteAcrossARollingRotation(): void
    {
        $client = $this->client([
            $this->startServer([self::OLD_KEY, self::NEW_KEY], binder: true),
            $this->startServer([self::NEW_KEY, self::OLD_KEY], binder: true),
        ], 'alice');

        for ($i = 0; $i < 2; ++$i) {
            $this->assertSame('alice moved 10 to bob, approved by client', self::text($client->callTool('transfer', ['to' => 'bob', 'amount' => 10])));
        }

        $client->disconnect();
    }

    #[TestDox('a state minted under the new key stays bound on an instance that verifies it with the same ring')]
    public function testStateOfTheNewKeyStaysBound(): void
    {
        $url = $this->startServer([self::NEW_KEY, self::OLD_KEY], binder: true);
        $call = self::tool('refund', ['order' => 1]);
        $pending = $this->pendingState($url, 'alice', $call);

        $this->assertRefused($this->finish($this->startServer([self::OLD_KEY, self::NEW_KEY], binder: true), 'mallory', $call, $pending));
    }
}
