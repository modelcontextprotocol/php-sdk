<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Fiber;

use Mcp\Exception\LogicException;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Mcp\Schema\Request\PingRequest;
use Mcp\Server\Fiber\HandlerFibers;
use Mcp\Server\Suspension\NotificationSuspension;
use Mcp\Server\Suspension\RequestSuspension;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class HandlerFibersTest extends TestCase
{
    #[TestDox('a handler waiting on the client is driven as before: the server gets the suspension, the handler the answer')]
    public function testSuspensionsForTheServerAreDrivenAsBefore(): void
    {
        $asked = new RequestSuspension(new PingRequest(), 'session');
        $fiber = HandlerFibers::create(static fn (): string => 'answered with '.HandlerFibers::suspend($asked));

        $this->assertSame($asked, HandlerFibers::start($fiber));
        $this->assertNull(HandlerFibers::resume($fiber, 'pong'));
        $this->assertSame('answered with pong', $fiber->getReturn());
    }

    #[TestDox('a handler suspending straight with a server payload, without the gateway, still suspends for the server')]
    public function testAPayloadSuspendedWithoutTheGatewayIsForTheServer(): void
    {
        $notification = new NotificationSuspension(new ToolListChangedNotification(), 'session');
        $fiber = HandlerFibers::create(static fn (): mixed => \Fiber::suspend($notification));

        $this->assertSame($notification, HandlerFibers::start($fiber));
    }

    #[TestDox('without a scheduler, a handler fiber suspended for something else fails, instead of being resumed with nothing')]
    public function testAFiberSuspendedForTheRuntimeFailsWithoutAScheduler(): void
    {
        $resumed = false;
        $fiber = HandlerFibers::create(static function () use (&$resumed): void {
            \Fiber::suspend();
            $resumed = true;
        });

        try {
            HandlerFibers::start($fiber);
            $this->fail('The server resumed a fiber it did not suspend.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Builder::setFiberScheduler()', $e->getMessage());
        }

        $this->assertFalse($resumed);
        $this->assertTrue($fiber->isSuspended());
    }

    #[TestDox('the server waits on the runtime while the handler awaits I/O, and gets what it returns')]
    public function testTheServerWaitsOnTheRuntimeUntilTheHandlerReturns(): void
    {
        $loop = new FakeEventLoop();
        $fiber = HandlerFibers::create(static fn (): int => $loop->await(40) + $loop->await(2), $loop);

        $this->assertNull(HandlerFibers::start($fiber));
        $this->assertSame(42, $fiber->getReturn());
        $this->assertSame(1, $loop->waits);
    }

    #[TestDox('a handler the runtime resumed reaches the server when it waits on the client, and gets the answer')]
    public function testASuspensionAfterTheRuntimeResumedTheHandlerReachesTheServer(): void
    {
        $loop = new FakeEventLoop();
        $asked = new RequestSuspension(new PingRequest(), 'session');
        $fiber = HandlerFibers::create(static function () use ($loop, $asked): string {
            $before = $loop->await('read');
            $answer = HandlerFibers::suspend($asked);

            return \sprintf('%s, %s, %s', $before, $answer, $loop->await('written'));
        }, $loop);

        $this->assertSame($asked, HandlerFibers::start($fiber));
        $this->assertNull(HandlerFibers::resume($fiber, 'pong'));
        $this->assertSame('read, pong, written', $fiber->getReturn());
        $this->assertSame(2, $loop->waits);
    }

    #[TestDox('what a handler throws while the runtime drives it reaches the server, not the runtime')]
    public function testWhatTheHandlerThrowsWhileTheRuntimeDrivesItReachesTheServer(): void
    {
        $loop = new FakeEventLoop();
        $fiber = HandlerFibers::create(static function () use ($loop): never {
            $loop->await(null);

            throw new \DomainException(LoggingLevel::Error->value);
        }, $loop);

        try {
            HandlerFibers::start($fiber);
            $this->fail('The handler\'s exception was lost.');
        } catch (\DomainException $e) {
            $this->assertSame('error', $e->getMessage());
        }

        $this->assertTrue($fiber->isTerminated());
    }

    #[TestDox('a fiber the server did not create is started and resumed as is')]
    public function testAFiberNotCreatedHereIsDrivenAsIs(): void
    {
        $fiber = new \Fiber(static fn (): mixed => \Fiber::suspend('anything'));

        $this->assertSame('anything', HandlerFibers::start($fiber));
        $this->assertNull(HandlerFibers::resume($fiber));
        $this->assertTrue($fiber->isTerminated());
    }
}
