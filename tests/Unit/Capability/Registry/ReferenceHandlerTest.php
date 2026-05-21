<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Registry;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ResourceHandlerInterface;
use Mcp\Server\Handler\ToolHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ReferenceHandlerTest extends TestCase
{
    public function testHandleDispatchesToBoundToolClosureWithRawArgumentBag(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());

        $toolHandler = new class implements ToolHandlerInterface {
            /** @var array<string, mixed>|null */
            public ?array $executedWith = null;
            public ?ClientGateway $receivedGateway = null;

            public function execute(array $arguments, ClientGateway $gateway): mixed
            {
                $this->executedWith = $arguments;
                $this->receivedGateway = $gateway;

                return 'tool-result';
            }
        };

        $closure = \Closure::bind(
            static function (array $arguments) use ($toolHandler): mixed {
                $gateway = new ClientGateway($arguments['_session']);
                unset($arguments['_session'], $arguments['_request']);

                return $toolHandler->execute($arguments, $gateway);
            },
            null,
            ReferenceHandler::class,
        );
        $reference = new ElementReference($closure);

        $result = (new ReferenceHandler())->handle($reference, [
            '_session' => $session,
            '_request' => new \stdClass(),
            'kept' => 'value',
            'other' => 'value2',
        ]);

        $this->assertSame('tool-result', $result);
        $this->assertSame(
            ['kept' => 'value', 'other' => 'value2'],
            $toolHandler->executedWith,
        );
        $this->assertInstanceOf(ClientGateway::class, $toolHandler->receivedGateway);
    }

    public function testHandleDispatchesToBoundResourceClosureWithRawArgumentBag(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());

        $resourceHandler = new class implements ResourceHandlerInterface {
            public ?string $receivedUri = null;
            public ?ClientGateway $receivedGateway = null;

            public function read(string $uri, ClientGateway $gateway): mixed
            {
                $this->receivedUri = $uri;
                $this->receivedGateway = $gateway;

                return ['contents' => 'r-ok'];
            }
        };

        $closure = \Closure::bind(
            static fn (array $arguments): mixed => $resourceHandler->read(
                $arguments['uri'],
                new ClientGateway($arguments['_session']),
            ),
            null,
            ReferenceHandler::class,
        );
        $reference = new ElementReference($closure);

        $result = (new ReferenceHandler())->handle($reference, [
            '_session' => $session,
            '_request' => new \stdClass(),
            'uri' => 'config://x',
        ]);

        $this->assertSame(['contents' => 'r-ok'], $result);
        $this->assertSame('config://x', $resourceHandler->receivedUri);
        $this->assertInstanceOf(ClientGateway::class, $resourceHandler->receivedGateway);
    }

    public function testHandleStillReflectsOrdinaryClosuresAndDoesNotInjectArgumentBag(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());

        $captured = null;
        $closure = static function (string $kept) use (&$captured): string {
            $captured = $kept;

            return $kept;
        };
        $reference = new ElementReference($closure);

        $result = (new ReferenceHandler())->handle($reference, [
            '_session' => $session,
            '_request' => new \stdClass(),
            'kept' => 'value',
        ]);

        $this->assertSame('value', $result);
        $this->assertSame('value', $captured);
    }

    public function testHandleThrowsForStringHandlerThatIsNeitherFunctionNorClass(): void
    {
        $session = $this->createMock(SessionInterface::class);

        $reference = new ElementReference('definitely_not_a_function_or_class_xyz');

        $this->expectException(InvalidArgumentException::class);

        (new ReferenceHandler())->handle($reference, ['_session' => $session]);
    }
}
