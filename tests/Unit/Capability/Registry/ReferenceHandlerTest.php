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
use Mcp\Server\Handler\RuntimeHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ReferenceHandlerTest extends TestCase
{
    public function testHandleDispatchesToRuntimeHandlerAndForwardsClientGateway(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());

        $runtimeHandler = new class implements RuntimeHandlerInterface {
            /** @var array<string, mixed>|null */
            public ?array $executedWith = null;
            public ?ClientGateway $receivedGateway = null;

            public function execute(array $arguments, ClientGateway $gateway): mixed
            {
                $this->executedWith = $arguments;
                $this->receivedGateway = $gateway;

                return 'runtime-result';
            }
        };

        $reference = new ElementReference($runtimeHandler, true);
        $referenceHandler = new ReferenceHandler();

        $request = new \stdClass();
        $result = $referenceHandler->handle($reference, [
            '_session' => $session,
            '_request' => $request,
            'kept' => 'value',
            'other' => 'value2',
        ]);

        $this->assertSame('runtime-result', $result);
        $this->assertSame(
            ['kept' => 'value', 'other' => 'value2'],
            $runtimeHandler->executedWith,
        );
        $this->assertInstanceOf(ClientGateway::class, $runtimeHandler->receivedGateway);
    }

    public function testRuntimeHandlerTakesPriorityOverInvokeAndCallableDetection(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());

        $runtimeHandler = new class implements RuntimeHandlerInterface {
            public bool $executed = false;

            public function __invoke(): string
            {
                throw new \LogicException('__invoke must not be called when RuntimeHandlerInterface is implemented');
            }

            public function execute(array $arguments, ClientGateway $gateway): mixed
            {
                $this->executed = true;

                return 'priority-ok';
            }
        };

        $reference = new ElementReference($runtimeHandler);
        $referenceHandler = new ReferenceHandler();

        $this->assertSame('priority-ok', $referenceHandler->handle($reference, ['_session' => $session]));
        $this->assertTrue($runtimeHandler->executed);
    }

    public function testHandleThrowsForStringHandlerThatIsNeitherFunctionNorClass(): void
    {
        $session = $this->createMock(SessionInterface::class);

        $reference = new ElementReference('definitely_not_a_function_or_class_xyz');

        $this->expectException(InvalidArgumentException::class);

        (new ReferenceHandler())->handle($reference, ['_session' => $session]);
    }
}
