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

use Mcp\Capability\Registry\Container;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\RegistryException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\Request\PingRequest;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ResourceHandlerInterface;
use Mcp\Server\Handler\ToolHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
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
            '_request' => new PingRequest(),
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
            '_request' => new PingRequest(),
            'uri' => 'config://x',
        ]);

        $this->assertSame(['contents' => 'r-ok'], $result);
        $this->assertSame('config://x', $resourceHandler->receivedUri);
        $this->assertInstanceOf(ClientGateway::class, $resourceHandler->receivedGateway);
    }

    public function testHandleInjectsClientGatewayIntoReflectedHandler(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());

        $handler = new class {
            public ?ClientGateway $receivedGateway = null;

            public function search(string $query, ClientGateway $gateway): string
            {
                $this->receivedGateway = $gateway;

                return 'found: '.$query;
            }
        };

        $result = (new ReferenceHandler())->handle(new ElementReference([$handler, 'search']), [
            '_session' => $session,
            '_request' => new PingRequest(),
            'query' => 'foo',
        ]);

        $this->assertSame('found: foo', $result);
        $this->assertInstanceOf(ClientGateway::class, $handler->receivedGateway);
    }

    public function testHandlePassesAnExplicitNullToANullableParameterWithADefault(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());

        $handler = new class {
            public function search(?string $category = 'books'): string
            {
                return var_export($category, true);
            }
        };

        $reference = new ElementReference([$handler, 'search']);
        $context = ['_session' => $session, '_request' => new PingRequest()];

        $this->assertSame('NULL', (new ReferenceHandler())->handle($reference, $context + ['category' => null]));
        $this->assertSame("'books'", (new ReferenceHandler())->handle($reference, $context));
    }

    public function testParameterTypeDecidesHowJsonObjectsAreDelivered(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());
        $value = (object) ['a' => new \stdClass(), 'list' => [(object) ['b' => 1]]];
        $context = ['_session' => $session, '_request' => new PingRequest(), 'value' => $value];
        $plain = ['a' => [], 'list' => [['b' => 1]]];
        $handler = new ReferenceHandler();

        $this->assertSame($plain, $handler->handle(new ElementReference(static fn (array $value): array => $value), $context));
        $this->assertSame($plain, $handler->handle(new ElementReference(static fn (array|string $value): array|string => $value), $context));
        $this->assertSame($plain, $handler->handle(new ElementReference(static fn (iterable $value): iterable => $value), $context));
        $this->assertSame($value, $handler->handle(new ElementReference(static fn (\stdClass $value): \stdClass => $value), $context));
        $this->assertSame($value, $handler->handle(new ElementReference(static fn (object $value): object => $value), $context));
        $this->assertSame($value, $handler->handle(new ElementReference(static fn (array|\stdClass $value): array|\stdClass => $value), $context));
        $this->assertSame($value, $handler->handle(new ElementReference(static fn (mixed $value): mixed => $value), $context));
        $this->assertSame($value, $handler->handle(new ElementReference(static fn ($value) => $value), $context));
    }

    public function testIterableParameterKeepsATraversable(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());
        $value = new \ArrayIterator(['a' => 1]);
        $context = ['_session' => $session, '_request' => new PingRequest(), 'value' => $value];

        $this->assertSame($value, (new ReferenceHandler())->handle(new ElementReference(static fn (iterable $value): iterable => $value), $context));
    }

    public function testHandleKeepsTheDefaultOfANonNullableParameterOnAnExplicitNull(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());

        $handler = new class {
            public function search(string $category = 'books'): string
            {
                return var_export($category, true);
            }
        };

        $reference = new ElementReference([$handler, 'search']);
        $context = ['_session' => $session, '_request' => new PingRequest(), 'category' => null];

        $this->assertSame("'books'", (new ReferenceHandler())->handle($reference, $context));
    }

    public function testHandleResolvesAClassStringAndMethodPairToAnInstance(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());

        $result = (new ReferenceHandler())->handle(new ElementReference([ArrayHandlerFixture::class, 'greet']), [
            '_session' => $session,
            '_request' => new PingRequest(),
            'who' => 'bob',
        ]);

        $this->assertSame('hi bob', $result);
    }

    public function testHandleRefusesAnArrayHandlerWhoseMethodIsNotCallable(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());

        $this->expectException(InvalidArgumentException::class);

        (new ReferenceHandler())->handle(new ElementReference([new ArrayHandlerFixture(), 'hidden']), [
            '_session' => $session,
            '_request' => new PingRequest(),
        ]);
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
            '_request' => new PingRequest(),
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

    public function testHandleKeepsArgumentOrderWhenAnInjectableParameterPrecedesAVariadic(): void
    {
        // Injectable and regular parameters are assigned by position while variadic
        // elements are appended, so the final order only holds because a variadic is
        // always the last parameter.
        $closure = static fn (ClientGateway $gateway, string $sep = ',', string ...$parts): string => implode($sep, $parts);
        $reference = new ElementReference($closure);

        $result = (new ReferenceHandler())->handle($reference, [
            '_session' => $this->createMock(SessionInterface::class),
            '_request' => new PingRequest(),
            'parts' => ['a', 'b'],
        ]);

        $this->assertSame('a,b', $result);
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

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function provideIntegerValues(): iterable
    {
        yield 'int' => [5, 5];
        yield 'integral float' => [5.0, 5];
        yield 'negative zero' => [-0.0, 0];
        yield 'smallest float in range' => [(float) \PHP_INT_MIN, \PHP_INT_MIN];
        yield 'largest int' => [\PHP_INT_MAX, \PHP_INT_MAX];
        yield 'smallest int' => [\PHP_INT_MIN, \PHP_INT_MIN];
        yield 'string' => ['5', 5];
        yield 'negative string' => ['-5', -5];
        yield 'zero string' => ['0', 0];
        yield 'negative zero string' => ['-0', 0];
        yield 'leading zeros' => ['007', 7];
        yield 'negative leading zeros' => ['-007', -7];
        yield 'only zeros' => ['000', 0];
        yield 'largest int string' => [(string) \PHP_INT_MAX, \PHP_INT_MAX];
        yield 'smallest int string' => [(string) \PHP_INT_MIN, \PHP_INT_MIN];
    }

    #[DataProvider('provideIntegerValues')]
    public function testHandleCastsIntegerArgument(mixed $value, int $expected): void
    {
        $result = (new ReferenceHandler())->handle(new ElementReference(static fn (int $qty): int => $qty), [
            '_session' => $this->createMock(SessionInterface::class),
            'qty' => $value,
        ]);

        $this->assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideNonIntegerValues(): iterable
    {
        yield 'float above the range' => [9300000000000000000.0];
        yield 'float below the range' => [-9300000000000000000.0];
        yield 'first float above the range' => [-(float) \PHP_INT_MIN];
        yield 'float wrapping to zero' => [18446744073709551616.0];
        yield 'infinity' => [\INF];
        yield 'negative infinity' => [-\INF];
        yield 'not a number' => [\NAN];
        yield 'fractional float' => [1.5];
        yield 'string above the range' => ['9223372036854775808'];
        yield 'string below the range' => ['-9223372036854775809'];
        yield 'string far above the range' => ['99999999999999999999'];
        yield 'string far below the range' => ['-99999999999999999999'];
        yield 'double minus' => ['--5'];
        yield 'only a minus' => ['-'];
        yield 'empty string' => [''];
        yield 'plus sign' => ['+5'];
        yield 'trailing minus' => ['5-'];
        yield 'trailing newline' => ["5\n"];
        yield 'surrounding space' => [' 5 '];
        yield 'exponent' => ['1e3'];
        yield 'decimal string' => ['1.0'];
        yield 'word' => ['abc'];
        yield 'bool' => [true];
        yield 'list' => [[1]];
    }

    #[DataProvider('provideNonIntegerValues')]
    public function testHandleRefusesNonIntegerArgument(mixed $value): void
    {
        $this->expectException(RegistryException::class);
        $this->expectExceptionCode(Error::INVALID_PARAMS);

        (new ReferenceHandler())->handle(new ElementReference(static fn (int $qty): int => $qty), [
            '_session' => $this->createMock(SessionInterface::class),
            'qty' => $value,
        ]);
    }

    public function testHandleRefusesOutOfRangeNullableInteger(): void
    {
        $this->expectException(RegistryException::class);
        $this->expectExceptionCode(Error::INVALID_PARAMS);

        (new ReferenceHandler())->handle(new ElementReference(static fn (?int $qty = null): ?int => $qty), [
            '_session' => $this->createMock(SessionInterface::class),
            'qty' => 9300000000000000000.0,
        ]);
    }

    public function testHandleRefusesOutOfRangeVariadicInteger(): void
    {
        $this->expectException(RegistryException::class);
        $this->expectExceptionCode(Error::INVALID_PARAMS);
        $this->expectExceptionMessage('Parameter `qty[1]`');

        (new ReferenceHandler())->handle(new ElementReference(static fn (int ...$qty): int => \count($qty)), [
            '_session' => $this->createMock(SessionInterface::class),
            'qty' => [1, 9300000000000000000.0],
        ]);
    }

    public function testHandleResolvesStringHandlerFromContainerByServiceId(): void
    {
        $container = new Container();
        $container->set('app.greet_handler', new InvokableHandlerFixture());

        $result = (new ReferenceHandler($container))->handle(new ElementReference('app.greet_handler'), [
            '_session' => $this->createMock(SessionInterface::class),
            'who' => 'container',
        ]);

        $this->assertSame('hi container', $result);
    }

    public function testHandleResolvesClosureServiceFromContainer(): void
    {
        $container = new Container();
        $container->set('app.closure_handler', static fn (string $who): string => 'hi '.$who);

        $result = (new ReferenceHandler($container))->handle(new ElementReference('app.closure_handler'), [
            '_session' => $this->createMock(SessionInterface::class),
            'who' => 'closure',
        ]);

        $this->assertSame('hi closure', $result);
    }

    public function testHandleRefusesContainerServiceThatIsNotCallable(): void
    {
        $container = new Container();
        $container->set('app.not_a_handler', new \stdClass());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler "app.not_a_handler" is not invokable.');

        (new ReferenceHandler($container))->handle(new ElementReference('app.not_a_handler'), [
            '_session' => $this->createMock(SessionInterface::class),
        ]);
    }

    public function testHandleRefusesStringHandlerUnknownToContainer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid handler type');

        (new ReferenceHandler(new Container()))->handle(new ElementReference('app.unknown'), [
            '_session' => $this->createMock(SessionInterface::class),
        ]);
    }

    public function testHandlePrefersContainerServiceOverFunctionOfTheSameName(): void
    {
        $container = new Container();
        $container->set('strtoupper', new InvokableHandlerFixture());

        $result = (new ReferenceHandler($container))->handle(new ElementReference('strtoupper'), [
            '_session' => $this->createMock(SessionInterface::class),
            'string' => 'function',
            'who' => 'service',
        ]);

        $this->assertSame('hi service', $result);
    }

    public function testHandleCallsFunctionUnknownToContainer(): void
    {
        $result = (new ReferenceHandler(new Container()))->handle(new ElementReference('strtoupper'), [
            '_session' => $this->createMock(SessionInterface::class),
            'string' => 'function',
        ]);

        $this->assertSame('FUNCTION', $result);
    }

    public function testHandleResolvesArrayCallableServiceFromContainer(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn([new ArrayHandlerFixture(), 'greet']);

        $result = (new ReferenceHandler($container))->handle(new ElementReference('app.array_handler'), [
            '_session' => $this->createMock(SessionInterface::class),
            'who' => 'array',
        ]);

        $this->assertSame('hi array', $result);
    }

    public function testHandleResolvesStringCallableServiceFromContainer(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn('strtoupper');

        $result = (new ReferenceHandler($container))->handle(new ElementReference('app.string_handler'), [
            '_session' => $this->createMock(SessionInterface::class),
            'string' => 'function',
        ]);

        $this->assertSame('FUNCTION', $result);
    }

    public function testHandleInjectsClientGatewayIntoContainerService(): void
    {
        $container = new Container();
        $container->set('app.gateway_handler', static fn (ClientGateway $gateway): string => $gateway::class);

        $result = (new ReferenceHandler($container))->handle(new ElementReference('app.gateway_handler'), [
            '_session' => $this->createMock(SessionInterface::class),
        ]);

        $this->assertSame(ClientGateway::class, $result);
    }

    public function testHandleNamesContainerServiceWhenRequiredArgumentIsMissing(): void
    {
        $container = new Container();
        $container->set('app.greet_handler', new InvokableHandlerFixture());

        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('Missing required argument `who` for app.greet_handler.');

        (new ReferenceHandler($container))->handle(new ElementReference('app.greet_handler'), [
            '_session' => $this->createMock(SessionInterface::class),
        ]);
    }

    public function testHandleRefusesClassWithoutInvokeMethod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('Handler "%s" is not invokable.', ArrayHandlerFixture::class));

        (new ReferenceHandler(new Container()))->handle(new ElementReference(ArrayHandlerFixture::class), [
            '_session' => $this->createMock(SessionInterface::class),
        ]);
    }
}

class ArrayHandlerFixture
{
    public function greet(string $who): string
    {
        return 'hi '.$who;
    }

    /**
     * Not public, so `[$instance, 'hidden']` is not a callable and the handler
     * has to refuse it rather than invoke it.
     */
    protected function hidden(): string
    {
        return 'unreachable';
    }
}

class InvokableHandlerFixture
{
    public function __invoke(string $who): string
    {
        return 'hi '.$who;
    }
}
