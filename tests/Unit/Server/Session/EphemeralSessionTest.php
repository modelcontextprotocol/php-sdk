<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Session;

use Mcp\Server\Exception\SessionRequiredException;
use Mcp\Server\Session\EphemeralSession;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class EphemeralSessionTest extends TestCase
{
    #[TestDox('holds what a request sets for as long as the request runs')]
    public function testHoldsValuesInMemory(): void
    {
        $session = new EphemeralSession();
        $session->set('cart.items', 2);

        $this->assertSame(2, $session->get('cart.items'));
        $this->assertSame(['cart' => ['items' => 2]], $session->all());
    }

    #[TestDox('every instance gets an id of its own')]
    public function testGetsAnIdOfItsOwn(): void
    {
        $this->assertFalse((new EphemeralSession())->getId()->equals((new EphemeralSession())->getId()));
    }

    #[TestDox('saving fails loud instead of dropping what was meant to outlast the request')]
    public function testSaveFailsLoud(): void
    {
        $session = new EphemeralSession();
        $session->set('cart.items', 2);

        $this->expectException(SessionRequiredException::class);
        $this->expectExceptionMessage('Nothing in a session outlives its request without a session store; configure one with Builder::setSession().');

        $session->save();
    }
}
