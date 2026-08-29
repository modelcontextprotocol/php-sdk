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
use Mcp\Exception\RegistryException;
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

    public function testHandleCastsEachElementOfAnArrayArgumentForAVariadicParameter(): void
    {
        // SchemaGenerator advertises variadic parameters as a JSON "array" schema,
        // so the array arrives here as a single named argument (not spread across
        // multiple keys) and must be cast element-by-element to the variadic's type.
        $closure = static fn (string $name, int ...$scores): string => \sprintf('%s:%d', $name, array_sum($scores));
        $reference = new ElementReference($closure);

        $result = (new ReferenceHandler())->handle($reference, [
            '_session' => $this->createMock(SessionInterface::class),
            'name' => 'total',
            'scores' => ['1', '2', '3'],
        ]);

        $this->assertSame('total:6', $result);
    }

    public function testHandleTreatsOmittedVariadicArgumentAsZeroElements(): void
    {
        $closure = static fn (string $name, int ...$scores): int => \count($scores);
        $reference = new ElementReference($closure);

        $result = (new ReferenceHandler())->handle($reference, [
            '_session' => $this->createMock(SessionInterface::class),
            'name' => 'empty',
        ]);

        $this->assertSame(0, $result);
    }

    public function testHandleWrapsANonArrayVariadicArgumentAsASingleElement(): void
    {
        // Tool arguments follow the advertised "array" schema, but MCP prompt
        // arguments are always Record<string,string> per the protocol - a
        // client sends a plain string for a variadic prompt parameter, and
        // that must still work rather than being rejected.
        $closure = static fn (string $name, string ...$topics): array => $topics;
        $reference = new ElementReference($closure);

        $result = (new ReferenceHandler())->handle($reference, [
            '_session' => $this->createMock(SessionInterface::class),
            'name' => 'single',
            'topics' => 'php',
        ]);

        $this->assertSame(['php'], $result);
    }

    public function testHandleThrowsRegistryExceptionWhenVariadicArgumentIsAnObjectNotAList(): void
    {
        $closure = static fn (string $name, int ...$scores): int => \count($scores);
        $reference = new ElementReference($closure);

        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('Parameter `scores` must be a list of values, not an object.');

        (new ReferenceHandler())->handle($reference, [
            '_session' => $this->createMock(SessionInterface::class),
            'name' => 'bad',
            'scores' => ['a' => 1, 'b' => 2],
        ]);
    }

    public function testHandleIncludesParameterNameAndIndexWhenAVariadicElementFailsToCast(): void
    {
        $closure = static fn (string $name, int ...$scores): int => \count($scores);
        $reference = new ElementReference($closure);

        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('Parameter `scores[1]`: Cannot cast value to integer. Expected integer representation.');

        (new ReferenceHandler())->handle($reference, [
            '_session' => $this->createMock(SessionInterface::class),
            'name' => 'bad',
            'scores' => ['1', 'not-a-number', '3'],
        ]);
    }
}
