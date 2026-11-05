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
 * What a state does outside the endpoint and moment it was minted for: past its TTL, on an endpoint configured
 * differently, and on the other protocol era of the same server.
 */
final class RequestStateDeploymentTest extends RequestStateTestCase
{
    #[TestDox('a state is refused past the TTL given to the builder')]
    public function testExpiredStateIsRefused(): void
    {
        $url = $this->startServer(self::NEW_KEY, ttl: 1);
        $call = self::tool('refund', ['order' => 1]);
        $pending = $this->pendingState($url, 'alice', $call);

        sleep(2);

        $this->assertRefused($this->finish($url, 'alice', $call, $pending));
    }

    #[TestDox('with a binder, a state is still refused past the TTL given to the builder')]
    public function testExpiredBoundStateIsRefused(): void
    {
        $url = $this->startServer(self::NEW_KEY, binder: true, ttl: 1);
        $call = self::tool('refund', ['order' => 1]);
        $pending = $this->pendingState($url, 'alice', $call);

        sleep(2);

        $this->assertRefused($this->finish($url, 'alice', $call, $pending));
    }

    #[TestDox('with a key ring and a binder, a state of the older key is still refused past the TTL')]
    public function testExpiredStateOfTheOlderKeyIsRefused(): void
    {
        $call = self::tool('refund', ['order' => 1]);
        $old = $this->startServer(self::OLD_KEY, binder: true, ttl: 2);
        $rotated = $this->startServer([self::NEW_KEY, self::OLD_KEY], binder: true, ttl: 600);
        $pending = $this->pendingState($old, 'alice', $call);

        $this->assertCompleted($this->finish($rotated, 'alice', $call, $pending), 'alice refunded order 1');

        sleep(3);

        $this->assertRefused($this->finish($rotated, 'alice', $call, $pending));
    }

    #[TestDox('adding a binder refuses the states minted before it')]
    public function testAddingABinderRefusesEarlierStates(): void
    {
        $call = self::tool('transfer', ['to' => 'bob', 'amount' => 10]);
        $pending = $this->pendingState($this->startServer(self::NEW_KEY), 'alice', $call);

        $this->assertRefused($this->finish($this->startServer(self::NEW_KEY, binder: true), 'alice', $call, $pending));
    }

    #[TestDox('removing a binder refuses the states minted with it')]
    public function testRemovingABinderRefusesEarlierStates(): void
    {
        $call = self::tool('transfer', ['to' => 'bob', 'amount' => 10]);
        $pending = $this->pendingState($this->startServer(self::NEW_KEY, binder: true), 'alice', $call);

        $this->assertRefused($this->finish($this->startServer(self::NEW_KEY), 'alice', $call, $pending));
    }

    #[TestDox('a state of an endpoint without authorization is refused on one with it, sharing the key and the binder')]
    public function testAnonymousStateIsRefusedUnderAToken(): void
    {
        $call = self::tool('refund', ['order' => 1]);
        $pending = $this->pendingState($this->startServer(self::NEW_KEY, binder: true, anonymous: true), null, $call);

        $this->assertRefused($this->finish($this->startServer(self::NEW_KEY, binder: true), 'alice', $call, $pending));
    }

    #[TestDox('a state minted under a token is refused on an endpoint without authorization, sharing the key and the binder')]
    public function testStateUnderATokenIsRefusedAnonymously(): void
    {
        $call = self::tool('refund', ['order' => 1]);
        $pending = $this->pendingState($this->startServer(self::NEW_KEY, binder: true), 'alice', $call);

        $this->assertRefused($this->finish($this->startServer(self::NEW_KEY, binder: true, anonymous: true), null, $call, $pending));
    }

    #[TestDox('with a key ring, removing the old key while another instance still signs with it fails the calls crossing over')]
    public function testRetiringAKeyTooEarlyFailsAcrossInstances(): void
    {
        $call = self::tool('transfer', ['to' => 'bob', 'amount' => 10]);
        $pending = $this->pendingState($this->startServer([self::OLD_KEY, self::NEW_KEY]), 'alice', $call);

        $this->assertRefused($this->finish($this->startServer([self::NEW_KEY]), 'alice', $call, $pending));
    }

    #[TestDox('a state of the modern era means nothing on a session of the handshake era')]
    public function testModernStateIsIgnoredOnAHandshakeSession(): void
    {
        $url = $this->startServer(self::NEW_KEY, binder: true, shim: false);
        $call = self::tool('refund', ['order' => 1]);
        $pending = $this->pendingState($url, 'alice', $call);

        $answer = $this->sendOnSession($url, 'mallory', $this->handshake($url, 'mallory'), $call, ['requestState' => $pending['state']]);

        $this->assertCompleted($answer, 'Only a manager may refund.');
    }

    #[TestDox('with a binder, a state a handshake session handed out cannot be spent on the modern era')]
    public function testHandshakeStateIsRefusedOnTheModernEra(): void
    {
        $url = $this->startServer(self::NEW_KEY, binder: true, shim: false);
        $call = self::tool('refund', ['order' => 1]);

        $state = $this->sendOnSession($url, 'alice', $this->handshake($url, 'alice'), $call);
        $this->assertIsString($state['body']['result']['requestState'] ?? null, $state['raw']);

        $this->assertRefused($this->finish($url, 'alice', $call, ['state' => $state['body']['result']['requestState'], 'asks' => ['reason']]));
    }
}
