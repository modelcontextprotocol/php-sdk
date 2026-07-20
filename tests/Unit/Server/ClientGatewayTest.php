<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server;

use Mcp\Exception\ClientException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListRootsRequest;
use Mcp\Schema\Result\ListRootsResult;
use Mcp\Server\ClientGateway;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ClientGatewayTest extends TestCase
{
    public function testSupportsRootsReturnsTrueWhenAdvertised(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->with('client_capabilities', [])->willReturn(['roots' => []]);

        $gateway = new ClientGateway($session);

        $this->assertTrue($gateway->supportsRoots());
    }

    public function testSupportsRootsReturnsFalseWhenNotAdvertised(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->with('client_capabilities', [])->willReturn(['sampling' => []]);

        $gateway = new ClientGateway($session);

        $this->assertFalse($gateway->supportsRoots());
    }

    public function testListRootsReturnsRootsFromClient(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());

        $gateway = new ClientGateway($session);

        $response = $this->response([
            'roots' => [
                ['uri' => 'file:///home/user/project', 'name' => 'project'],
            ],
        ]);

        $result = $this->runInFiber(static fn (): ListRootsResult => $gateway->listRoots(), $response);

        $this->assertInstanceOf(ListRootsResult::class, $result);
        $this->assertCount(1, $result->roots);
        $this->assertSame('file:///home/user/project', $result->roots[0]->uri);
    }

    public function testListRootsThrowsClientExceptionOnError(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());

        $gateway = new ClientGateway($session);

        $error = Error::forInternalError('nope', '1');

        $this->expectException(ClientException::class);

        $this->runInFiber(static fn (): ListRootsResult => $gateway->listRoots(), $error);
    }

    /**
     * Runs the gateway call inside a Fiber, asserts it suspends with a roots/list
     * request, then resumes it with the given client response.
     *
     * @param Response<array<string, mixed>>|Error $response
     */
    private function runInFiber(\Closure $call, Response|Error $response): mixed
    {
        $fiber = new \Fiber($call);
        $suspend = $fiber->start();

        $this->assertIsArray($suspend);
        $this->assertSame('request', $suspend['type']);
        $this->assertInstanceOf(ListRootsRequest::class, $suspend['request']);

        $fiber->resume($response);

        return $fiber->getReturn();
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return Response<array<string, mixed>>
     */
    private function response(array $result): Response
    {
        return new Response('1', $result);
    }
}
